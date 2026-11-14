<?php

namespace App\Policies;

use App\Models\SigningAsset;
use App\Models\User;

/**
 * Firma y sello personales: solo su dueño los ve, reemplaza, quita o usa.
 *
 * Subir la propia firma no depende del rol (es un dato personal); aplicarla
 * sobre un documento exige además `sign_documents` en la empresa del documento
 * (ver `DocumentPolicy::sign` y `SignatureController`).
 */
class SigningAssetPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, SigningAsset $asset): bool
    {
        return (int) $asset->user_id === (int) $user->id;
    }

    public function update(User $user, SigningAsset $asset): bool
    {
        return $this->view($user, $asset);
    }

    public function delete(User $user, SigningAsset $asset): bool
    {
        return $this->view($user, $asset);
    }

    public function use(User $user, SigningAsset $asset): bool
    {
        return $this->view($user, $asset);
    }
}
