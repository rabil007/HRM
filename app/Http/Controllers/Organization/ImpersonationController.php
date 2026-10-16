<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Support\Auth\ImpersonationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ImpersonationController extends Controller
{
    /**
     * Start impersonating the target user.
     */
    public function start(Request $request, User $user, ImpersonationService $service): RedirectResponse
    {
        $initiator = $request->user();
        abort_unless($initiator instanceof User, 401);

        $companyId = (int) ($request->attributes->get('current_company_id') ?? $request->session()->get('current_company_id'));

        // If initiator cannot impersonate the target, let start() perform the detailed check, logging, and abort
        if (! $service->canImpersonate($initiator, $user, $companyId)) {
            $service->start($request, $user, $companyId);
        }

        // Direct administrator through Fortify confirmation flow if recent authentication is required
        if (! $service->hasRecentAuthentication($request)) {
            $request->session()->put('impersonation_pending', [
                'initiator_id' => $initiator->id,
                'company_id' => $companyId,
                'target_id' => $user->id,
                'target_user_id' => $user->id,
                'created_at' => now()->timestamp,
                'expires_at' => now()->addMinutes(10)->timestamp,
            ]);
            $request->session()->put('url.intended', route('organization.impersonation.confirm'));

            return redirect()->route('password.confirm');
        }

        $service->start($request, $user, $companyId);

        return redirect()->route('dashboard')->with('info', "Logged in as {$user->name}. Return to your account at any time using the banner.");
    }

    /**
     * Safe continuation landing page after Fortify password confirmation.
     * Renders an Inertia view without altering authenticated identity on GET.
     */
    public function continuation(Request $request, ImpersonationService $service): Response|RedirectResponse
    {
        if ($service->isImpersonating($request)) {
            abort(403, 'Nested impersonation is not allowed.');
        }

        $pending = $request->session()->get('impersonation_pending');
        $targetUserId = (int) ($pending['target_id'] ?? $pending['target_user_id'] ?? 0);

        if (! is_array($pending) || $targetUserId <= 0) {
            return redirect()->route('organization.users');
        }

        // Validate expiration (10 minutes)
        if (empty($pending['expires_at']) || now()->timestamp > (int) $pending['expires_at']) {
            $request->session()->forget('impersonation_pending');

            return redirect()->route('organization.users')->with('error', 'Pending impersonation request has expired. Please try again.');
        }

        // Validate initiator identity match
        $initiator = $request->user();
        if (! $initiator instanceof User || $initiator->id !== (int) ($pending['initiator_id'] ?? 0)) {
            $request->session()->forget('impersonation_pending');

            return redirect()->route('organization.users')->with('error', 'Initiating user mismatch.');
        }

        $companyId = (int) ($pending['company_id'] ?? 0);

        $target = User::find($targetUserId);
        if (! $target) {
            $request->session()->forget('impersonation_pending');

            return redirect()->route('organization.users')->with('error', 'Target user not found.');
        }

        $company = Company::find($companyId);
        if (! $company) {
            $request->session()->forget('impersonation_pending');

            return redirect()->route('organization.users')->with('error', 'Company not found.');
        }

        // Revalidate initiator and target eligibility against selected company
        if (! $service->canImpersonate($initiator, $target, $companyId)) {
            $request->session()->forget('impersonation_pending');
            $service->start($request, $target, $companyId);
        }

        // If password confirmation expired between prompt and continuation
        if (! $service->hasRecentAuthentication($request)) {
            $request->session()->put('url.intended', route('organization.impersonation.confirm'));

            return redirect()->route('password.confirm');
        }

        return Inertia::render('auth/impersonation-continuation', [
            'target' => [
                'id' => $target->id,
                'name' => $target->name,
                'email' => $target->email,
            ],
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
            ],
        ]);
    }

    /**
     * Complete pending impersonation via CSRF-protected POST request.
     * Consumes the pending context to prevent replay attacks.
     */
    public function complete(Request $request, ImpersonationService $service): RedirectResponse
    {
        if ($service->isImpersonating($request)) {
            abort(403, 'Nested impersonation is not allowed.');
        }

        $pending = $request->session()->pull('impersonation_pending');
        $targetUserId = (int) ($pending['target_id'] ?? $pending['target_user_id'] ?? 0);

        if (! is_array($pending) || $targetUserId <= 0) {
            return redirect()->route('organization.users');
        }

        // Validate expiration (10 minutes)
        if (empty($pending['expires_at']) || now()->timestamp > (int) $pending['expires_at']) {
            return redirect()->route('organization.users')->with('error', 'Pending impersonation request has expired. Please try again.');
        }

        // Validate initiator identity match
        $initiator = $request->user();
        if (! $initiator instanceof User || $initiator->id !== (int) ($pending['initiator_id'] ?? 0)) {
            return redirect()->route('organization.users')->with('error', 'Initiating user mismatch.');
        }

        $companyId = (int) ($pending['company_id'] ?? 0);

        $target = User::find($targetUserId);
        if (! $target) {
            return redirect()->route('organization.users')->with('error', 'Target user not found.');
        }

        // Revalidate initiator and target eligibility against selected company
        if (! $service->canImpersonate($initiator, $target, $companyId)) {
            $service->start($request, $target, $companyId);
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
     * Alias for complete to support backward compatibility.
     */
    public function confirmPending(Request $request, ImpersonationService $service): RedirectResponse
    {
        return $this->complete($request, $service);
    }

    /**
     * Cancel pending impersonation.
     */
    public function cancel(Request $request): RedirectResponse
    {
        $request->session()->forget('impersonation_pending');

        return redirect()->route('organization.users');
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
