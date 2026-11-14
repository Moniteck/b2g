<?php

namespace App\Policies;

use App\Models\Quote;
use App\Models\User;
use App\Support\Permissions;

/**
 * Cotizaciones.
 *
 *   ver y descargar                     admin, operator, viewer
 *   crear, editar, adjuntar, firmar,
 *   participantes, Ganada/Perdida, correo   admin, operator
 *   eliminar (solo Borrador), revertir  admin
 */
class QuotePolicy extends OperationalPolicy
{
    /**
     * Una cotización presentada ya no se edita: se sustituye por una revisión.
     */
    public function update(User $user, object $model): bool
    {
        return parent::update($user, $model) && $model->isEditable();
    }

    public function delete(User $user, object $model): bool
    {
        return parent::delete($user, $model) && $model->status === 'Borrador' && $model->isPending();
    }

    /** Cambiar el estado documental (Borrador → Emitida → Presentada, Sustituida, Anulada). */
    public function changeStatus(User $user, Quote $quote): bool
    {
        return $this->allowsOn($user, $quote, Permissions::UPDATE) && $quote->nextStatuses() !== [];
    }

    /**
     * Subir o quitar PDF adjuntos: también en Presentada (la usa `Attachables`).
     */
    public function manageAttachments(User $user, Quote $quote): bool
    {
        return $this->allowsOn($user, $quote, Permissions::UPDATE) && $quote->acceptsAttachments();
    }

    /** Participantes: permitido en Presentada y después del resultado (para marcar el ganador). */
    public function manageParticipants(User $user, Quote $quote): bool
    {
        return $this->allowsOn($user, $quote, Permissions::UPDATE) && $quote->status !== 'Anulada';
    }

    /** Enviar por correo. */
    public function send(User $user, Quote $quote): bool
    {
        return $this->allowsOn($user, $quote, Permissions::UPDATE) && $quote->status !== 'Anulada';
    }

    /**
     * Registrar el resultado comercial. El estado (Pendiente, Emitida o Presentada) lo valida
     * AdjudicationService, para que repetir la acción responda igual en vez de dar 403.
     */
    public function adjudicate(User $user, Quote $quote): bool
    {
        return $this->allowsOn($user, $quote, Permissions::ADJUDICATE);
    }

    public function revertOutcome(User $user, Quote $quote): bool
    {
        return $this->allowsOn($user, $quote, Permissions::REVERT_OUTCOME);
    }
}
