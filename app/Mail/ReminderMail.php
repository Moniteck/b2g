<?php

namespace App\Mail;

use App\Models\Company;
use App\Models\Event;
use App\Models\ReminderDelivery;
use App\Services\Notifications\NotificationFeed;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Correo de un recordatorio: «Mañana cierra el plazo de aclaraciones: LPN-007-2026».
 */
class ReminderMail extends Mailable
{
    use Queueable;

    public function __construct(public ReminderDelivery $delivery, public Event $event) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine());
    }

    public function subjectLine(): string
    {
        $dueAt = $this->delivery->due_at ?? $this->event->starts_at;

        return NotificationFeed::headline($this->event, $dueAt).': '.($this->event->label ?: $this->event->title);
    }

    public function content(): Content
    {
        $dueAt = $this->delivery->due_at ?? $this->event->starts_at;
        $company = $this->event->company_id
            ? Company::query()->withoutGlobalScopes()->whereKey($this->event->company_id)->value('name')
            : null;

        return new Content(
            view: 'mail.reminder',
            with: [
                'heading' => NotificationFeed::headline($this->event, $dueAt),
                'event' => $this->event,
                'typeLabel' => $this->event->typeLabel(),
                'when' => NotificationFeed::when($this->event, $dueAt),
                'companyName' => $company,
                'url' => url($this->event->sourceHref()),
                'sourceLabel' => $this->event->sourceLabel(),
                'offsetLabel' => $this->delivery->offsetLabel(),
            ],
        );
    }
}
