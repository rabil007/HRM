<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Document recipient action request email.
 *
 * Sent from DeliverDocumentRecipientRequestEmailJob — not independently queued.
 * No PDF attachments.
 */
class DocumentRecipientRequestActionMail extends Mailable
{
    use SerializesModels;

    public function __construct(
        public string $organizationName = '',
        public string $subjectLine = 'Document action required',
        public string $bodyHtml = '',
        public bool $includeCompanyFooter = true,
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
            view: 'mail.document-recipient-action-request',
            with: [
                'subjectLine' => $this->subjectLine,
                'bodyHtml' => $this->bodyHtml,
                'organizationName' => $this->organizationName,
                'includeCompanyFooter' => $this->includeCompanyFooter,
            ],
        );
    }
}
