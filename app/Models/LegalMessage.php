<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LegalMessage extends Model
{
    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    protected $fillable = ['legal_conversation_id', 'role', 'content'];

    public function conversation()
    {
        return $this->belongsTo(LegalConversation::class, 'legal_conversation_id');
    }
}
