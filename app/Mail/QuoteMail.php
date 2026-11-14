<?php

namespace App\Mail;

use App\Models\Quote;
use App\Services\QuoteDocumentService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Cotización en PDF adjunto, con mensaje opcional de quien la envía. */
class QuoteMail extends Mailable
{
    use Queueable;

    public function __construct(
        public Quote $quote,
        public ?string $body = null,
        public ?string $customSubject = null,
        public ?string $sender = null,
        public ?string $replyToAddress = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->customSubject ?: "Cotización {$this->quote->number}".($this->quote->company ? " · {$this->quote->company->name}" : ''),
            replyTo: $this->replyToAddress ? [new Address($this->replyToAddress, $this->sender)] : [],
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.quote', with: [
            'body' => $this->body,
            'sender' => $this->sender ?? config('design.company.name'),
        ]);
    }

    public function attachments(): array
    {
        return [Attachment::fromData(fn () => app(QuoteDocumentService::class)->pdf($this->quote), "{$this->quote->number}.pdf")->withMime('application/pdf')];
    }
}
