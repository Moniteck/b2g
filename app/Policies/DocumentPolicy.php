<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;
use App\Policies\Concerns\HandlesCompanyScope;

class DocumentPolicy
{
    use HandlesCompanyScope;

    public function viewAny(User $user): bool
    {
        return $user->isRoot() || $user->companies()->exists();
    }

    public function view(User $user, Document $document): bool
    {
        return $this->hasCompanyAccess($user, $document->company_id);
    }

    public function create(User $user): bool
    {
        return $user->isRoot() || $user->companies()->exists();
    }

    public function update(User $user, Document $document): bool
    {
        return $this->canOperate($user, $document);
    }

    public function delete(User $user, Document $document): bool
    {
        return $this->isCompanyAdmin($user, $document->company_id);
    }

    /**
     * Aplicar firma y sello sobre un documento.
     */
    public function sign(User $user, Document $document): bool
    {
        return $this->canOperate($user, $document);
    }

    /**
     * Descarga de archivos: mismo permiso que la lectura del expediente.
     */
    public function download(User $user, Document $document): bool
    {
        return $this->view($user, $document);
    }
}
