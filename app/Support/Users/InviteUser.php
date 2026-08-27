<?php

namespace App\Support\Users;

use App\Mail\UserInvitationMail;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Support\Facades\DB;
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

        activity()
            ->causedBy($inviterId ? User::find($inviterId) : null)
            ->performedOn($invitation)
            ->withProperties([
                'company_id' => $companyId,
                'email' => $invitation->email,
                'role_id' => $invitation->role_id,
            ])
            ->tap(function ($activity) use ($companyId): void {
                $activity->company_id = $companyId;
            })
            ->log('sent user invitation');

        return $invitation;
    }

    public function resend(UserInvitation $invitation): void
    {
        $token = DB::transaction(function () use ($invitation): string {
            /** @var UserInvitation $locked */
            $locked = UserInvitation::where('id', $invitation->id)->lockForUpdate()->firstOrFail();

            if ($locked->accepted_at !== null || $locked->revoked_at !== null) {
                throw new \DomainException('Cannot resend an accepted or revoked invitation.');
            }

            // Issue a fresh cryptographically random token (invalidating previous token)
            // and extend expiration by 7 days.
            $newToken = Str::random(40);

            $locked->update([
                'token_hash' => hash('sha256', $newToken),
                'expires_at' => now()->addDays(7),
            ]);

            return $newToken;
        });

        $this->sendInvitationEmail($invitation->fresh(), $token);

        activity()
            ->causedBy(auth()->user())
            ->performedOn($invitation)
            ->withProperties([
                'company_id' => $invitation->company_id,
                'email' => $invitation->email,
            ])
            ->tap(function ($activity) use ($invitation): void {
                $activity->company_id = $invitation->company_id;
            })
            ->log('resent user invitation');
    }

    protected function sendInvitationEmail(UserInvitation $invitation, string $token): void
    {
        Mail::to($invitation->email)->queue(new UserInvitationMail($invitation, $token));

        $invitation->update([
            'last_sent_at' => now(),
        ]);
    }
}
