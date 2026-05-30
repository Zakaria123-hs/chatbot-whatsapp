<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WebhookController;

// Your WhatsApp GREEN-API Webhook Route
Route::post('/webhook/whatsapp', [WebhookController::class, 'receive']);