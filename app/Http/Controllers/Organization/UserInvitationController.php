<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\User\StoreUserInvitationRequest;
use App\Models\UserInvitation;
use App\Support\Users\InviteUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserInvitationController extends Controller
{
    public function store(StoreUserInvitationRequest $request, InviteUser $inviteUser)
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        $inviteUser->execute($request->validated(), $companyId, (int) $request->user()->id);

        return back()->with('success', 'Invitation sent successfully.');
    }

    public function resend(Request $request, UserInvitation $invitation, InviteUser $inviteUser)
    {
        abort_unless($request->user()?->can('users.create'), 403);

        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $invitation->company_id === $companyId, 404);

        try {
            $inviteUser->resend($invitation);

            return back()->with('success', 'Invitation resent successfully.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function destroy(Request $request, UserInvitation $invitation)
    {
        abort_unless($request->user()?->can('users.delete'), 403);

        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $invitation->company_id === $companyId, 404);

        try {
            DB::transaction(function () use ($invitation, $companyId, $request): void {
                /** @var UserInvitation $locked */
                $locked = UserInvitation::where('id', $invitation->id)->lockForUpdate()->firstOrFail();

                // Already accepted invitations must not be revoked
                if ($locked->accepted_at !== null) {
                    throw new \DomainException('Cannot revoke an already accepted invitation.');
                }

                if ($locked->revoked_at !== null) {
                    // Already revoked — idempotent, nothing to do
                    return;
                }

                $locked->update(['revoked_at' => now()]);

                activity()
                    ->causedBy($request->user())
                    ->performedOn($locked)
                    ->withProperties([
                        'company_id' => $companyId,
                        'email' => $locked->email,
                    ])
                    ->tap(function ($activity) use ($companyId): void {
                        $activity->company_id = $companyId;
                    })
                    ->log('revoked user invitation');
            });
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Invitation revoked successfully.');
    }
}
