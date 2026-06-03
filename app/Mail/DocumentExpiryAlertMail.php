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
     * @param  list<array{employee_name: string, documents: list<array{document_name: string, document_type: string, expiry_date: string, remaining_days: int}>}>  $employeeGroups
     */
    public function __construct(
        public string $organizationName,
        public string $subjectLine,
        public string $introMessage,
        public array $employeeGroups,
        public int $alertWindowDays,
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
            view: 'mail.document-expiry-alert',
        );
    }
}
