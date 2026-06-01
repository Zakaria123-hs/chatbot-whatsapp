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
use Illuminate\Support\Facades\DB;
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
        // 1. Find the incoming message in the database using DB::table
        $message = DB::table('messages')->where('id', $this->messageId)->first();

        if (!$message || $message->status !== 'received') {
            return;
        }

        // 2. Mark it as processing
        DB::table('messages')->where('id', $this->messageId)->update(['status' => 'processing']);

        try {
            // 3. NO AI KEY NEEDED: Simply create a static echo reply text
            $echoReplyText = "Echo Bot: You said \"" . $message->body . "\"";

            // 4. Send that text back to your brother's phone via GREEN-API
            $contact = DB::table('contacts')->where('id', $message->contact_id)->first();
            
            $url = "https://api.green-api.com/waInstance" . env('GREEN_API_ID_INSTANCE') . "/sendMessage/" . env('GREEN_API_TOKEN_INSTANCE');

            $greenApiResponse = Http::post($url, [
                'chatId' => $contact->whatsapp_id,
                'message' => $echoReplyText
            ]);

            if ($greenApiResponse->failed()) {
                \log::info('GREEN-API outgoing request failed: ' . $greenApiResponse->body());

                throw new Exception('GREEN-API outgoing request failed: ' . $greenApiResponse->body());
            }

            // 5. Store the Bot echo response in your messages table using DB::table
            DB::table('messages')->insert([
                'contact_id'           => $contact->id,
                'green_api_message_id' => $greenApiResponse->json('idMessage') ?? 'bot_' . uniqid(),
                'sender_type'          => 'bot',
                'message_type'         => 'textMessage',
                'body'                 => $echoReplyText,
                'status'               => 'completed',
                'raw_payload'          => json_encode($greenApiResponse->json() ?? []),
                'processed_at'         => now(),
                'created_at'           => now(),
                'updated_at'           => now(),
            ]);

            // 6. Update the original incoming message to completed
            DB::table('messages')->where('id', $this->messageId)->update([
                'status' => 'completed',
                'processed_at' => now()
            ]);

            DB::table('contacts')->where('id', $contact->id)->update([
                'last_message_at' => now()
            ]);

        } catch (Exception $e) {
            Log::error('Redis Worker Error: ' . $e->getMessage());
            
            DB::table('messages')->where('id', $this->messageId)->update([
                'status' => 'failed',
                'error_message' => $e->getMessage()
            ]);
        }
    }
}