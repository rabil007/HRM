<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Users\GlobalIdentityAccessGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class UserSecurityController extends Controller
{
    public function sendPasswordResetLink(Request $request, User $user)
    {
        $companyId = $request->attributes->get('current_company_id');
        GlobalIdentityAccessGuard::check($user, $companyId);

        $status = Password::broker()->sendResetLink(
            ['email' => $user->email]
        );

        return back()->with('status', $status === Password::RESET_LINK_SENT
            ? 'Password reset link sent to '.$user->email.'.'
            : 'Unable to send password reset link.');
    }

    public function revokeSessions(Request $request, User $user)
    {
        $companyId = $request->attributes->get('current_company_id');
        GlobalIdentityAccessGuard::check($user, $companyId);

        $user->forceFill([
            'remember_token' => Str::random(60),
        ])->save();

        DB::table('sessions')->where('user_id', $user->id)->delete();

        return back()->with('status', 'All active sessions for this user have been revoked.');
    }
}
