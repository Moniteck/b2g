<?php

namespace App\Policies;

use App\Models\Contract;
use App\Models\User;
use App\Support\Permissions;

/**
 * viewer ve y descarga; operator crea, edita, registra entregas y seguimientos, sube documentos y
 * firma; admin además cierra, cancela, reabre y elimina, y administra los feriados.
 */
class ContractPolicy extends OperationalPolicy
{
    /**
     * Registrar seguimiento y entregas del contrato.
     */
    public function followUp(User $user, Contract $contract): bool
    {
        return $this->allowsOn($user, $contract, Permissions::UPDATE);
    }

    /**
     * Cerrar el contrato es una decisión administrativa de la empresa.
     */
    public function close(User $user, Contract $contract): bool
    {
        return $this->isCompanyAdmin($user, $contract->company_id);
    }

    /** Cancelar (y reabrir un cerrado) también es del administrador. */
    public function cancel(User $user, Contract $contract): bool
    {
        return $this->isCompanyAdmin($user, $contract->company_id);
    }

    /** Feriados de la empresa activa: solo el administrador. */
    public function manageHolidays(User $user): bool
    {
        return $this->allowsInActiveCompany($user, Permissions::DELETE);
    }
}
