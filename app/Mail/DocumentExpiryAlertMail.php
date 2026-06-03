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

    public const SUBJECT = 'Document Expiry Alert - Next 30 Days';

    /**
     * @param  list<array{employee_name: string, employee_id: string, document_name: string, expiry_date: string, days_remaining: int}>  $rows
     */
    public function __construct(
        public string $organizationName,
        public array $rows,
        public int $alertWindowDays,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: self::SUBJECT,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.document-expiry-alert',
        );
    }
}
