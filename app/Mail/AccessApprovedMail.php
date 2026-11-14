<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Aviso a la persona: su solicitud fue aprobada y ya puede entrar con la contraseña que eligió.
 */
class AccessApprovedMail extends Mailable
{
    use Queueable;

    public function __construct(public string $person, public string $companyName, public string $roleLabel) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Tu acceso al CRM B2G fue aprobado');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.access-approved', with: ['url' => url('/login')]);
    }
}
