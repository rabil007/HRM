<?php

namespace App\Mail;

use App\Models\UserInvitation;
use App\Support\Users\ComposeUserInvitationMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UserInvitationMail extends Mailable implements ShouldBeEncrypted
{
    use Queueable, SerializesModels;

    /**
     * @var array{
     *     subject: string,
     *     body: string,
     *     companyName: string,
     *     includeCompanyFooter: bool,
     * }|null
     */
    private ?array $composed = null;

    public function __construct(
        public UserInvitation $invitation,
        public string $token
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->composed()['subject'],
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $composed = $this->composed();

        return new Content(
            view: 'mail.bulk-document',
            with: [
                'subjectLine' => $composed['subject'],
                'bodyMessage' => $composed['body'],
                'organizationName' => $composed['companyName'],
                'includeCompanyFooter' => $composed['includeCompanyFooter'],
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [];
    }

    /**
     * @return array{
     *     subject: string,
     *     body: string,
     *     companyName: string,
     *     includeCompanyFooter: bool,
     * }
     */
    private function composed(): array
    {
        return $this->composed ??= app(ComposeUserInvitationMail::class)->handle(
            $this->invitation,
            $this->token,
        );
    }
}
