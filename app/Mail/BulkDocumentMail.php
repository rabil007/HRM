<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BulkDocumentMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  list<string>  $ccRecipients
     */
    public function __construct(
        public string $subjectLine,
        public string $bodyMessage,
        public string $organizationName,
        public string $attachmentPath,
        public string $attachmentName,
        public bool $includeCompanyFooter = true,
        public array $ccRecipients = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine,
            cc: $this->ccRecipients,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.bulk-document',
            with: [
                'subjectLine' => $this->subjectLine,
                'bodyMessage' => $this->bodyMessage,
                'organizationName' => $this->organizationName,
                'includeCompanyFooter' => $this->includeCompanyFooter,
            ],
        );
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromStorageDisk('public', $this->attachmentPath)
                ->as($this->attachmentName)
                ->withMime('application/pdf'),
        ];
    }
}
