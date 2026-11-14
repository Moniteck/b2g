<?php

namespace App\Services\Blessing;

use RuntimeException;

/**
 * Error del conector de Blessing. El mensaje se muestra al usuario: va en
 * español y nunca incluye el token ni la respuesta completa de la API.
 */
class BlessingException extends RuntimeException
{
    public static function notConfigured(): self
    {
        return new self('La conexión con Blessing está pendiente de configurar para esta empresa.');
    }

    public static function alreadyRunning(): self
    {
        return new self('Ya hay una sincronización en curso para esta empresa. Espera a que termine.', 409);
    }
}
