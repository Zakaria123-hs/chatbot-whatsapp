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
            $systemPrompt = "You are 'Zaka Battery Assistant', an expert AI concierge for an e-commerce website specializing in high-quality batteries (cars, motorcycles, solar, etc.).
            
YOUR CORE RULES:
- Respond in the EXACT language or dialect the customer uses (Moroccan Darija, Arabic, French, English).
- If the customer asks for a battery, a specific brand, checks a price, or asks for a range of prices (e.g., between 300 and 500 DH), you MUST use the 'search_battery_database' tool. Do not guess the stock or pricing.
- Present data cleanly using WhatsApp markdown (*bold* keys, bullet points) and friendly emojis.";

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
                        'description' => 'Searches the local database for batteries by keyword, brand, exact price, or minimum/maximum price ranges.',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'search_term' => [
                                    'type' => 'string',
                                    'description' => 'The brand name or model keyword extracted from user text (e.g., Bosch, Varta, motorcycle, car). Pass an empty string if they only ask for a price range.'
                                ],
                                'exact_price' => [
                                    'type' => 'number',
                                    'description' => 'An exact price if the user explicitly asks for something costing a fixed amount (e.g., 800).'
                                ],
                                'min_price' => [
                                    'type' => 'number',
                                    'description' => 'The lower bound of a price range if provided by the customer (e.g., 300).'
                                ],
                                'max_price' => [
                                    'type' => 'number',
                                    'description' => 'The upper bound of a price range if provided by the customer (e.g., 500).'
                                ]
                            ]
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
                        
                        $searchTerm = $arguments['search_term'] ?? null;
                        $exactPrice = $arguments['exact_price'] ?? null;
                        $minPrice = $arguments['min_price'] ?? null;
                        $maxPrice = $arguments['max_price'] ?? null;

                        // Start building the query dynamically
                        $query = Product::where('status', 'active');

                        // Filter by text keyword if present
                        if (!empty($searchTerm)) {
                            $query->where('name', 'LIKE', '%' . $searchTerm . '%');
                        }

                        // Filter by exact price if present
                        if (!property_exists((object)$arguments, 'exact_price') && !is_null($exactPrice)) {
                            $query->where('price', '=', $exactPrice);
                        }

                        // Filter by price ranges if present
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
                            $dbResultString = "No matching active products found with your criteria.";
                        } else {
                            foreach ($products as $prod) {
                                $dbResultString .= "- *{$prod->name}* | Original: {$prod->price} DH | Discounted: " . ($prod->is_discountable ? "Yes ({$prod->discount_percentage}%)" : "No") . " | *Final Price: {$prod->final_price} DH* | Stock: " . ($prod->stock_quantity > 0 ? "{$prod->stock_quantity} units" : "OUT OF STOCK") . "\n";
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