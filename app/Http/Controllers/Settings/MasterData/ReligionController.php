<?php

namespace App\Http\Controllers\Settings\MasterData;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\MasterData\StoreReligionRequest;
use App\Http\Requests\Settings\MasterData\UpdateReligionRequest;
use App\Models\Religion;
use Inertia\Inertia;

class ReligionController extends Controller
{
    public function index()
    {
        $religions = Religion::query()
            ->orderBy('name')
            ->get(['id', 'name', 'is_active']);

        return Inertia::render('settings/master-data/religions', [
            'religions' => $religions,
        ]);
    }

    public function store(StoreReligionRequest $request)
    {
        $data = $request->validated();
        $data['is_active'] = $data['is_active'] ?? true;

        Religion::create($data);

        return redirect()->route('settings.master-data.religions.index');
    }

    public function update(UpdateReligionRequest $request, Religion $religion)
    {
        $data = $request->validated();

        $religion->update($data);

        return redirect()->route('settings.master-data.religions.index');
    }

    public function destroy(Religion $religion)
    {
        $religion->delete();

        return redirect()->route('settings.master-data.religions.index');
    }
}
