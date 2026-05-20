<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DocumentsSharedMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  list<array{name: string, size_bytes: int, mime_type: string}>  $attachmentSummaries
     * @param  list<array{path: string, name: string, mime: string}>  $fileAttachments
     */
    public function __construct(
        public string $organizationName,
        public string $senderName,
        public string $subjectLine,
        public ?string $bodyMessage,
        public array $attachmentSummaries,
        private array $fileAttachments,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.documents-shared',
        );
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return array_map(
            fn (array $attachment) => Attachment::fromPath($attachment['path'])
                ->as($attachment['name'])
                ->withMime($attachment['mime']),
            $this->fileAttachments,
        );
    }
}
