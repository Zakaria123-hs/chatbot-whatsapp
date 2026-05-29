<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            // The unique identifier from GREEN-API (e.g., '212600000000@c.us')
            $table->string('whatsapp_id')->unique();
            // The customer's WhatsApp profile name
            $table->string('name')->nullable();
            // Tracks when the last message was exchanged (useful for sorting chats)
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};