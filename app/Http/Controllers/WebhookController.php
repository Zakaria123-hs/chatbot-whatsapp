<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Jobs\ProcessWhatsAppMessage;
use Illuminate\Support\Facades\Log;
use Exception;

class WebhookController extends Controller
{
    public function receive(Request $request)
    {
        if ($request->input('typeWebhook') !== 'incomingMessageReceived') {
            return response()->json(['status' => 'ignored', 'message' => 'Not an incoming message webhook'], 200);
        }

        $messageId   = $request->input('idMessage');
        $whatsappId  = $request->input('senderData.chatId');
        $senderName  = $request->input('senderData.senderName');
        $messageType = $request->input('messageData.typeMessage');
        $body        = $request->input('messageData.textMessageData.textMessage')
                    ?? $request->input('messageData.extendedTextMessageData.textMessage')
                    ?? '';

        // 1. Duplicate check
        $exists = DB::table('messages')->where('green_api_message_id', $messageId)->exists();
        if ($exists) {
            return response()->json(['status' => 'ignored', 'message' => 'Duplicate webhook request'], 200);
        }

        try {
            // 2. Upsert contact
            $contact = DB::table('contacts')->where('whatsapp_id', $whatsappId)->first();
            if ($contact) {
                DB::table('contacts')->where('whatsapp_id', $whatsappId)->update([
                    'name'            => $senderName,
                    'last_message_at' => now(),
                    'updated_at'      => now(),
                ]);
                $contactId = $contact->id;
            } else {
                $contactId = DB::table('contacts')->insertGetId([
                    'whatsapp_id'     => $whatsappId,
                    'name'            => $senderName,
                    'last_message_at' => now(),
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);
            }

            // 3. Insert message
            $messageId = DB::table('messages')->insertGetId([
                'contact_id'           => $contactId,
                'green_api_message_id' => $messageId,
                'sender_type'          => 'customer',
                'message_type'         => $messageType,
                'body'                 => $body,
                'status'               => 'received',
                'raw_payload'          => json_encode($request->all()),
                'created_at'           => now(),
                'updated_at'           => now(),
            ]);

            // 4. Dispatch job
            ProcessWhatsAppMessage::dispatch($messageId);

            return response()->json(['status' => 'success', 'message' => 'Message queued successfully'], 200);

        } catch (Exception $e) {
            Log::error('Error processing WhatsApp Webhook: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Internal logic error'], 500);
        }
    }
}