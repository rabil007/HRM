<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class FailedQueueJobMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $jobName,
        public string $queueName,
        public string $queueConnection,
        public string $exceptionSummary,
        public string $exceptionDetails,
        public ?string $jobUuid = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Queue job failed: {$this->jobName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.failed-queue-job',
        );
    }
}
