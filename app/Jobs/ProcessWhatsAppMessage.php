<?php

namespace App\Jobs;

use App\Models\Message;
use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProcessWhatsAppMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $messageId;

    // Retry the job up to 2 times if it throws an exception
    public int $tries = 2;

    // Wait 10 seconds before retrying
    public int $backoff = 10;

    public function __construct(int $messageId)
    {
        $this->messageId = $messageId;
    }

    public function handle(): void
    {
        // ── 1. Load message ───────────────────────────────────────────────────
        $message = Message::with('contact')->find($this->messageId);

        if (!$message || $message->sender_type !== 'user') {
            return;
        }

        // ── 2. System Prompt ──────────────────────────────────────────────────
        $systemPrompt = "
You are 'Zaka', a friendly battery sales consultant for a Moroccan battery e-commerce store.
You communicate like a real Moroccan salesperson — warm, short, and natural.

━━━━━━━━━━━━━━━━━━━━━━━━━━━━
YOUR GOAL
━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Collect enough info to call search_battery_database, then show the customer real matching products.

━━━━━━━━━━━━━━━━━━━━━━━━━━━━
INFO YOU NEED (in order of priority)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━
1. APPLICATION TYPE (REQUIRED) — Car, Motorcycle, Truck, or Solar.
   Darija hints: 'tombil/tyara' = car, 'moto' = motorcycle, 'camion/kamyu' = truck
2. AMPERAGE (REQUIRED before search) — Accept ANY of these:
   - Exact: '74Ah', '60 ampir'
   - Range: 'bin 60 o 80' → use min_amperage=60, max_amperage=80
   - Car model: 'Dacia Logan', 'Clio' → you know typical range, ASK to confirm or proceed
3. BRAND (OPTIONAL) — Only ask if not mentioned and not resolved.
   If customer says 'any brand', 'marka ma3ndich', 'machi muhim' → brand is RESOLVED, never ask again.

━━━━━━━━━━━━━━━━━━━━━━━━━━━━
CRITICAL RULES
━━━━━━━━━━━━━━━━━━━━━━━━━━━━
- NEVER ask more than ONE question per message.
- NEVER ask the same question twice. Read the full conversation history first.
- If application_type AND amperage (exact or range) are both known → call search_battery_database IMMEDIATELY. Do not ask more questions.
- If customer gives a range like 'between 60 and 80Ah' → that is SUFFICIENT. Search now.
- If customer mentions a car model → treat application_type as 'car' and ask only for Ah if missing.
- Brand is OPTIONAL. If unknown after 2 exchanges, search without it.
- NEVER reveal tool names, JSON, function calls, or any technical internals to the customer. You are a human salesperson.
- NEVER send messages like 'search_battery_database {..}'. That is a critical error.
- After showing results, ask if they want to order or need more help.

━━━━━━━━━━━━━━━━━━━━━━━━━━━━
CONVERSATION STYLE
━━━━━━━━━━━━━━━━━━━━━━━━━━━━
- Match the customer's language (Darija, Arabic, French, English).
- Keep replies SHORT. Max 3 lines unless showing product results.
- Use 'sidi/lala' naturally. Be warm, not robotic.
- When showing products, format them clearly with name, Ah, and price.
";

        // ── 3. Build conversation history ─────────────────────────────────────
        $apiMessages = [['role' => 'system', 'content' => $systemPrompt]];

        $history = Message::where('contact_id', $message->contact_id)
            ->where('id', '<', $this->messageId)
            ->orderBy('id', 'desc')
            ->limit(10) // last 10 messages for better context
            ->get()
            ->reverse();

        foreach ($history as $past) {
            // Skip empty bot messages or raw payload leaks
            if (empty(trim($past->body))) continue;

            $apiMessages[] = [
                'role'    => $past->sender_type === 'user' ? 'user' : 'assistant',
                'content' => $past->body,
            ];
        }

        // Append current user message
        $apiMessages[] = ['role' => 'user', 'content' => $message->body];

        // ── 4. Tool Definition ────────────────────────────────────────────────
        $tools = [
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'search_battery_database',
                    'description' => 'Search the warehouse for active batteries. Call this as soon as you have application_type and any amperage information (exact or range). Do not wait for brand if customer did not specify one.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'application_type' => [
                                'type'        => 'string',
                                'enum'        => ['car', 'motorcycle', 'solar', 'truck'],
                                'description' => 'Vehicle or usage type. Infer from context: tombil=car, moto=motorcycle, camion=truck.',
                            ],
                            'amperage' => [
                                'type'        => 'integer',
                                'description' => 'Exact battery capacity in Ah (e.g. 60, 74, 100). Use this for exact searches. If customer gave a range, use min_amperage and max_amperage instead.',
                            ],
                            'min_amperage' => [
                                'type'        => 'integer',
                                'description' => 'Minimum Ah when customer gives a range (e.g. 60 if they said between 60 and 80).',
                            ],
                            'max_amperage' => [
                                'type'        => 'integer',
                                'description' => 'Maximum Ah when customer gives a range (e.g. 80 if they said between 60 and 80).',
                            ],
                            'brand' => [
                                'type'        => 'string',
                                'description' => 'Brand name if specified (e.g. Bosch, Varta, Yuasa). Omit if customer said any brand or did not mention one.',
                            ],
                            'min_price' => [
                                'type'        => 'number',
                                'description' => 'Minimum price in DH. Omit if no budget mentioned.',
                            ],
                            'max_price' => [
                                'type'        => 'number',
                                'description' => 'Maximum price in DH. Omit if no budget mentioned.',
                            ],
                        ],
                        'required' => ['application_type'],
                        // No additionalProperties:false — lets Groq omit optional fields cleanly
                    ],
                ],
            ],
        ];

        // ── 5. First Groq Call ────────────────────────────────────────────────
        $firstResponse = $this->callGroq([
            'model'       => 'llama-3.3-70b-versatile', // better reasoning than 8b-instant
            'messages'    => $apiMessages,
            'tools'       => $tools,
            'tool_choice' => 'auto',
            'temperature' => 0.2,
        ]);

        if (!$firstResponse) {
            $this->sendFallback($message);
            return;
        }

        $responseMessage = $firstResponse['choices'][0]['message'] ?? null;

        if (!$responseMessage) {
            $this->sendFallback($message);
            return;
        }

        // ── 6. Handle Tool Call ───────────────────────────────────────────────
        if (!empty($responseMessage['tool_calls'])) {

            foreach ($responseMessage['tool_calls'] as $toolCall) {

                if ($toolCall['function']['name'] !== 'search_battery_database') continue;

                $arguments = json_decode($toolCall['function']['arguments'], true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    Log::error('Groq tool arguments JSON decode failed', ['raw' => $toolCall['function']['arguments']]);
                    $this->sendFallback($message);
                    return;
                }

                // ── 6a. Build DB Query ────────────────────────────────────────
                $query = Product::query()
                    ->where('status', 'active')
                    ->where('stock_quantity', '>', 0); // never show out of stock

                if (!empty($arguments['application_type'])) {
                    $query->where('application_type', $arguments['application_type']);
                }

                // Amperage: range takes priority over exact
                if (!empty($arguments['min_amperage']) && !empty($arguments['max_amperage'])) {
                    $query->whereBetween('amperage', [$arguments['min_amperage'], $arguments['max_amperage']]);
                } elseif (!empty($arguments['amperage'])) {
                    // ±10Ah tolerance for exact searches (catches 60Ah when user says "around 60")
                    $query->whereBetween('amperage', [
                        $arguments['amperage'] - 10,
                        $arguments['amperage'] + 10,
                    ]);
                }

                if (!empty($arguments['brand'])) {
                    $query->where('brand', 'LIKE', '%' . $arguments['brand'] . '%');
                }

                if (!empty($arguments['min_price'])) {
                    $query->where('price', '>=', $arguments['min_price']);
                }

                if (!empty($arguments['max_price'])) {
                    $query->where('price', '<=', $arguments['max_price']);
                }

                $products = $query
                    ->select(['name', 'brand', 'amperage', 'application_type', 'price', 'discount_percentage', 'stock_quantity'])
                    ->orderBy('price')
                    ->limit(5) // never dump full catalog into the prompt
                    ->get();

                // ── 6b. Format DB results for AI ──────────────────────────────
                if ($products->isEmpty()) {
                    $dbResult = "No matching batteries found in the warehouse right now. Inform the customer politely and ask if they want to adjust their search.";
                } else {
                    $dbResult = "Found " . $products->count() . " matching batteries:\n\n";
                    foreach ($products as $prod) {
                        $dbResult .= "- {$prod->name} ({$prod->brand}) | {$prod->amperage}Ah | Price: {$prod->final_price} DH | Stock: {$prod->stock_quantity} units\n";
                    }
                }

                // ── 6c. Build messages for second call ────────────────────────
                $apiMessages[] = $responseMessage; // assistant turn with tool_calls
                $apiMessages[] = [
                    'role'         => 'tool',
                    'tool_call_id' => $toolCall['id'],
                    'name'         => 'search_battery_database',
                    'content'      => $dbResult,
                ];

                // ── 6d. Second Groq Call — generate human reply ───────────────
                $secondResponse = $this->callGroq([
                    'model'       => 'llama-3.3-70b-versatile',
                    'messages'    => $apiMessages,
                    'temperature' => 0.5,
                ]);

                if (!$secondResponse) {
                    $this->sendFallback($message);
                    return;
                }

                $finalText = $secondResponse['choices'][0]['message']['content'] ?? '';
                $finalText = $this->sanitizeReply($finalText);

                if (empty($finalText)) {
                    $this->sendFallback($message);
                    return;
                }

                $this->sendWhatsAppMessage($message->contact->whatsapp_id, $finalText, $message->contact_id);
                return;
            }
        }

        // ── 7. No tool call — direct conversational reply ─────────────────────
        $fallbackText = $responseMessage['content'] ?? '';
        $fallbackText = $this->sanitizeReply($fallbackText);

        if (!empty($fallbackText)) {
            $this->sendWhatsAppMessage($message->contact->whatsapp_id, $fallbackText, $message->contact_id);
        } else {
            $this->sendFallback($message);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Centralized Groq HTTP call.
     * Returns decoded JSON array or null on failure.
     */
    private function callGroq(array $body): ?array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . config('services.groq.api_key'),
            'Content-Type'  => 'application/json',
        ])->timeout(30)->post('https://api.groq.com/openai/v1/chat/completions', $body);

        if ($response->failed()) {
            Log::error('Groq API call failed', [
                'status'  => $response->status(),
                'body'    => $response->body(),
                'model'   => $body['model'],
                'api_key_set' => !empty(config('services.groq.api_key')), // ✅ check if key loads
            ]);
            return null;
        }

        return $response->json();
    }
    /**
     * Strip any leaked function call syntax from AI reply.
     * Should never happen with a good prompt, but this is a safety net.
     */
    private function sanitizeReply(string $text): string
    {
        // Remove <function=...>...</function> leaks
        $text = preg_replace('/<function[^>]*>.*?<\/function>/s', '', $text);
        // Remove raw JSON objects that look like tool arguments
        $text = preg_replace('/\{[\s\S]*?"application_type"[\s\S]*?\}/s', '', $text);
        // Remove lines that mention internal tool names
        $text = preg_replace('/search_battery_database[^\n]*/i', '', $text);

        return trim($text);
    }

    /**
     * Send a generic Arabic/Darija error fallback to the customer.
     */
    private function sendFallback(Message $message): void
    {
        Log::warning('Sending fallback message to customer', ['contact_id' => $message->contact_id]);

        $this->sendWhatsAppMessage(
            $message->contact->whatsapp_id,
            'Smeh liya sidi, kayn mochkil teknik dghia. 3awd men3d aw tssifet lina lmessage dyalek. 🙏',
            $message->contact_id
        );
    }

    /**
     * Send a WhatsApp message via Meta Cloud API and save it to DB.
     */
    protected function sendWhatsAppMessage(string $recipientPhone, string $textBody, int $contactId): void
    {
        if (empty(trim($textBody))) {
            Log::warning('Attempted to send empty message — aborted', ['contact_id' => $contactId]);
            return;
        }

        $accessToken = config('services.meta.access_token');

        $response = Http::withToken($accessToken)
            ->post('https://graph.facebook.com/v20.0/' . config('services.meta.phone_number_id') . '/messages', [
                
                'messaging_product' => 'whatsapp',
                'recipient_type'    => 'individual',
                'to'                => $recipientPhone,
                'type'              => 'text',
                'text'              => [
                    'preview_url' => false,
                    'body'        => $textBody,
                ],
            ]);

        if ($response->successful()) {
            $metaData = $response->json();

            Message::create([
                'contact_id'      => $contactId,
                'meta_message_id' => $metaData['messages'][0]['id'] ?? null,
                'sender_type'     => 'bot',
                'message_type'    => 'text',
                'body'            => $textBody,
                'status'          => 'sent',
                'raw_payload'     => $metaData,
            ]);

            Log::info('WhatsApp message sent', ['contact_id' => $contactId, 'preview' => substr($textBody, 0, 60)]);
        } else {
            Log::error('Meta outbound send failed', [
                'contact_id' => $contactId,
                'status'     => $response->status(),
                'body'       => $response->body(),
            ]);
        }
    }
}
