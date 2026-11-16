<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * «Crea una contraseña nueva»: enlace con token para restablecer la contraseña (vence en
 * `$minutes` minutos). Con la identidad del sistema (mail.layout).
 */
class PasswordResetMail extends Mailable
{
    use Queueable;

    public function __construct(public string $name, public string $url, public int $minutes) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Crea una contraseña nueva para el CRM');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.password-reset');
    }
}
