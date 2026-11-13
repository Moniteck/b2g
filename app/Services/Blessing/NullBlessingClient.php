<?php

namespace App\Services\Blessing;

/**
 * Cliente de una empresa sin conexión con Blessing configurada.
 */
final class NullBlessingClient implements BlessingClient
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function source(): string
    {
        return 'ninguno';
    }

    public function products(?string $cursor = null): iterable
    {
        throw BlessingException::notConfigured();
    }
}
