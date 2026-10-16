<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Auth\ImpersonationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ImpersonationController extends Controller
{
    /**
     * Start impersonating the target user.
     */
    public function start(Request $request, User $user, ImpersonationService $service): RedirectResponse
    {
        $companyId = (int) ($request->attributes->get('current_company_id') ?? $request->session()->get('current_company_id'));

        // If initiator cannot impersonate the target, let start() perform the detailed check, logging, and abort
        if (! $service->canImpersonate($request->user(), $user, $companyId)) {
            $service->start($request, $user, $companyId);
        }

        // Direct administrator through Fortify confirmation flow if recent authentication is required
        if (! $service->hasRecentAuthentication($request)) {
            $request->session()->put('impersonation_pending', [
                'target_user_id' => $user->id,
                'company_id' => $companyId,
            ]);
            $request->session()->put('url.intended', route('organization.impersonation.confirm'));

            return redirect()->route('password.confirm');
        }

        $service->start($request, $user, $companyId);

        return redirect()->route('dashboard')->with('info', "Logged in as {$user->name}. Return to your account at any time using the banner.");
    }

    /**
     * Complete pending impersonation after password confirmation.
     */
    public function confirmPending(Request $request, ImpersonationService $service): RedirectResponse
    {
        if ($service->isImpersonating($request)) {
            abort(403, 'Nested impersonation is not allowed.');
        }

        $pending = $request->session()->pull('impersonation_pending');

        if (! is_array($pending) || empty($pending['target_user_id'])) {
            return redirect()->route('organization.users');
        }

        $targetUserId = (int) $pending['target_user_id'];
        $companyId = (int) ($pending['company_id'] ?? 0);

        $target = User::find($targetUserId);

        if (! $target) {
            return redirect()->route('organization.users')->with('error', 'Target user not found.');
        }

        if (! $service->hasRecentAuthentication($request)) {
            $request->session()->put('impersonation_pending', $pending);
            $request->session()->put('url.intended', route('organization.impersonation.confirm'));

            return redirect()->route('password.confirm');
        }

        $service->start($request, $target, $companyId);

        return redirect()->route('dashboard')->with('info', "Logged in as {$target->name}. Return to your account at any time using the banner.");
    }

    /**
     * Stop impersonation and restore the original administrator account.
     */
    public function stop(Request $request, ImpersonationService $service): RedirectResponse
    {
        $original = $service->stop($request);

        $targetUrl = $original->can('users.view') ? route('organization.users') : route('dashboard');

        return redirect()->to($targetUrl)->with('success', "Returned to your account ({$original->name}).");
    }
}
