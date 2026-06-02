<?php

namespace App\Http\Controllers\Settings\MasterData;

use App\Http\Controllers\Concerns\ReturnsQuickCreateJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\MasterData\StoreSssaOptionRequest;
use App\Http\Requests\Settings\MasterData\UpdateSssaOptionRequest;
use App\Models\SssaOption;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class SssaOptionController extends Controller
{
    use ReturnsQuickCreateJson;

    public function index()
    {
        $sssaOptions = SssaOption::query()
            ->orderBy('name')
            ->get(['id', 'name', 'is_active']);

        return Inertia::render('settings/master-data/sssa-options', [
            'sssa_options' => $sssaOptions,
        ]);
    }

    public function store(StoreSssaOptionRequest $request): JsonResponse|RedirectResponse
    {
        $data = $request->validated();
        $data['is_active'] = $data['is_active'] ?? true;

        return $this->createOrReturnExistingQuickCreate(
            $request,
            SssaOption::class,
            $data,
            redirect()->route('settings.master-data.sssa-options.index'),
        );
    }

    public function update(UpdateSssaOptionRequest $request, SssaOption $sssaOption)
    {
        $sssaOption->update($request->validated());

        return redirect()->route('settings.master-data.sssa-options.index');
    }

    public function destroy(SssaOption $sssaOption)
    {
        $sssaOption->delete();

        return redirect()->route('settings.master-data.sssa-options.index');
    }
}
