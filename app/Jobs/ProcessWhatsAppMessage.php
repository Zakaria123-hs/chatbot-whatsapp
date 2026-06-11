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
            // 1. Cleaner, lightweight system prompt (NO product data here!)
            $systemPrompt = "You are 'Zaka Battery Assistant', an expert AI concierge for an e-commerce website specializing in high-quality batteries (cars, motorcycles, solar, etc.).
            
YOUR CORE RULES:
- Respond in the EXACT language or dialect the customer uses (Moroccan Darija, Arabic, French, English).
- If the customer asks about a specific battery, wants a price, or checks availability, you MUST call the 'search_battery_database' tool to look up real-time information. Do not guess or make up details.
- Always present the data cleanly using WhatsApp markdown formatting (*bold* keys, list bullet points, clear spacing) and natural emojis.";

            $apiMessages = [['role' => 'system', 'content' => $systemPrompt]];

            // 2. Fetch lightweight recent conversation history
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

            // Append current user message
            $apiMessages[] = ['role' => 'user', 'content' => $message->body];

            // 3. Define the Tool blueprint for Groq
            $tools = [
                [
                    'type' => 'function',
                    'function' => [
                        'name' => 'search_battery_database',
                        'description' => 'Searches the local database for batteries matching a specific search term or brand name provided by the customer.',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'search_term' => [
                                    'type' => 'string',
                                    'description' => 'The brand name or model of the battery extracted from the user text (e.g., Bosch, Varta, YTX9, Solar).'
                                ]
                            ],
                            'required' => ['search_term']
                        ]
                    ]
                ]
            ];

            // 4. First call to Groq: Let the AI decide if it needs a tool
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . env('GROQ_API_KEY'),
                'Content-Type' => 'application/json',
            ])->post('https://api.groq.com/openai/v1/chat/completions', [
                'model' => 'llama-3.1-8b-instant',
                'messages' => $apiMessages,
                'tools' => $tools,
                'tool_choice' => 'auto',
                'temperature' => 0.3,
            ]);

            if ($response->failed()) {
                Log::error('Groq Initial Call Error: ' . $response->body());
                return;
            }

            $responseData = $response->json();
            $responseMessage = $responseData['choices'][0]['message'] ?? null;

            // 5. Check if Groq decided to execute the tool
            if (!empty($responseMessage['tool_calls'])) {
                foreach ($responseMessage['tool_calls'] as $toolCall) {
                    if ($toolCall['function']['name'] === 'search_battery_database') {
                        // Extract arguments determined by the AI
                        $arguments = json_decode($toolCall['function']['arguments'], true);
                        $searchTerm = $arguments['search_term'] ?? '';

                        // Run your fast native Laravel Eloquent Query!
                        $products = Product::where('status', 'active')
                            ->where('name', 'LIKE', '%' . $searchTerm . '%')
                            ->get();

                        // Format what we found into a string for the AI
                        $dbResultString = "";
                        if ($products->isEmpty()) {
                            $dbResultString = "No matching active products found for keyword: " . $searchTerm;
                        } else {
                            foreach ($products as $prod) {
                                $dbResultString .= "- *{$prod->name}* | Retail Price: {$prod->price} DH | Discounted: " . ($prod->is_discountable ? "Yes ({$prod->discount_percentage}%)" : "No") . " | *Final Price: {$prod->final_price} DH* | Stock Level: " . ($prod->stock_quantity > 0 ? "{$prod->stock_quantity} units available" : "OUT OF STOCK") . "\n";
                            }
                        }

                        // Append the AI's intent and the tool results back into the conversation context array
                        $apiMessages[] = $responseMessage; // Add the tool call request
                        $apiMessages[] = [
                            'role' => 'tool',
                            'tool_call_id' => $toolCall['id'],
                            'name' => 'search_battery_database',
                            'content' => $dbResultString // Send the actual database results back to the AI
                        ];

                        // 6. Second call to Groq: Let the AI generate a native human-like response using the query data
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

            // If the user just said "Hi" or something basic, no tool call is triggered. Send the standard text back.
            $fallbackText = $responseMessage['content'] ?? '';
            if (!empty($fallbackText)) {
                $this->sendWhatsAppMessage($message->contact->whatsapp_id, $fallbackText, $message->contact_id);
            }

        } catch (\Exception $e) {
            Log::error('Tool AI Execution Failed: ' . $e->getMessage() . ' on line ' . $e->getLine());
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
            Log::error('Meta Tool Response Outbound Failed: ' . $response->body());
        }
    }
}