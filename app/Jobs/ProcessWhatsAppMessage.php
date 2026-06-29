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

    public int $tries   = 2;
    public int $backoff = 10;

    public function __construct(int $messageId)
    {
        $this->messageId = $messageId;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // MAIN HANDLER
    // ═══════════════════════════════════════════════════════════════════════════

    public function handle(): void
    {
        $message = Message::with('contact')->find($this->messageId);

        if (!$message || $message->sender_type !== 'user') {
            return;
        }

        // Build conversation context
        $apiMessages = [['role' => 'system', 'content' => $this->buildSystemPrompt()]];

        // Load last 14 messages (7 exchanges) for solid context
        $history = Message::where('contact_id', $message->contact_id)
            ->where('id', '<', $this->messageId)
            ->whereNotNull('body')
            ->where('body', '!=', '')
            ->orderBy('id', 'desc')
            ->limit(14)
            ->get()
            ->reverse();

        foreach ($history as $past) {
            $apiMessages[] = [
                'role'    => $past->sender_type === 'user' ? 'user' : 'assistant',
                'content' => (string) $past->body,
            ];
        }

        $apiMessages[] = ['role' => 'user', 'content' => (string) $message->body];

        // First Groq call: intent detection + tool use
        $firstResponse = $this->callGroq([
            'model'       => 'llama-3.3-70b-versatile',
            'messages'    => $apiMessages,
            'tools'       => $this->buildTools(),
            'tool_choice' => 'auto',
            'temperature' => 0.1,
            'max_tokens'  => 1024,
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

        // Tool call path
        if (!empty($responseMessage['tool_calls'])) {
            $this->handleToolCall($message, $apiMessages, $responseMessage);
            return;
        }

        // Direct conversational reply
        $replyText = $this->sanitizeReply($responseMessage['content'] ?? '');

        if (!empty($replyText)) {
            $this->sendWhatsAppMessage($message->contact->whatsapp_id, $replyText, $message->contact_id);
        } else {
            $this->sendFallback($message);
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TOOL CALL HANDLER
    // ═══════════════════════════════════════════════════════════════════════════

    private function handleToolCall(Message $message, array $apiMessages, array $responseMessage): void
    {
        foreach ($responseMessage['tool_calls'] as $toolCall) {

            if ($toolCall['function']['name'] !== 'search_battery_database') continue;

            $arguments = json_decode($toolCall['function']['arguments'], true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('Groq tool arguments JSON decode failed', [
                    'raw' => $toolCall['function']['arguments'],
                ]);
                $this->sendFallback($message);
                return;
            }

            Log::info('Tool called with arguments', $arguments);

            // Run DB search and format for AI
            $products = $this->searchProducts($arguments);
            $dbResult = $this->formatProductsForAI($products, $arguments);

            // Append tool exchange to message history
            $apiMessages[] = $responseMessage;
            $apiMessages[] = [
                'role'         => 'tool',
                'tool_call_id' => $toolCall['id'],
                'name'         => 'search_battery_database',
                'content'      => $dbResult,
            ];

            // Second Groq call: generate the human-facing reply
            $secondResponse = $this->callGroq([
                'model'       => 'llama-3.3-70b-versatile',
                'messages'    => $apiMessages,
                'temperature' => 0.4,
                'max_tokens'  => 1024,
            ]);

            if (!$secondResponse) {
                $this->sendFallback($message);
                return;
            }

            $finalText = $this->sanitizeReply(
                $secondResponse['choices'][0]['message']['content'] ?? ''
            );

            if (empty($finalText)) {
                $this->sendFallback($message);
                return;
            }

            $this->sendWhatsAppMessage($message->contact->whatsapp_id, $finalText, $message->contact_id);
            return;
        }

        $this->sendFallback($message);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // DB SEARCH
    // ═══════════════════════════════════════════════════════════════════════════

    private function searchProducts(array $args): \Illuminate\Database\Eloquent\Collection
    {
        $query = Product::query()
            ->where('status', 'active')
            ->where('stock_quantity', '>', 0);

        if (!empty($args['application_type'])) {
            $query->where('application_type', $args['application_type']);
        }

        // Range takes priority over exact
        if (!empty($args['min_amperage']) && !empty($args['max_amperage'])) {
            $query->whereBetween('amperage', [(int)$args['min_amperage'], (int)$args['max_amperage']]);
        } elseif (!empty($args['amperage'])) {
            $ah = (int)$args['amperage'];
            $query->whereBetween('amperage', [$ah - 5, $ah + 5]);
        }

        if (!empty($args['brand'])) {
            $query->where('brand', 'LIKE', '%' . $args['brand'] . '%');
        }

        if (!empty($args['min_price'])) {
            $query->where('price', '>=', (float)$args['min_price']);
        }

        if (!empty($args['max_price'])) {
            $query->where('price', '<=', (float)$args['max_price']);
        }

        return $query
            ->select(['id', 'name', 'brand', 'amperage', 'application_type', 'price', 'discount_percentage', 'stock_quantity'])
            ->orderBy('price')
            ->limit(10)
            ->get();
    }

    private function formatProductsForAI(\Illuminate\Database\Eloquent\Collection $products, array $args): string
    {
        if ($products->isEmpty()) {
            $searched = [];
            if (!empty($args['application_type']))               $searched[] = "type: {$args['application_type']}";
            if (!empty($args['amperage']))                       $searched[] = "amperage: {$args['amperage']}Ah";
            if (!empty($args['min_amperage']))                   $searched[] = "amperage range: {$args['min_amperage']}-{$args['max_amperage']}Ah";
            if (!empty($args['brand']))                          $searched[] = "brand: {$args['brand']}";
            if (!empty($args['min_price']))                      $searched[] = "min price: {$args['min_price']} DH";
            if (!empty($args['max_price']))                      $searched[] = "max price: {$args['max_price']} DH";

            return "DATABASE RESULT: No batteries found matching: " . implode(', ', $searched) . ".\n"
                 . "INSTRUCTION: Tell the customer honestly we don't have this right now. "
                 . "Suggest adjusting the amperage range, or ask if they want to see all available batteries for their vehicle type.";
        }

        $result = "DATABASE RESULT: Found {$products->count()} matching batteries in stock:\n\n";

        foreach ($products as $prod) {
            $result .= "• {$prod->name} | Brand: {$prod->brand} | {$prod->amperage}Ah"
            . " | Price: " . number_format($prod->price, 0) . " DH"
            . " | Stock: {$prod->stock_quantity} units\n";
        }

        $result .= "\nINSTRUCTION: Present these products with their listed price. "
                . "Do NOT mention discounts unless the customer asks about price reduction or promotions. "
                . "Ask if they want to order or need more info.";
        return $result;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // SYSTEM PROMPT
    // ═══════════════════════════════════════════════════════════════════════════

    private function buildSystemPrompt(): string
    {
        $stockSummary = $this->buildStockSummary();
    return "
You are 'Zaka', a friendly battery sales consultant at a Moroccan battery store.
You speak Moroccan Darija, Arabic, French, and English — always match the customer's language exactly.

{$stockSummary}

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
🔴 MOST IMPORTANT RULE — READ FIRST
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
NEVER call search_battery_database unless you have collected ALL of these from the customer:
  1. application_type → what vehicle? (car / motorcycle / truck / solar)
  2. amperage (Ah)    → exact number OR range (e.g. 60Ah, or between 60 and 80)

If you do NOT have both of these → DO NOT SEARCH. Ask for the missing one.
It does not matter what the customer says — no application_type + amperage = no search.

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
YOUR CONVERSATION FLOW (ALWAYS FOLLOW THIS ORDER)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
STEP 1 — Greet and ask what vehicle they have
  → Any first message (salam, hello, I want a battery, any message) = greet warmly + ask: 'ina tombil 3ndek?' (what vehicle do you have?)

STEP 2 — Collect amperage
  → Once you know the vehicle type, ask for Ah: 'chhal mn Ah khas lik?' (how many Ah do you need?)
  → Accept: exact number (60), range (bin 60 o 80), or car model (Dacia Logan → infer 60-74Ah)

STEP 3 — Ask brand (OPTIONAL)
  → Only ask brand if customer hasn't mentioned it yet AND you haven't asked before
  → If customer says any brand / marka ma3ndich / machi muhim → skip, never ask again

STEP 4 — SEARCH NOW
  → You have application_type + amperage → call search_battery_database immediately
  → Never ask more questions after this point

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
WHAT TRIGGERS EACH STEP
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
These messages → STEP 1 (greet + ask vehicle), NEVER search:
  'salam', 'hello', 'labas', 'bonjour', 'hi'
  'bghit batirya' (I want a battery)
  'wach 3andkom batteries?' (do you have batteries?)
  'chhal hiya lprix?' (what are the prices?)
  'show me products', 'ma3ndich batirya', 'khsni batirya'
  ANY message that does not mention a vehicle type

These messages → STEP 2 (ask Ah), NEVER search yet:
  'tombil' / 'tyara' / 'car' → know type=car, missing Ah
  'moto' → know type=motorcycle, missing Ah  
  'camion' → know type=truck, missing Ah
  'solaire' → know type=solar, missing Ah

These messages → STEP 4 (SEARCH NOW):
  'tombil' + '60Ah' → search(car, 60)
  'tombil' + 'bin 60 o 80' → search(car, 60-80)
  'Dacia Logan' → search(car, 60-74) ← infer from car model
  'moto' + '12Ah' → search(motorcycle, 12)
  type known from history + Ah just provided → SEARCH IMMEDIATELY

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
MOROCCAN CAR MODELS → INFER AH RANGE
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Dacia Logan / Sandero / Duster      → 60-74Ah → search immediately
Renault Clio / Symbol               → 45-60Ah → search immediately
Hyundai i10 / i20                   → 45-60Ah → search immediately
Volkswagen Golf / Polo              → 60-74Ah → search immediately
Toyota Corolla / Yaris              → 60-74Ah → search immediately
Peugeot 206 / 207 / 208            → 45-60Ah → search immediately
Fiat Punto / Tipo                   → 45-60Ah → search immediately
Mercedes C/E / BMW 3/5             → 74-100Ah → search immediately
Ford Focus / Fiesta                 → 60-74Ah → search immediately
If customer mentions any of these → set min_amperage + max_amperage and call search immediately.

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
STRICT RULES
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
✅ ONE question per message maximum
✅ Read full conversation history before every reply
✅ Always answer customer's question before asking yours
✅ Match customer language exactly (Darija / Arabic / French / English)
✅ Keep replies short — max 2 lines for questions
✅ Show original price only — NEVER mention discounts unless customer asks

❌ NEVER search without application_type AND amperage
❌ NEVER search on a greeting or vague message
❌ NEVER ask the same question twice
❌ NEVER mention tool names, JSON, function calls to customer
❌ NEVER invent products, prices, or specs — only use database results
❌ NEVER ask more than one question at a time

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
WHEN CUSTOMER SAYS 'I DON'T KNOW' THE AH
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
If customer says 'm3arafch', 'I don't know', 'je sais pas' about Ah → NEVER search immediately.
Follow this order:
  1. Ask which exact model: 'Logan, Sandero, wla Duster sidi?'
  2. Ask year of car: 'chhal hiya snet tombiltek?'
  3. Ask budget: '3ndek budget f balek?'
  4. Then search using car model inference + budget filter

WHEN CUSTOMER MENTIONS ONLY A BRAND (Dacia, Renault...):
  → Always ask which specific model first before searching.
  → Dacia alone is NOT enough — Logan/Sandero/Duster need different batteries.

BUDGET QUESTION — ALWAYS ASK BEFORE SHOWING RESULTS:
  → Before calling search_battery_database, if budget is unknown, ask:
     '3ndek chi budget f balek sidi?' (do you have a budget in mind?)
  → If customer says cheap/rkhis → apply max_price filter
  → If customer says no preference → search without price filter

AFTER SHOWING RESULTS:
  → NEVER ask 'kifach tbedel' (how do you want to pay) immediately
  → Ask: 'wach wahad men homa 3jbek sidi?' (do any of these interest you?)
  → Only discuss payment/ordering AFTER customer confirms interest in a specific product

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
AFTER SHOWING RESULTS — HANDLING CUSTOMER RESPONSES
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
When customer asks about final price / discount / n9os / tkhfid:
  → This means they are interested in buying — it's a buying signal!
  → If the product has discount_percentage > 0 → NOW reveal the discounted price
  → Format: 'Akhir taman f Tudor TA640: [discounted price] DH (bdel men 890 DH) 🎉'
  → Then ask: 'Wach tbedel sidi?' (shall we proceed?)

When customer picks a specific product:
  → Confirm the product name, Ah, and final price
  → Ask for delivery info: 'Fin nwesllek sidi?' (where shall we deliver?)

When customer says 'ghali / too expensive / cher':
  → Acknowledge: 'Wakha sidi, 3ndna chi haja rkhisa...'
  → Show the cheapest option from the previous search results
  → Never search again for the same thing

When customer says 'wakha / ok / confirmed':
  → Ask: 'Smiya dyalek o numero telefon sidi?' (your name and phone number?)
  → Then: 'Fin nwesllek?' (delivery address?)

DISCOUNT REVEAL RULES:
  ✅ Only reveal discount when customer asks for better price / n9os / tkhfid
  ✅ Always frame it as a special deal: 'ghir liyek sidi' (just for you)
  ✅ If product has NO discount → say 'had lprix howa akhir taman sidi, mzyan bzaf'
  ✅ Never reveal ALL discounts at once — only for the product they asked about

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
REPLY STYLE
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
- Warm, short, natural Moroccan salesperson tone
- Use 'sidi' (men) or 'lala' (women) naturally  
- Questions: max 2 lines
- Product results: clear format — name, Ah, price per line
- After results: ask if they want to order or need delivery/warranty info
";
    }

    /**
     * Live stock summary injected into prompt.
     * Prevents AI from claiming products exist that don't.
     */
    private function buildStockSummary(): string
    {
        try {
            $types = Product::where('status', 'active')
                ->where('stock_quantity', '>', 0)
                ->selectRaw('application_type, COUNT(*) as total, MIN(amperage) as min_ah, MAX(amperage) as max_ah, MIN(price) as min_price, MAX(price) as max_price')
                ->groupBy('application_type')
                ->get();

            if ($types->isEmpty()) {
                return "⚠️ STOCK NOTE: The warehouse is currently empty. Tell customers honestly and ask for their contact to notify them when stock arrives.";
            }

            $summary  = "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            $summary .= "LIVE WAREHOUSE STOCK OVERVIEW\n";
            $summary .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

            foreach ($types as $type) {
                $summary .= "• {$type->application_type}: {$type->total} products";
                if ($type->min_ah && $type->max_ah) {
                    $summary .= ", {$type->min_ah}-{$type->max_ah}Ah";
                }
                $summary .= ", " . number_format($type->min_price, 0) . "-" . number_format($type->max_price, 0) . " DH\n";
            }

            $summary .= "IMPORTANT: Only present products returned by the database. Never invent products.\n";

            return $summary;

        } catch (\Throwable $e) {
            Log::warning('Could not build stock summary: ' . $e->getMessage());
            return '';
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TOOL DEFINITION
    // ═══════════════════════════════════════════════════════════════════════════

    private function buildTools(): array
    {
        return [
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'search_battery_database',
                    'description' => 'Search the warehouse for in-stock batteries. '
                                   . 'Call this as soon as you know application_type and any amperage info (exact or range). '
                                   . 'Brand is optional — do not wait for it. '
                                   . 'For known car models, infer the Ah range and search immediately.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'application_type' => [
                                'type'        => 'string',
                                'enum'        => ['car', 'motorcycle', 'solar', 'truck'],
                                'description' => 'Vehicle/use type. Infer: tombil/tyara=car, moto=motorcycle, camion=truck, solaire=solar.',
                            ],
                            'amperage' => [
                                'type'        => 'integer',
                                'description' => 'Exact Ah value for precise searches. For ranges use min_amperage + max_amperage instead.',
                            ],
                            'min_amperage' => [
                                'type'        => 'integer',
                                'description' => 'Lower Ah bound when customer gives a range or when inferring from car model.',
                            ],
                            'max_amperage' => [
                                'type'        => 'integer',
                                'description' => 'Upper Ah bound. Always pair with min_amperage.',
                            ],
                            'brand' => [
                                'type'        => 'string',
                                'description' => 'Brand only if customer explicitly mentioned it. Omit otherwise.',
                            ],
                            'min_price' => [
                                'type'        => 'number',
                                'description' => 'Min price in DH. Omit if no budget mentioned.',
                            ],
                            'max_price' => [
                                'type'        => 'number',
                                'description' => 'Max price in DH. Omit if no budget mentioned.',
                            ],
                        ],
                        'required' => ['application_type'],
                    ],
                ],
            ],
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // GROQ HTTP CLIENT
    // ═══════════════════════════════════════════════════════════════════════════

    private function callGroq(array $body): ?array
    {
        $apiKey = config('services.groq.api_key');

        if (empty($apiKey)) {
            Log::error('Groq API key not configured — check services.groq.api_key');
            return null;
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type'  => 'application/json',
        ])->timeout(30)->post('https://api.groq.com/openai/v1/chat/completions', $body);

        if ($response->failed()) {
            Log::error('Groq API call failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
                'model'  => $body['model'] ?? 'unknown',
            ]);
            return null;
        }

        $data = $response->json();

        if (!empty($data['usage'])) {
            Log::info('Groq token usage', $data['usage']);
        }

        return $data;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // WHATSAPP SENDER
    // ═══════════════════════════════════════════════════════════════════════════

    protected function sendWhatsAppMessage(string $recipientPhone, string $textBody, int $contactId): void
    {
        $textBody = trim($textBody);

        if (empty($textBody)) {
            Log::warning('Attempted to send empty message — aborted', ['contact_id' => $contactId]);
            return;
        }

        $accessToken   = config('services.meta.access_token');
        $phoneNumberId = config('services.meta.phone_number_id');

        if (empty($accessToken) || empty($phoneNumberId)) {
            Log::error('Meta config missing', [
                'access_token_set'    => !empty($accessToken),
                'phone_number_id_set' => !empty($phoneNumberId),
            ]);
            return;
        }

        $response = Http::withToken($accessToken)
            ->post("https://graph.facebook.com/v20.0/{$phoneNumberId}/messages", [
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

            Log::info('WhatsApp message sent', [
                'contact_id' => $contactId,
                'preview'    => mb_substr($textBody, 0, 80),
            ]);

        } else {
            Log::error('Meta outbound send failed', [
                'contact_id' => $contactId,
                'status'     => $response->status(),
                'body'       => $response->body(),
            ]);
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════════════════════

    private function sanitizeReply(string $text): string
    {
        $text = preg_replace('/<function[^>]*>.*?<\/function>/s', '', $text);
        $text = preg_replace('/\{[\s\S]*?"application_type"[\s\S]*?\}/s', '', $text);
        $text = preg_replace('/search_battery_database[^\n]*/i', '', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    private function sendFallback(Message $message): void
    {
        Log::warning('Sending fallback message', ['contact_id' => $message->contact_id]);

        $this->sendWhatsAppMessage(
            $message->contact->whatsapp_id,
            'Smeh liya sidi, kayn mochkil teknik dghia. 3awd men3d aw tssifet lina lmessage dyalek. 🙏',
            $message->contact_id
        );
    }
}