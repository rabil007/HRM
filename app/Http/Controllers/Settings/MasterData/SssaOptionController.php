<?php

namespace App\Http\Controllers\Settings\MasterData;

use App\Http\Controllers\Concerns\ReturnsQuickCreateJson;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Settings\MasterData\Concerns\PaginatesMasterDataIndex;
use App\Http\Requests\Settings\MasterData\StoreSssaOptionRequest;
use App\Http\Requests\Settings\MasterData\UpdateSssaOptionRequest;
use App\Models\SssaOption;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class SssaOptionController extends Controller
{
    use PaginatesMasterDataIndex;
    use ReturnsQuickCreateJson;

    public function index()
    {
        $page = $this->paginateMasterDataIndex(
            request(),
            SssaOption::query()
                ->orderBy('name')
                ->select(['id', 'name', 'is_active']),
            ['name'],
        );

        return Inertia::render('settings/master-data/sssa-options', [
            'sssa_options' => $page['items'],
            'pagination' => $page['pagination'],
            'search' => $page['search'],
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
