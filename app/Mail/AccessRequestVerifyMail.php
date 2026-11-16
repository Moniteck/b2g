<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A quien pidió acceso: «Confirma tu correo». El enlace es firmado y vence en 24 horas; hasta
 * confirmarlo, la solicitud no llega a los administradores.
 */
class AccessRequestVerifyMail extends Mailable
{
    use Queueable;

    public function __construct(public string $applicant, public string $url, public int $hours) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirma tu correo para completar la solicitud de acceso');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.access-request-verify');
    }
}
