<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organization\Recruitment;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RecruitmentController extends Controller
{
    /**
     * Redirect authenticated users to their accessible Recruitment submodule.
     */
    public function index(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user !== null && $user->can('recruitment.requirements.view')) {
            return redirect()->route('organization.recruitment.requirements.index');
        }

        abort(403, 'Unauthorized module access.');
    }
}
