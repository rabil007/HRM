<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Support\Auth\ImpersonationService;
use App\Support\Companies\ActivateCompanySession;
use Illuminate\Http\Request;

class CompanySwitchController extends Controller
{
    public function __invoke(Request $request, ActivateCompanySession $activateCompany)
    {
        $user = $request->user();

        abort_unless($user, 403);

        $data = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
        ]);

        if (app(ImpersonationService::class)->isImpersonating($request)) {
            $sessionData = $request->session()->get('impersonation');
            $chosenCompanyId = (int) ($sessionData['impersonated_company_id'] ?? $sessionData['original_company_id'] ?? 0);

            if ((int) $data['company_id'] !== $chosenCompanyId) {
                activity('impersonation')
                    ->causedBy($user)
                    ->event('company_switch_blocked')
                    ->withProperties([
                        'initiator_id' => $sessionData['original_user_id'] ?? null,
                        'target_id' => $user->id,
                        'chosen_company_id' => $chosenCompanyId,
                        'attempted_company_id' => (int) $data['company_id'],
                        'correlation_id' => $sessionData['correlation_id'] ?? null,
                        'ip' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                    ])
                    ->log('Blocked company switch outside impersonation boundary');

                abort(403, 'Company switching outside the impersonated company boundary is not allowed.');
            }
        }

        $activateCompany->handle($user, (int) $data['company_id'], $request);

        return back();
    }
}
