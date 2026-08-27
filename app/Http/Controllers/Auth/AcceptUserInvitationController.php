<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Models\UserInvitation;
use App\Support\Auth\UserEmailIdentity;
use App\Support\Users\UserMembershipAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class AcceptUserInvitationController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        $token = (string) $request->query('token');
        if ($token === '') {
            abort(404);
        }

        $invitation = UserInvitation::with(['company', 'role'])
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if (! $invitation || $invitation->isInvalidOrExpired()) {
            return redirect()->route('login')->with('status', 'This invitation is invalid or has expired.');
        }

        $emailIdentity = new UserEmailIdentity;
        $userExists = $emailIdentity->matchingNonDeleted($invitation->email)->exists();

        $currentUser = $request->user();
        $isAuthenticated = $currentUser !== null;
        $isMatchingUser = false;

        if ($isAuthenticated) {
            $isMatchingUser = UserEmailIdentity::normalize($currentUser->email) === UserEmailIdentity::normalize($invitation->email);
        } elseif ($userExists) {
            $request->session()->put('url.intended', route('invitations.accept', ['token' => $token]));
        }

        return Inertia::render('auth/accept-invitation', [
            'invitation' => [
                'email' => $invitation->email,
                'name' => $invitation->name,
                'company' => $invitation->company->name,
            ],
            'token' => $token,
            'userExists' => $userExists,
            'isAuthenticated' => $isAuthenticated,
            'isMatchingUser' => $isMatchingUser,
            'currentUserEmail' => $currentUser?->email,
        ]);
    }

    public function store(Request $request)
    {
        $token = (string) $request->input('token');
        if ($token === '') {
            abort(404);
        }

        $invitation = UserInvitation::with('company')
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if (! $invitation || $invitation->isInvalidOrExpired()) {
            return redirect()->route('login')->with('status', 'This invitation is invalid or has expired.');
        }

        $emailIdentity = new UserEmailIdentity;
        $normalizedEmail = UserEmailIdentity::normalize($invitation->email);
        $userExists = $emailIdentity->matchingNonDeleted($normalizedEmail)->exists();

        if ($userExists) {
            // SECURITY BLOCKER 1: Existing global users MUST NOT bypass authentication or 2FA.
            if (! Auth::check()) {
                $request->session()->put('url.intended', route('invitations.accept', ['token' => $token]));

                return redirect()->route('login')->with('status', 'Please sign in to accept this invitation.');
            }

            $currentUser = $request->user();
            if (UserEmailIdentity::normalize($currentUser->email) !== $normalizedEmail) {
                abort(403, 'Authenticated user does not match the invitation email.');
            }
        } else {
            $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'password' => ['required', 'confirmed', Password::defaults()],
            ]);
        }

        try {
            $user = DB::transaction(function () use ($request, $invitation, $userExists, $normalizedEmail) {
                // SECURITY BLOCKER 2: Lock invitation and fail-fast with an exception if state is invalid!
                $lockedInvitation = UserInvitation::where('id', $invitation->id)->lockForUpdate()->first();

                if (! $lockedInvitation || $lockedInvitation->isInvalidOrExpired()) {
                    throw new \DomainException('This invitation is invalid or has expired.');
                }

                if ($userExists) {
                    $targetUser = Auth::user();
                } else {
                    $existing = User::whereRaw('LOWER(email) = ?', [$normalizedEmail])->lockForUpdate()->first();
                    if ($existing) {
                        throw new \DomainException('An account with this email was just created. Please sign in to accept the invitation.');
                    }

                    $targetUser = User::create([
                        'name' => (string) $request->input('name', $lockedInvitation->name ?? 'User'),
                        'email' => $lockedInvitation->email,
                        'password' => Hash::make((string) $request->input('password')),
                        'company_id' => $lockedInvitation->company_id,
                        'status' => 'active',
                    ]);
                }

                // Link user to company
                $membershipAccess = app(UserMembershipAccess::class);
                $company = Company::findOrFail($lockedInvitation->company_id);
                $membershipAccess->linkToCompany($targetUser, $company);

                // Sync role if set and belongs to company
                if ($lockedInvitation->role_id) {
                    UserMembershipAccess::syncRole($targetUser, $lockedInvitation->company_id, $lockedInvitation->role_id);
                }

                // Link employee if set, belongs to company, and is unlinked
                if ($lockedInvitation->employee_id) {
                    $employee = Employee::where('id', $lockedInvitation->employee_id)
                        ->where('company_id', $lockedInvitation->company_id)
                        ->whereNull('user_id')
                        ->lockForUpdate()
                        ->first();

                    if ($employee) {
                        $employee->update(['user_id' => $targetUser->id]);
                    }
                }

                $lockedInvitation->update([
                    'accepted_at' => now(),
                ]);

                activity()
                    ->causedBy($targetUser)
                    ->performedOn($lockedInvitation)
                    ->withProperties([
                        'company_id' => $lockedInvitation->company_id,
                        'user_id' => $targetUser->id,
                        'email' => $lockedInvitation->email,
                    ])
                    ->tap(function ($activity) use ($lockedInvitation): void {
                        $activity->company_id = $lockedInvitation->company_id;
                    })
                    ->log('accepted user invitation');

                return $targetUser;
            });
        } catch (\DomainException $e) {
            return redirect()->route('login')->with('status', $e->getMessage());
        }

        // Only log in NEW users
        if (! $userExists) {
            Auth::login($user);
            $request->session()->regenerate();
        }

        return redirect()->route('dashboard')->with('success', 'Invitation accepted successfully.');
    }
}
