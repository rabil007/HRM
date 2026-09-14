<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DocumentExpiryAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  list<array{employee_name: string, employee_id: string, document_name: string, expiry_date: string, days_remaining: int, folder_url: string}>  $rows
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
            subject: "Employee Document Expiry Alert — {$count} document(s) require attention",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.document-expiry-alert',
        );
    }
}
