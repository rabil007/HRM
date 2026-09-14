<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CompanyDocumentExpiryAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  list<array{document_name: string, document_number: string|null, expiry_date: string, days_remaining: int, view_url: string}>  $rows
     */
    public function __construct(
        public string $organizationName,
        public array $rows,
        public int $alertWindowDays,
        public bool $includeCompanyFooter = true,
        public ?string $complianceUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        $count = count($this->rows);

        return new Envelope(
            subject: "Company Document Expiry Alert — {$count} document(s) require attention",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.company-document-expiry-alert',
        );
    }
}
