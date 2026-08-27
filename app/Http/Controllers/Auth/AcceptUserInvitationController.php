<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Models\UserInvitation;
use App\Support\Users\UserMembershipAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

class AcceptUserInvitationController extends Controller
{
    public function show(Request $request)
    {
        $token = $request->query('token');
        if (! $token) {
            abort(404);
        }

        $invitation = UserInvitation::where('token_hash', hash('sha256', $token))->firstOrFail();

        if ($invitation->expires_at->isPast() || $invitation->accepted_at || $invitation->revoked_at) {
            return redirect()->route('login')->with('status', 'This invitation is invalid or has expired.');
        }

        $userExists = User::where('email', $invitation->email)->exists();

        return Inertia::render('auth/accept-invitation', [
            'invitation' => [
                'email' => $invitation->email,
                'name' => $invitation->name,
                'company' => $invitation->company->name,
            ],
            'token' => $token,
            'userExists' => $userExists,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'token' => ['required', 'string'],
            'password' => ['nullable', 'confirmed', Password::defaults()],
        ]);

        $invitation = UserInvitation::where('token_hash', hash('sha256', $request->input('token')))->firstOrFail();

        if ($invitation->expires_at->isPast() || $invitation->accepted_at || $invitation->revoked_at) {
            return redirect()->route('login')->with('status', 'This invitation is invalid or has expired.');
        }

        $user = User::where('email', $invitation->email)->first();

        if (! $user) {
            $request->validate([
                'password' => ['required', 'confirmed', Password::defaults()],
                'name' => ['required', 'string', 'max:255'],
            ]);
        }

        DB::transaction(function () use ($request, $invitation, &$user) {
            $lockedInvitation = UserInvitation::where('id', $invitation->id)->lockForUpdate()->first();

            if ($lockedInvitation->expires_at->isPast() || $lockedInvitation->accepted_at || $lockedInvitation->revoked_at) {
                return redirect()->route('login')->with('status', 'This invitation is invalid or has expired.');
            }

            if (! $user) {
                // Ensure duplicate email is not created concurrently
                $user = User::where('email', $invitation->email)->lockForUpdate()->first();
                if (! $user) {
                    $user = User::create([
                        'name' => $request->input('name') ?? $invitation->name,
                        'email' => $invitation->email,
                        'password' => Hash::make($request->input('password')),
                        'company_id' => $invitation->company_id, // Default company
                        'status' => 'active',
                    ]);
                }
            }

            // Link user to the company
            $membershipAccess = app(UserMembershipAccess::class);
            $company = Company::find($invitation->company_id);
            $membershipAccess->linkToCompany($user, $company);

            if ($invitation->role_id) {
                UserMembershipAccess::syncRole($user, $invitation->company_id, $invitation->role_id);
            }

            if ($invitation->employee_id) {
                Employee::where('id', $invitation->employee_id)
                    ->where('company_id', $invitation->company_id)
                    ->whereNull('user_id')
                    ->update(['user_id' => $user->id]);
            }

            $lockedInvitation->update([
                'accepted_at' => now(),
            ]);
        });

        Auth::login($user);

        return redirect()->route('dashboard');
    }
}
