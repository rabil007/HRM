<?php

namespace App\Support\Users;

use App\Mail\UserInvitationMail;
use App\Models\UserInvitation;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class InviteUser
{
    public function execute(array $data, int $companyId, int $inviterId): UserInvitation
    {
        // Cancel any pending invitations for this email in this company
        UserInvitation::where('company_id', $companyId)
            ->where('email', $data['email'])
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        $token = Str::random(40);

        $invitation = UserInvitation::create([
            'company_id' => $companyId,
            'email' => $data['email'],
            'name' => $data['name'] ?? null,
            'role_id' => $data['role_id'] ?? null,
            'employee_id' => $data['employee_id'] ?? null,
            'invited_by' => $inviterId,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(7),
        ]);

        $this->sendInvitationEmail($invitation, $token);

        return $invitation;
    }

    public function resend(UserInvitation $invitation): void
    {
        if ($invitation->accepted_at || $invitation->revoked_at || $invitation->expires_at->isPast()) {
            throw new \Exception('Cannot resend this invitation.');
        }

        // When resending, we create a new token to ensure they have a valid link,
        // and we extend the expiration by another 7 days
        $token = Str::random(40);

        $invitation->update([
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(7),
        ]);

        $this->sendInvitationEmail($invitation, $token);
    }

    protected function sendInvitationEmail(UserInvitation $invitation, string $token): void
    {
        Mail::to($invitation->email)->queue(new UserInvitationMail($invitation, $token));

        $invitation->update([
            'last_sent_at' => now(),
        ]);
    }
}
