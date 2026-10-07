<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RequirementTargetDateReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  list<array{label: string, value: string}>  $details
     */
    public function __construct(
        public string $subjectLine,
        public string $organizationName,
        public string $requirementNumber,
        public string $heading,
        public string $intro,
        public array $details,
        public string $requirementUrl,
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
            view: 'mail.requirement-target-date-reminder',
        );
    }
}
