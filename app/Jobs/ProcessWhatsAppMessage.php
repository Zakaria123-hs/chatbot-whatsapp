<?php

namespace App\Jobs;

use App\Models\Message;
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
        // 1. Fetch the incoming user message
        $message = Message::with('contact')->find($this->messageId);

        if (!$message || $message->sender_type !== 'user') {
            return;
        }

        try {
            // 2. Fetch active products from your database to give to the AI
            $products = \App\Models\Product::where('status', 'active')->get();
            
            $productListString = "";
            foreach ($products as $prod) {
                $productListString .= "- Name: {$prod->name} | Price: {$prod->price} DH | Discountable: " . ($prod->is_discountable ? "Yes ({$prod->discount_percentage}%)" : "No") . " | Final Price: {$prod->final_price} DH | Stock: {$prod->stock_quantity}\n";
            }

            // 3. Build the Multilingual Battery Expert System Prompt
            $systemContent = "You are 'Zaka Battery Assistant', an expert AI concierge for an e-commerce website specializing in high-quality batteries (cars, motorcycles, solar systems, etc.).

                YOUR CORE JOB:
                - Help customers find the exact battery details they are looking for.
                - Respond in the EXACT same language or dialect the customer uses. You must support and fluently switch between: Moroccan Darija (الدارجة), Classical Arabic (العربية), French, and English.
                - If they ask about a specific product, check the available store product list below and show them its price, final price (if discounted), and stock status.

                CRITICAL FORMATTING RULES:
                1. Always use clean WhatsApp styling: bold keys (*text*) and use bullet points for lists.
                2. Keep responses brief and friendly. No huge paragraphs.
                3. Use relevant emojis naturally (🔋, 🚗, ⚡, 💰, 🛒).

                AVAILABLE STORE PRODUCT LIST (From Database):
                " . ($productListString ?: "No products available in stock right now.") . "

                OPERATIONAL BEHAVIOR:
                - If a product is out of stock, inform them politely.
                - If they ask for a product that doesn't match anything in the list, politely tell them in their language that you couldn't find that exact model, and offer to suggest an alternative battery based on what they need (e.g., car brand or capacity).";

            $apiMessages = [
                [
                    'role' => 'sytem',
                    'content' => $systemContent
                ]
            ];

            // 4. Fetch recent conversation history (Last 6 turns to keep it fast)
            $history = Message::where('contact_id', $message->contact_id)
                ->where('id', '<', $this->messageId)
                ->orderBy('id', 'desc')
                ->limit(6)
                ->get()
                ->reverse();

            foreach ($history as $pastMessage) {
                $role = ($pastMessage->sender_type === 'user') ? 'user' : 'assistant';
                $apiMessages[] = [
                    'role' => $role,
                    'content' => $pastMessage->body
                ];
            }

            // 5. Append current user message
            $apiMessages[] = [
                'role' => 'user',
                'content' => $message->body
            ];

            // 6. Call Groq
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . env('GROQ_API_KEY'),
                'Content-Type' => 'application/json',
            ])->post('https://api.groq.com/openai/v1/chat/completions', [
                'model' => 'llama-3.1-8b-instant',
                'messages' => $apiMessages,
                'temperature' => 0.5, // Lower temperature means more accurate product matching
            ]);

            if ($response->failed()) {
                Log::error('Groq API Error: ' . $response->body());
                return;
            }

            $aiResponseText = $response->json('choices.0.message.content');

            if (!empty($aiResponseText)) {
                $this->sendWhatsAppMessage($message->contact->whatsapp_id, $aiResponseText, $message->contact_id);
            }

        } catch (\Exception $e) {
            Log::error('Battery AI Processing Failed: ' . $e->getMessage());
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
                'text' => [
                    'preview_url' => false,
                    'body' => $textBody
                ]
            ]);

        if ($response->successful()) {
            $metaData = $response->json();
            $metaMessageId = $metaData['messages'][0]['id'] ?? null;

            // This ensures the bot's reply is saved so it can be read as history on the NEXT message turn!
            Message::create([
                'contact_id'      => $contactId,
                'meta_message_id' => $metaMessageId,
                'sender_type'     => 'bot',
                'message_type'    => 'text',
                'body'            => $textBody,
                'status'          => 'sent',
                'raw_payload'     => $metaData,
            ]);
        } else {
            Log::error('Meta Outbound Send Failed: ' . $response->body());
        }
    }
}