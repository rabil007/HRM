<?php

namespace App\Http\Controllers\Settings\MasterData;

use App\Http\Controllers\Controller;
use App\Models\Vessel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class VesselController extends Controller
{
    public function index(Request $request): RedirectResponse
    {
        return redirect()->route('organization.vessels.index', $request->query());
    }

    public function show(Request $request, Vessel $vessel): RedirectResponse
    {
        return redirect()->route('organization.vessels.show', [
            'vessel' => $vessel,
            ...$request->query(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        return redirect()->route('organization.vessels.index');
    }

    public function update(Request $request, Vessel $vessel): RedirectResponse
    {
        return redirect()->route('organization.vessels.show', $vessel);
    }

    public function destroy(Request $request, Vessel $vessel): RedirectResponse
    {
        return redirect()->route('organization.vessels.index');
    }

    public function importTemplate(): RedirectResponse
    {
        return redirect()->route('organization.vessels.import.template');
    }

    public function import(Request $request): RedirectResponse
    {
        return redirect()->route('organization.vessels.index');
    }
}
