<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\User\StoreUserInvitationRequest;
use App\Models\UserInvitation;
use App\Support\Users\InviteUser;

class UserInvitationController extends Controller
{
    public function store(StoreUserInvitationRequest $request, InviteUser $inviteUser)
    {
        $companyId = request()->attributes->get('current_company_id');

        $inviteUser->execute($request->validated(), $companyId, $request->user()->id);

        return back()->with('success', 'Invitation sent successfully.');
    }

    public function resend(UserInvitation $invitation, InviteUser $inviteUser)
    {
        $this->authorize('users.create');

        $companyId = request()->attributes->get('current_company_id');
        abort_if($invitation->company_id !== $companyId, 404);

        try {
            $inviteUser->resend($invitation);

            return back()->with('success', 'Invitation resent successfully.');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function destroy(UserInvitation $invitation)
    {
        $this->authorize('users.delete');

        $companyId = request()->attributes->get('current_company_id');
        abort_if($invitation->company_id !== $companyId, 404);

        $invitation->update(['revoked_at' => now()]);

        return back()->with('success', 'Invitation revoked successfully.');
    }
}
