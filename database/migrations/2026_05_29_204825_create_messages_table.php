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
            $table->foreignId('contact_id')->constrained()->onDelete('cascade');
            $table->string('meta_message_id')->unique(); // For Meta API IDs
            $table->enum('sender_type', ['user', 'bot', 'agent']);
            $table->string('message_type')->default('text');
            $table->text('body');
            $table->string('status')->default('received');
            $table->string('referral_source_url')->nullable(); 
            $table->json('raw_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};