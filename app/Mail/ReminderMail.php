<?php

namespace App\Mail;

use App\Models\Event;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Event $event) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[Recordatorio] '.$this->event->title,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.reminder',
            with: [
                'event' => $this->event,
                'startsAt' => $this->event->starts_at?->toDateTimeString(),
                'company' => $this->event->company?->name,
            ],
        );
    }
}
