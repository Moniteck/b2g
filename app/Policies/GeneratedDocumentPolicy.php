<?php

namespace App\Policies;

use App\Models\GeneratedDocument;
use App\Models\User;
use App\Policies\Concerns\HandlesCompanyScope;
use App\Support\Permissions;

/**
 * Documentos del Generador: los genera quien tiene `generate_documents`; cada
 * autor ve los suyos y el administrador de la empresa ve y elimina todos.
 */
class GeneratedDocumentPolicy
{
    use HandlesCompanyScope;

    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::GENERATE_DOCUMENTS);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::GENERATE_DOCUMENTS);
    }

    public function view(User $user, GeneratedDocument $document): bool
    {
        if (! $this->hasCompanyAccess($user, $document->company_id)) {
            return false;
        }

        return (int) $document->user_id === (int) $user->id || $this->isCompanyAdmin($user, $document->company_id);
    }

    public function delete(User $user, GeneratedDocument $document): bool
    {
        return $this->allowsOn($user, $document, Permissions::DELETE);
    }
}
