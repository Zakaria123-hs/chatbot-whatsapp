<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WebhookController;
use App\Jobs\ProcessWhatsAppMessage;

// Route::get('/test-queue', function () {
//     // Dispatch a fake message ID (e.g., 999) just to see if the worker responds
//     ProcessWhatsAppMessage::dispatch(999);
//     return "Job dispatched to queue!";
// });
// // Your WhatsApp GREEN-API Webhook Route
// Route::post('/webhook/whatsapp', [WebhookController::class, 'receive']);