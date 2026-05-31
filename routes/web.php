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

// Manually defining the /api prefix inside web.php ignores all hidden API settings
Route::post('/api/webhook/whatsapp', [WebhookController::class, 'receive']);