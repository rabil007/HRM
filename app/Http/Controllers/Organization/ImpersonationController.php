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

        $service->start($request, $user, $companyId);

        return redirect()->route('dashboard')->with('info', "Logged in as {$user->name}. Return to your account at any time using the banner.");
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
