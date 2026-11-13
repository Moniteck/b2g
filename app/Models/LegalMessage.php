<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class LegalMessage extends Model { protected $fillable = ['role', 'content']; public function conversation() { return $this->belongsTo(LegalConversation::class, 'legal_conversation_id'); } }
