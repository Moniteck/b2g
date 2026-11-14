<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Resumen diario de plazos (hoy y próximos 7 días) de una empresa para un usuario.
 */
class DailyDigestMail extends Mailable
{
    use Queueable;

    /**
     * @param  array<int, array{label: string, today: bool, items: array<int, array<string, string>>}>  $groups
     */
    public function __construct(
        public string $userName,
        public ?string $companyName,
        public array $groups,
        public int $total,
        public string $dateLabel,
    ) {}

    public function subjectLine(): string
    {
        $today = collect($this->groups)->firstWhere('today', true);
        $todayCount = $today ? count($today['items']) : 0;

        return $todayCount > 0
            ? "Hoy tienes {$todayCount} ".($todayCount === 1 ? 'plazo' : 'plazos')." y {$this->total} en la semana".($this->companyName ? " · {$this->companyName}" : '')
            : "Plazos de los próximos 7 días: {$this->total}".($this->companyName ? " · {$this->companyName}" : '');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine());
    }

    public function content(): Content
    {
        return new Content(view: 'mail.daily-digest', with: [
            'subjectLine' => $this->subjectLine(),
            'calendarUrl' => url('/calendario'),
        ]);
    }
}
