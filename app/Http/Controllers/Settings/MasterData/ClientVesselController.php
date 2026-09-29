<?php

namespace App\Http\Controllers\Settings\MasterData;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\MasterData\StoreClientVesselRequest;
use App\Models\Client;
use App\Support\Vessels\CreateVesselAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class ClientVesselController extends Controller
{
    public function store(StoreClientVesselRequest $request, Client $client, CreateVesselAction $createVessel): RedirectResponse
    {
        abort_unless($request->user()?->can('crew_operations.vessels.create'), 403);

        if (! $client->is_active) {
            throw ValidationException::withMessages([
                'client_id' => 'Activate this client before adding new projects or vessels.',
            ]);
        }

        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless($companyId > 0, 403);

        $data = $request->safe()->except(['certificate']);
        $data['company_id'] = $companyId;
        $data['client_id'] = (int) $client->id;

        $vessel = $createVessel->execute($data, $request->file('certificate'));

        return redirect()
            ->route('settings.master-data.clients.show', $client)
            ->with('success', "Vessel '{$vessel->name}' created successfully.");
    }
}
