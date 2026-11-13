<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Conversación personal con el Agente Legal: solo la ve y la continúa su autor.
 */
class LegalConversation extends Model
{
    protected $fillable = ['user_id', 'title'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function messages()
    {
        return $this->hasMany(LegalMessage::class);
    }
}
