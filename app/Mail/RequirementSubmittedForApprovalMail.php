<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RequirementSubmittedForApprovalMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  list<array{label: string, value: string}>  $details
     */
    public function __construct(
        public string $subjectLine,
        public string $organizationName,
        public string $requirementNumber,
        public string $requesterName,
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
            view: 'mail.requirement-submitted-for-approval',
        );
    }
}
