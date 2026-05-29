<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            // Link to the contact
            $table->foreignId('contact_id')->constrained()->onDelete('cascade');
            
            // Unique GREEN-API message ID to prevent duplicate webhook processing
            $table->string('green_api_message_id')->unique()->index();
            
            // Tracks who sent it ('customer' or 'bot')
            $table->string('sender_type'); 
            
            // Type of message ('text', 'image', 'audio', etc.)
            $table->string('message_type'); 
            
            // The actual message text content
            $table->text('body');
            
            // Queue pipeline state ('received', 'processing', 'completed', 'failed')
            $table->string('status')->default('received');
            
            // Stores the full incoming JSON webhook payload for safety and debugging
            $table->json('raw_payload');
            
            // Stores the exception message if the OpenAI or GREEN-API call fails
            $table->text('error_message')->nullable();
            
            // Tracks exactly when the Redis worker finished processing this message
            $table->timestamp('processed_at')->nullable();
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};