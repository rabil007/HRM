<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class CompanySwitchController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();

        abort_unless($user, 403);

        $data = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
        ]);

        $companyId = (int) $data['company_id'];

        $isMember = $user->companies()->whereKey($companyId)->exists()
            || ($user->company_id && (int) $user->company_id === $companyId);

        abort_unless($isMember, 403);

        $request->session()->put('current_company_id', $companyId);

        return back();
    }
}
