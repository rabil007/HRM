<?php

namespace App\Http\Controllers\Settings\MasterData;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\MasterData\StoreGenderRequest;
use App\Http\Requests\Settings\MasterData\UpdateGenderRequest;
use App\Models\Gender;
use Inertia\Inertia;

class GenderController extends Controller
{
    public function index()
    {
        $genders = Gender::query()
            ->orderBy('name')
            ->get(['id', 'name', 'is_active']);

        return Inertia::render('settings/master-data/genders', [
            'genders' => $genders,
        ]);
    }

    public function store(StoreGenderRequest $request)
    {
        $data = $request->validated();
        $data['is_active'] = $data['is_active'] ?? true;

        Gender::create($data);

        return redirect()->route('settings.master-data.genders.index');
    }

    public function update(UpdateGenderRequest $request, Gender $gender)
    {
        $data = $request->validated();

        $gender->update($data);

        return redirect()->route('settings.master-data.genders.index');
    }

    public function destroy(Gender $gender)
    {
        $gender->delete();

        return redirect()->route('settings.master-data.genders.index');
    }
}
