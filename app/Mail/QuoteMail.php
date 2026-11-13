<?php
namespace App\Mail;
use App\Models\Quote;
use App\Services\QuoteDocumentService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
class QuoteMail extends Mailable
{
    use Queueable;
    public function __construct(public Quote $quote) {}
    public function envelope(): Envelope { return new Envelope(subject: "Cotización {$this->quote->number}"); }
    public function content(): Content { return new Content(view: 'mail.quote'); }
    public function attachments(): array { return [Attachment::fromData(fn () => app(QuoteDocumentService::class)->pdf($this->quote), "{$this->quote->number}.pdf")->withMime('application/pdf')]; }
}
