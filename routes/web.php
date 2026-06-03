<?php

use Illuminate\Support\Facades\Route;
use App\Jobs\ProcessWhatsAppMessage;
use App\Http\Controllers\WebhookController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/test-queue', function () {
    // This safely adds a dummy job to the queue table without breaking on database checks
    ProcessWhatsAppMessage::dispatch(999);
    
    return "Success! Check your queue:work terminal window now.";
});

use App\Http\Controllers\WhatsAppWebhookController;

// Make sure these are the ONLY whatsapp webhook lines!
Route::get('/api/webhook/whatsapp', [WhatsAppWebhookController::class, 'verify']);
Route::post('/api/webhook/whatsapp', [WhatsAppWebhookController::class, 'receive']);