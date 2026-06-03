<?php

namespace App\Jobs;

use App\Models\Message;
use App\Models\Contact;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class ProcessWhatsAppMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $messageId;

    /**
     * Create a new job instance.
     */
    public function __construct($messageId)
    {
        $this->messageId = $messageId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // 1. Find the incoming message cleanly using Eloquent
        $message = Message::find($this->messageId);

        if (!$message || $message->status !== 'received') {
            return;
        }

        // 2. Mark it as processing
        $message->update(['status' => 'processing']);

        try {
            // 3. Create the echo response text
            $echoReplyText = "Echo Bot: You said \"" . $message->body . "\"";

            // 4. Fetch the contact model relationship cleanly
            $contact = $message->contact;
            
            // Meta Graph API Outbound Message URL setup
            $phoneId = env('META_WHATSAPP_PHONE_NUMBER_ID');
            $accessToken = env('META_WHATSAPP_ACCESS_TOKEN');
            $url = "https://graph.facebook.com/v20.0/{$phoneId}/messages";

            // 5. Send payload to Meta Cloud API
            $metaResponse = Http::withToken($accessToken)->post($url, [
                'messaging_product' => 'whatsapp',
                'recipient_type'    => 'individual',
                'to'                => $contact->whatsapp_id,
                'type'              => 'text',
                'text'              => [
                    'preview_url' => false,
                    'body'        => $echoReplyText
                ]
            ]);

            if ($metaResponse->failed()) {
                throw new Exception('Meta Cloud API outbound request failed: ' . $metaResponse->body());
            }

            // Extract Meta's unique message ID for the bot's response
            $metaResponseData = $metaResponse->json();
            $botMetaMessageId = $metaResponseData['messages'][0]['id'] ?? 'bot_' . uniqid();

            // 6. Store the Bot echo response in your messages table
            Message::create([
                'contact_id'          => $contact->id,
                'meta_message_id'     => $botMetaMessageId, // Updated column name
                'sender_type'         => 'bot',
                'message_type'        => 'text',
                'body'                => $echoReplyText,
                'status'              => 'completed',
                'raw_payload'         => $metaResponseData, // Handled beautifully as an array due to casts!
                'processed_at'        => now(),
            ]);

            // 7. Update the original incoming user message and contact timestamp to completed
            $message->update([
                'status'       => 'completed',
                'processed_at' => now()
            ]);

            $contact->update([
                'last_message_at' => now()
            ]);

        } catch (Exception $e) {
            Log::error('Meta Worker Queue Error: ' . $e->getMessage());
            
            $message->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage()
            ]);
        }
    }
}