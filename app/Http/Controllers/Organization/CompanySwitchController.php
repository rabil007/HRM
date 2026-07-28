<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
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

        $activateCompany->handle($user, (int) $data['company_id'], $request);

        return back();
    }
}
