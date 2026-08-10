<?php

namespace App\Http\Controllers\Settings\MasterData;

use App\Http\Controllers\Concerns\ReturnsQuickCreateJson;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Settings\MasterData\Concerns\PaginatesMasterDataIndex;
use App\Http\Requests\Settings\MasterData\StoreReligionRequest;
use App\Http\Requests\Settings\MasterData\UpdateReligionRequest;
use App\Models\Religion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ReligionController extends Controller
{
    use PaginatesMasterDataIndex;
    use ReturnsQuickCreateJson;

    public function index()
    {
        $page = $this->paginateMasterDataIndex(
            request(),
            Religion::query()
                ->orderBy('name')
                ->select(['id', 'name', 'is_active']),
            ['name'],
        );

        return Inertia::render('settings/master-data/religions', [
            'religions' => $page['items'],
            'pagination' => $page['pagination'],
            'search' => $page['search'],
        ]);
    }

    public function store(StoreReligionRequest $request): JsonResponse|RedirectResponse
    {
        $data = $request->validated();
        $data['is_active'] = $data['is_active'] ?? true;

        return $this->createOrReturnExistingQuickCreate(
            $request,
            Religion::class,
            $data,
            redirect()->route('settings.master-data.religions.index'),
        );
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
