<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'contact_id',
        'meta_message_id',
        'sender_type',
        'message_type',
        'body',
        'status',
        'referral_source_url',
        'raw_payload',
        'error_message',
        'processed_at',
    ];

    /**
     * Automatically cast the raw JSON payload to a PHP array
     */
    protected $casts = [
        'raw_payload' => 'array',
    ];

    /**
     * Get the contact that owns this message.
     */
    public function contact(): BelongsTo
    {
        // Fixed: changed $table to $this
        return $this->belongsTo(Contact::class);
    }
}