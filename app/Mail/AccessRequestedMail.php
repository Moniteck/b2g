<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Aviso a un administrador: «Nueva solicitud de acceso: Ana Pérez».
 */
class AccessRequestedMail extends Mailable
{
    use Queueable;

    public function __construct(public string $applicant, public string $email, public string $position, public string $where) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Nueva solicitud de acceso: {$this->applicant}");
    }

    public function content(): Content
    {
        return new Content(view: 'mail.access-requested', with: ['url' => url('/configuracion/usuarios#solicitudes')]);
    }
}
