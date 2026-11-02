<?php

namespace App\Mail;

use Closure;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A document sent by email with the text written in the send dialog and its PDF attached.
 */
class DocumentMail extends Mailable
{
    use Queueable;

    /**
     * @param  (Closure(): string)|null  $pdf  Renders the PDF when the mail is built.
     */
    public function __construct(
        public string $subjectLine,
        public string $body,
        public ?Closure $pdf = null,
        public ?string $filename = null,
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
        return new Content(markdown: 'mail.document', with: ['body' => $this->body]);
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        if (! $this->pdf) {
            return [];
        }

        return [Attachment::fromData($this->pdf, $this->filename ?? 'documento.pdf')->withMime('application/pdf')];
    }
}
