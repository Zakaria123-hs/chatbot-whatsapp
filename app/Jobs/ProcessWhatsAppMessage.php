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

    protected $messageId;

    public function __construct($messageId)
    {
        $this->messageId = $messageId;
    }

    public function handle()
    {
        $message = Message::with('contact')->find($this->messageId);

        if (!$message || $message->sender_type !== 'user') {
            return;
        }

        try {
            $systemPrompt = "You are 'Zaka Battery Assistant', an expert human-like consultant for a battery e-commerce store in Morocco. Many customers do not know technical battery details, so your job is to guide them conversationally to find the perfect product.
                YOUR GOAL:
                Before you call the 'search_battery_database' tool, you should ideally know:
                1. Vehicle/Application Type (Car, Motorcycle, Truck, or Solar system).
                2. Battery Capacity in Amperes (Ah) (e.g., 60Ah, 74Ah, 100Ah).
                3. Preferred Brand (Bosch, Varta, Yuasa, etc. - Optional, only if they care).

                DIAGNOSTIC CONVERSATION RULES:
                - Read the user's message and check the chat history. Mark down which info slots are already known.
                - DO NOT ask all questions at once. Ask ONE clear, friendly question at a time to get the missing information.
                - Match the customer's dialect naturally (Moroccan Darija 🇲🇦, Arabic, French, or English). Keep your phrasing warm, helpful, and local.
                - If the customer provides partial information (e.g., 'I want a Bosch battery'), check your history, recognize that Amperes/Ah is missing, and politely ask them: 'Wakha sidi, chhal mn Ah (Ampère) fiha wla ina tombil 3ndk?' (Sure, how many Ah or what car do you have?).
                - Once you have gathered enough parameters to make a useful search, trigger the 'search_battery_database' tool immediately to show them real matching options with their stock and dynamic final prices.";

            $apiMessages = [['role' => 'system', 'content' => $systemPrompt]];

            // Fetch recent conversation history
            $history = Message::where('contact_id', $message->contact_id)
                ->where('id', '<', $this->messageId)
                ->orderBy('id', 'desc')
                ->limit(6)
                ->get()
                ->reverse();

            foreach ($history as $pastMessage) {
                $apiMessages[] = [
                    'role' => $pastMessage->sender_type === 'user' ? 'user' : 'assistant',
                    'content' => $pastMessage->body
                ];
            }

            // Append current message
            $apiMessages[] = ['role' => 'user', 'content' => $message->body];

            // 1. Expanded Tool Blueprint with Price Filters
            $tools = [
                    [
                        'type' => 'function',
                        'function' => [
                            'name' => 'search_battery_database',
                            'description' => 'Queries the warehouse database for available active batteries matching specific filters. Only call this when the customer is looking for a battery. Always filter by application_type if determinable from context.',
                            'parameters' => [
                                'type' => 'object',
                                'properties' => [
                                    'brand' => [
                                        'type' => 'string', // Fixed: Single type string. Groq will omit if missing.
                                        'description' => 'Brand manufacturer name (e.g. "Bosch", "Varta").'
                                    ],
                                    'amperage' => [
                                        'type' => 'integer', // Fixed: Single type integer.
                                        'description' => 'Battery capacity in Ah as integer (e.g. 74, 100, 60).'
                                    ],
                                    'application_type' => [
                                        'type' => 'string',
                                        'enum' => ['car', 'motorcycle', 'solar', 'truck'],
                                        'description' => 'Vehicle or usage type. Infer from context if not explicitly stated (e.g., "tombil" implies car, "motor" implies motorcycle).'
                                    ],
                                    'min_price' => [
                                        'type' => 'number',
                                        'description' => 'Minimum price filter in local currency (DH).'
                                    ],
                                    'max_price' => [
                                        'type' => 'number',
                                        'description' => 'Maximum price filter in local currency (DH).'
                                    ],
                                ],
                                'required' => ['application_type'], // Brilliantly forces diagnostic behavior first!
                                'additionalProperties' => false
                            ]
                        ]
                    ]
                ];
            // First call to Groq
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . env('GROQ_API_KEY'),
                'Content-Type' => 'application/json',
            ])->post('https://api.groq.com/openai/v1/chat/completions', [
                'model' => 'llama-3.1-8b-instant',
                'messages' => $apiMessages,
                'tools' => $tools,
                'tool_choice' => 'auto',
                'temperature' => 0.2,
            ]);

            if ($response->failed()) {
                Log::error('Groq Initial Call Error: ' . $response->body());
                return;
            }

            $responseData = $response->json();
            $responseMessage = $responseData['choices'][0]['message'] ?? null;

            // 2. Process the Tool Call with dynamic Eloquent query logic
            if (!empty($responseMessage['tool_calls'])) {
                foreach ($responseMessage['tool_calls'] as $toolCall) {
                    if ($toolCall['function']['name'] === 'search_battery_database') {
                        $arguments = json_decode($toolCall['function']['arguments'], true);
                        
                        // Build the query instantly off real columns
                        $query = Product::where('status', 'active');

                        if (!empty($arguments['brand'])) {
                            $query->where('brand', 'LIKE', '%' . $arguments['brand'] . '%');
                        }

                        if (!empty($arguments['amperage'])) {
                            $query->where('amperage', '=', $arguments['amperage']);
                        }

                        if (!empty($arguments['application_type'])) {
                            $query->where('application_type', '=', $arguments['application_type']);
                        }

                        if (!empty($arguments['min_price'])) {
                            $query->where('price', '>=', $arguments['min_price']);
                        }

                        if (!empty($arguments['max_price'])) {
                            $query->where('price', '<=', $arguments['max_price']);
                        }

                        $products = $query->get();

                        // Format results for the AI
                        $dbResultString = "";
                        if ($products->isEmpty()) {
                            $dbResultString = "No matching batteries found in the warehouse right now.";
                        } else {
                            foreach ($products as $prod) {
                                $dbResultString .= "- *{$prod->name}* ({$prod->brand}) | Spec: {$prod->amperage}Ah | Price: {$prod->final_price} DH | Stock: {$prod->stock_quantity}\n";
                            }
                        }

                        $apiMessages[] = $responseMessage; 
                        $apiMessages[] = [
                            'role' => 'tool',
                            'tool_call_id' => $toolCall['id'],
                            'name' => 'search_battery_database',
                            'content' => $dbResultString
                        ];

                        // Second call to Groq for the human-like text reply
                        $secondResponse = Http::withHeaders([
                            'Authorization' => 'Bearer ' . env('GROQ_API_KEY'),
                            'Content-Type' => 'application/json',
                        ])->post('https://api.groq.com/openai/v1/chat/completions', [
                            'model' => 'llama-3.1-8b-instant',
                            'messages' => $apiMessages,
                            'temperature' => 0.5,
                        ]);

                        if ($secondResponse->successful()) {
                            $finalText = $secondResponse->json('choices.0.message.content');
                            $this->sendWhatsAppMessage($message->contact->whatsapp_id, $finalText, $message->contact_id);
                        }
                        return;
                    }
                }
            }
            $fallbackText = $responseMessage['content'] ?? '';
            if (!empty($fallbackText)) {
                $this->sendWhatsAppMessage($message->contact->whatsapp_id, $fallbackText, $message->contact_id);
            }

        } catch (\Exception $e) {
            Log::error('Tool AI Price Filter Failed: ' . $e->getMessage() . ' on line ' . $e->getLine());
        }
    }

    protected function sendWhatsAppMessage($recipientPhone, $textBody, $contactId)
    {
        $phoneId = env('META_WHATSAPP_PHONE_NUMBER_ID');
        $accessToken = env('META_WHATSAPP_ACCESS_TOKEN');

        $response = Http::withToken($accessToken)
            ->post("https://graph.facebook.com/v20.0/{$phoneId}/messages", [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $recipientPhone,
                'type' => 'text',
                'text' => ['preview_url' => false, 'body' => $textBody]
            ]);

        if ($response->successful()) {
            $metaData = $response->json();
            Message::create([
                'contact_id' => $contactId,
                'meta_message_id' => $metaData['messages'][0]['id'] ?? null,
                'sender_type' => 'bot',
                'message_type' => 'text',
                'body' => $textBody,
                'status' => 'sent',
                'raw_payload' => $metaData,
            ]);
        } else {
            Log::error('Meta Outbound Send Failed: ' . $response->body());
        }
    }
}