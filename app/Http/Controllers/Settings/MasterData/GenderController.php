<?php

namespace App\Http\Controllers\Settings\MasterData;

use App\Http\Controllers\Concerns\ReturnsQuickCreateJson;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Settings\MasterData\Concerns\PaginatesMasterDataIndex;
use App\Http\Requests\Settings\MasterData\StoreGenderRequest;
use App\Http\Requests\Settings\MasterData\UpdateGenderRequest;
use App\Models\Gender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class GenderController extends Controller
{
    use PaginatesMasterDataIndex;
    use ReturnsQuickCreateJson;

    public function index()
    {
        $page = $this->paginateMasterDataIndex(
            request(),
            Gender::query()
                ->orderBy('name')
                ->select(['id', 'name', 'is_active']),
        );

        return Inertia::render('settings/master-data/genders', [
            'genders' => $page['items'],
            'pagination' => $page['pagination'],
            'search' => $page['search'],
        ]);
    }

    public function store(StoreGenderRequest $request): JsonResponse|RedirectResponse
    {
        $data = $request->validated();
        $data['is_active'] = $data['is_active'] ?? true;

        return $this->createOrReturnExistingQuickCreate(
            $request,
            Gender::class,
            $data,
            redirect()->route('settings.master-data.genders.index'),
        );
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
