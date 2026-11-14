<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;
use App\Support\Permissions;

class DocumentPolicy extends OperationalPolicy
{
    /**
     * Aplicar firma y sello sobre un documento.
     */
    public function sign(User $user, Document $document): bool
    {
        return $this->allowsOn($user, $document, Permissions::SIGN_DOCUMENTS);
    }

    /**
     * Descarga de archivos: mismo permiso que la lectura del expediente.
     */
    public function download(User $user, Document $document): bool
    {
        return $this->view($user, $document);
    }
}
