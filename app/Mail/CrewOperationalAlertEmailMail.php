<?php

namespace App\Mail;

use App\Jobs\DeliverCrewOperationalAlertEmailJob;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Consolidated Crew operational alert digest email using EmailTemplate.
 *
 * Sent from {@see DeliverCrewOperationalAlertEmailJob} — not independently queued.
 */
class CrewOperationalAlertEmailMail extends Mailable
{
    use SerializesModels;

    public function __construct(
        public string $organizationName = '',
        public string $severityLabel = 'warning',
        public ?string $ctaUrl = null,
        public bool $includeCompanyFooter = true,
        public string $subjectLine = 'Crew Operations requires attention',
        public string $bodyHtml = '',
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
            view: 'mail.crew-operational-alert-digest',
            with: [
                'subjectLine' => $this->subjectLine,
                'bodyHtml' => $this->bodyHtml,
                'organizationName' => $this->organizationName,
                'includeCompanyFooter' => $this->includeCompanyFooter,
            ],
        );
    }
}
