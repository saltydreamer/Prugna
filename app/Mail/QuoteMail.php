<?php

namespace App\Mail;

use App\Models\Quote;
use App\Support\QuotePdf;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Quote sent to the customer: text written in the send dialog, PDF attached, link to accept online.
 */
class QuoteMail extends Mailable
{
    use Queueable;

    public function __construct(
        public Quote $quote,
        public string $subjectLine,
        public string $body,
        public bool $attachPdf = true,
        public ?string $replyToAddress = null,
        public ?string $replyToName = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine,
            replyTo: $this->replyToAddress ? [new Address($this->replyToAddress, $this->replyToName)] : [],
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.quote', with: [
            'body' => $this->body,
            'url' => $this->quote->publicUrl(),
        ]);
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        if (! $this->attachPdf) {
            return [];
        }

        $pdf = app(QuotePdf::class);

        return [
            Attachment::fromData(fn () => $pdf->make($this->quote)->output(), $pdf->filename($this->quote))->withMime('application/pdf'),
        ];
    }
}
