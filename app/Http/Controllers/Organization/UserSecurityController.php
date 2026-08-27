<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Auth\InvalidateUserSessions;
use App\Support\Users\GlobalIdentityAccessGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

class UserSecurityController extends Controller
{
    public function sendPasswordResetLink(Request $request, User $user)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        GlobalIdentityAccessGuard::check($user, $companyId);

        $status = Password::broker()->sendResetLink(
            ['email' => $user->email]
        );

        if ($status === Password::RESET_LINK_SENT) {
            activity()
                ->causedBy($request->user())
                ->performedOn($user)
                ->withProperties([
                    'company_id' => $companyId,
                    'target_user_id' => $user->id,
                    'email' => $user->email,
                ])
                ->tap(function ($activity) use ($companyId): void {
                    $activity->company_id = $companyId;
                })
                ->log('sent admin password reset link');

            return back()->with('status', 'Password reset link sent to '.$user->email.'.');
        }

        return back()->with('error', 'Unable to send password reset link. Please try again.');
    }

    public function revokeSessions(Request $request, User $user)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        GlobalIdentityAccessGuard::check($user, $companyId);

        app(InvalidateUserSessions::class)->handle($user, keepCurrentSession: false);

        activity()
            ->causedBy($request->user())
            ->performedOn($user)
            ->withProperties([
                'company_id' => $companyId,
                'target_user_id' => $user->id,
            ])
            ->tap(function ($activity) use ($companyId): void {
                $activity->company_id = $companyId;
            })
            ->log('revoked user sessions');

        return back()->with('status', 'User sessions have been revoked.');
    }
}
