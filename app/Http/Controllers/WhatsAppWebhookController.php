<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Contact;
use App\Models\Message;
use App\Jobs\ProcessWhatsAppMessage;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    /**
     * 1. Meta Webhook Verification (GET Request)
     * Meta fires this once when you hit "Verify and Save" in their dashboard.
     */
    public function verify(Request $request)
    {
        $mode      = $request->query('hub_mode');
        $token     = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        // ✅ FIX: Use config() instead of env() so it works after php artisan config:cache
        if ($mode === 'subscribe' && $token === config('services.meta.verify_token')) {
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        Log::warning('WhatsApp webhook verification failed', [
            'mode'  => $mode,
            'token' => $token,
        ]);

        return response()->json(['error' => 'Unauthorized'], 403);
    }

    /**
     * 2. Receive Live Chat Data (POST Request)
     * Meta drops incoming WhatsApp messages here.
     */
    public function receive(Request $request)
    {
        $payload = $request->all();

        // ✅ FIX: Wrap everything in try/catch so Meta always gets 200
        // If we throw, Meta retries the webhook repeatedly — we never want that
        try {
            // Safe navigation through Meta's multi-nested payload
            $value = $payload['entry'][0]['changes'][0]['value'] ?? null;

            if (is_null($value)) {
                Log::warning('WhatsApp webhook: empty value block', ['payload' => $payload]);
                return response()->json(['status' => 'empty_value'], 200);
            }

            // Ignore status update payloads (sent, delivered, read receipts)
            if (isset($value['statuses'])) {
                return response()->json(['status' => 'status_ignored'], 200);
            }

            // Check if this payload contains a real message
            if (isset($value['messages'][0])) {

                $metaMessage   = $value['messages'][0];
                $customerPhone = $metaMessage['from'];                               // e.g. 2126XXXXXXXX
                $profileName   = $value['contacts'][0]['profile']['name'] ?? 'Client';
                $metaMessageId = $metaMessage['id'];
                $messageType   = $metaMessage['type'];

                // ✅ FIX: Handle all message types — not just text & referral
                $bodyText = $this->extractMessageBody($metaMessage);

                // Extract Click-To-WhatsApp ad referral URL if present
                $adUrl = $metaMessage['referral']['source_url'] ?? null;

                // ✅ FIX: Use updateOrCreate so last_message_at updates on every message
                // firstOrCreate only sets values on creation — existing contacts were never updated
                $contact = Contact::updateOrCreate(
                    ['whatsapp_id' => $customerPhone],
                    [
                        'profile_name'    => $profileName,
                        'last_message_at' => now(),
                    ]
                );

                // Prevent duplicate processing
                // Meta retries the webhook if your server is slow — this guards against that
                $existingMessage = Message::where('meta_message_id', $metaMessageId)->first();
                if ($existingMessage) {
                    Log::info('WhatsApp webhook: duplicate message ignored', [
                        'meta_message_id' => $metaMessageId,
                    ]);
                    return response()->json(['status' => 'duplicate_ignored'], 200);
                }

                // Store the incoming message with status 'received'
                $message = Message::create([
                    'contact_id'          => $contact->id,
                    'meta_message_id'     => $metaMessageId,
                    'sender_type'         => 'user',
                    'message_type'        => $messageType,
                    'body'                => $bodyText,
                    'referral_source_url' => $adUrl,
                    'status'              => 'received',
                    'raw_payload'         => $payload,  // ✅ Requires cast in Message model (see below)
                ]);

                // Dispatch to background queue worker immediately
                ProcessWhatsAppMessage::dispatch($message->id);

                Log::info('WhatsApp webhook: message dispatched', [
                    'contact_id'      => $contact->id,
                    'meta_message_id' => $metaMessageId,
                    'type'            => $messageType,
                ]);

                // Instantly respond 200 OK — Meta will timeout after 20s if we don't
                return response()->json(['status' => 'success_dispatched'], 200);
            }

            // Payload arrived but had no messages (can happen with account-level events)
            return response()->json(['status' => 'no_messages_found'], 200);

        } catch (\Throwable $e) {
            // ✅ FIX: Log the error but still return 200 — never let Meta retry endlessly
            Log::error('WhatsApp webhook exception: ' . $e->getMessage(), [
                'payload' => $payload,
                'trace'   => $e->getTraceAsString(),
            ]);

            return response()->json(['status' => 'error_logged'], 200);
        }
    }

    /**
     * 3. Extract a human-readable body text from any message type.
     *    Meta sends very different structures per type — handle them all here.
     *
     * ✅ FIX: Previously only 'text' and 'referral' were handled.
     *         All other types silently stored an empty string.
     */
    private function extractMessageBody(array $metaMessage): string
    {
        $type = $metaMessage['type'];

        return match ($type) {

            // Plain text message
            'text' => $metaMessage['text']['body'] ?? '',

            // Media messages — store caption if available, fallback to label
            'image'    => $metaMessage['image']['caption']    ?? '[Image]',
            'video'    => $metaMessage['video']['caption']    ?? '[Video]',
            'document' => $metaMessage['document']['filename'] ?? '[Document]',
            'audio'    => '[Audio Message]',
            'sticker'  => '[Sticker]',

            // Location — format coordinates into readable string
            'location' => sprintf(
                '[Location: %s, %s]',
                $metaMessage['location']['latitude']  ?? '?',
                $metaMessage['location']['longitude'] ?? '?'
            ),

            // Contacts card shared by user
            'contacts' => '[Contact Card: ' . ($metaMessage['contacts'][0]['name']['formatted_name'] ?? 'Unknown') . ']',

            // Interactive replies — list or button selections
            'interactive' => match ($metaMessage['interactive']['type'] ?? '') {
                'button_reply' => $metaMessage['interactive']['button_reply']['title'] ?? '[Button Reply]',
                'list_reply'   => $metaMessage['interactive']['list_reply']['title']   ?? '[List Reply]',
                default        => '[Interactive Message]',
            },

            // Reaction (emoji reaction to a message)
            'reaction' => '[Reaction: ' . ($metaMessage['reaction']['emoji'] ?? '?') . ']',

            // Order placed via WhatsApp catalog
            'order' => '[Order Received]',

            // Click-to-WhatsApp ad entry point
            'referral' => '[Clicked Ad: ' . ($metaMessage['referral']['source_url'] ?? 'unknown source') . ']',

            // Fallback for any future Meta message types
            default => '[Unsupported message type: ' . $type . ']',
        };
    }
}
