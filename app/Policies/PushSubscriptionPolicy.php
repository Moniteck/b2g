<?php

namespace App\Policies;

use App\Models\PushSubscription;
use App\Models\User;

/**
 * Suscripciones push: cada persona administra solo las de sus propios dispositivos.
 * Recibir avisos es lectura, así que también el rol «viewer» puede activarlos.
 * Se descubre por convención de nombres (sin registrarla en AppServiceProvider).
 */
class PushSubscriptionPolicy
{
    public function create(User $user): bool
    {
        return $user->exists;
    }

    public function delete(User $user, PushSubscription $subscription): bool
    {
        return $subscription->user_id === $user->id;
    }
}
