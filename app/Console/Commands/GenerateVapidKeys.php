<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/**
 * Genera un par de claves VAPID para Web Push y las muestra para copiarlas al `.env`.
 * No escribe el `.env`: las claves se generan una sola vez por instalación (cambiarlas invalida
 * todas las suscripciones existentes).
 */
class GenerateVapidKeys extends Command
{
    protected $signature = 'pwa:vapid {--subject= : Correo o URL de contacto (mailto:… o https://…)}';

    protected $description = 'Genera las claves VAPID de las notificaciones push (copiarlas al .env).';

    public function handle(): int
    {
        try {
            $keys = VAPID::createVapidKeys();
        } catch (\Throwable $exception) {
            $this->error('OpenSSL no pudo crear las claves: '.$exception->getMessage());
            $this->line('En Windows, defina OPENSSL_CONF con la ruta de openssl.cnf (p. ej. la carpeta extras\ssl de PHP) y repita.');

            return self::FAILURE;
        }
        $subject = $this->option('subject') ?: (config('pwa.vapid.subject') ?: 'mailto:soporte@example.com');

        $this->line('Copie estas líneas al .env del servidor (una sola vez; si cambian, cada dispositivo debe volver a activar los avisos):');
        $this->newLine();
        $this->line('VAPID_SUBJECT='.$subject);
        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->newLine();
        $this->warn('La clave privada es un secreto: no la suba al repositorio.');

        return self::SUCCESS;
    }
}
