<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CrewMovementCorrectionDecidedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subjectLine,
        public string $organizationName,
        public string $assignmentNo,
        public string $employeeName,
        public string $phaseLabel,
        public string $status,
        public string $reason,
        public ?string $decisionNotes,
        public string $correctionUrl,
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
            view: 'mail.crew-movement-correction-decided',
        );
    }
}
