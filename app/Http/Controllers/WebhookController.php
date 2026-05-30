<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Contact;
use App\Models\Message;
use App\Jobs\ProcessWhatsAppMessage;
use Illuminate\Support\Facades\Log;
use Exception;

class WebhookController extends Controller
{
    public function receive(Request $request)
    {
        // 1. Validate payload: Ensure it's an incoming message webhook type
        if ($request->input('typeWebhook') !== 'incomingMessageReceived') {
            return response()->json(['status' => 'ignored', 'message' => 'Not an incoming message webhook'], 200);
        }

        // Extract key pieces from the payload safely
        $messageId = $request->input('idMessage');
        $whatsappId = $request->input('senderData.chatId');
        $senderName = $request->input('senderData.senderName');
        $messageType = $request->input('messageData.typeMessage');
        
        // Safely extract text body depending on whether it's a pure text message or an extended text message
        $body = $request->input('messageData.textMessageData.textMessage') 
             ?? $request->input('messageData.extendedTextMessageData.textMessage') 
             ?? '';

        // 2. Prevent Duplicates: If this exact message ID is already in our DB, stop immediately
        $duplicateCheck = Message::where('green_api_message_id', $messageId)->exists();
        if ($duplicateCheck) {
            return response()->json(['status' => 'ignored', 'message' => 'Duplicate webhook request'], 200);
        }

        try {
            // 3. Save / Update Contact (Find by whatsapp_id or create a new one)
            $contact = Contact::updateOrCreate(
                ['whatsapp_id' => $whatsappId],
                [
                    'name' => $senderName,
                    'last_message_at' => now()
                ]
            );

            // 4. Save the Message to DB with status 'received'
            $message = Message::create([
                'contact_id'           => $contact->id,
                'green_api_message_id' => $messageId,
                'sender_type'          => 'customer',
                'message_type'         => $messageType,
                'body'                 => $body,
                'status'               => 'received',
                'raw_payload'          => $request->all(), // For logs/debugging
            ]);

            // 5. Dispatch the background Job to Redis Queue
            // We pass the saved message ID so the worker can fetch it from the DB and work on it asynchronously
            ProcessWhatsAppMessage::dispatch($message->id);

            // 6. Return 200 OK quickly (Green-API gets its acknowledgment; processing happens in background)
            return response()->json(['status' => 'success', 'message' => 'Message queued successfully'], 200);

        } catch (Exception $e) {
            // Log issues if database storage or dispatching breaks
            Log::error('Error processing WhatsApp Webhook: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Internal logic error'], 500);
        }
    }
}