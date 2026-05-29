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
        'green_api_message_id',
        'sender_type',
        'body',
        'status',
    ];

    /**
     * Get the contact that owns this message.
     */
    public function contact(): BelongsTo
    {
        return $table->belongsTo(Contact::class);
    }
}