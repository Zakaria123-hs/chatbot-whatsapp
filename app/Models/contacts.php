<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    use HasFactory;

    protected $fillable = ['whatsapp_id', 'profile_name', 'last_message_at'];

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}