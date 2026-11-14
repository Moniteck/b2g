<?php

namespace App\Policies;

use App\Models\LegalConversation;
use App\Models\User;
use App\Support\Permissions;

/**
 * Las conversaciones con el Agente Legal son personales: solo su autor las ve,
 * las continúa y las elimina, y solo mientras tenga permiso de usar la IA.
 */
class LegalConversationPolicy
{
    public function view(User $user, LegalConversation $conversation): bool
    {
        return (int) $conversation->user_id === (int) $user->id && $user->hasPermission(Permissions::USE_AI);
    }

    public function update(User $user, LegalConversation $conversation): bool
    {
        return $this->view($user, $conversation);
    }

    public function delete(User $user, LegalConversation $conversation): bool
    {
        return $this->view($user, $conversation);
    }
}
