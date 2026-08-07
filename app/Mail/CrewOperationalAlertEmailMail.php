<?php

namespace App\Mail;

use App\Jobs\DeliverCrewOperationalAlertEmailJob;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Privacy-safe Crew operational alert email.
 *
 * Sent from {@see DeliverCrewOperationalAlertEmailJob} — not independently queued.
 */
class CrewOperationalAlertEmailMail extends Mailable
{
    use SerializesModels;

    public function __construct(
        public string $organizationName,
        public string $severityLabel,
        public ?string $ctaUrl,
        public bool $includeCompanyFooter = true,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Crew Operations requires attention',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.crew-operational-alert',
        );
    }
}
