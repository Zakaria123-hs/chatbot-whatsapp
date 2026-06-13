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
                                'description' => 'Searches the database for batteries. Do NOT invent new parameters like brand. Put all brand names, capacities, or keywords inside the search_term parameter.',
                                'parameters' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'search_term' => [
                                            'type' => 'string',
                                            'description' => 'The search keyword, brand name, or capacity model (e.g., Bosch, Varta, 74Ah, car). Leave null or empty string ONLY if the user is only asking for a price range.'
                                        ],
                                        'exact_price' => [
                                            'type' => 'number',
                                            'description' => 'Match an exact price if mentioned. DO NOT provide or set to 0 unless the user literally requested something for 0 DH.'
                                        ],
                                        'min_price' => [
                                            'type' => 'number',
                                            'description' => 'The minimum price boundary. Do NOT set to 0 unless explicitly requested.'
                                        ],
                                        'max_price' => [
                                            'type' => 'number',
                                            'description' => 'The maximum price boundary.'
                                        ]
                                    ],
                                    // We don't make anything strictly required so it can be fully dynamic
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
                        
                        // Extract parameters, safely defaulting to null instead of 0
                        $searchTerm = $arguments['search_term'] ?? null;
                        
                        // Crucial fix: If the AI sets values to 0 or empty strings, treat them as null
                        $exactPrice = (!empty($arguments['exact_price'])) ? $arguments['exact_price'] : null;
                        $minPrice = (!empty($arguments['min_price'])) ? $arguments['min_price'] : null;
                        $maxPrice = (!empty($arguments['max_price'])) ? $arguments['max_price'] : null;

                        // Check if the AI accidentally put the brand into a hallucinated 'brand' key
                        if (empty($searchTerm) && !empty($arguments['brand'])) {
                            $searchTerm = $arguments['brand'];
                        }

                        // Start building the query dynamically
                        $query = Product::where('status', 'active');

                        if (!empty($searchTerm)) {
                            $query->where('name', 'LIKE', '%' . $searchTerm . '%');
                        }

                        if (!is_null($exactPrice)) {
                            $query->where('price', '=', $exactPrice);
                        }

                        if (!is_null($minPrice)) {
                            $query->where('price', '>=', $minPrice);
                        }
                        if (!is_null($maxPrice)) {
                            $query->where('price', '<=', $maxPrice);
                        }

                        $products = $query->get();

                        // Format results string back to the AI
                        $dbResultString = "";
                        if ($products->isEmpty()) {
                            $dbResultString = "No matching active products found for your criteria.";
                        } else {
                            foreach ($products as $prod) {
                                $dbResultString .= "- *{$prod->name}* | Original: {$prod->price} DH | *Final Price: {$prod->final_price} DH* | Stock: " . ($prod->stock_quantity > 0 ? "{$prod->stock_quantity} units" : "OUT OF STOCK") . "\n";
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