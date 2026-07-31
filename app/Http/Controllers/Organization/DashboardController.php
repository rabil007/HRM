<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Support\Dashboard\DashboardComposer;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardComposer $composer): Response
    {
        $user = $request->user();
        $companyId = (int) $request->attributes->get('current_company_id');

        return Inertia::render('dashboard', [
            ...$composer->primary($user, $companyId),
            ...$composer->deferred($user, $companyId),
        ]);
    }
}
