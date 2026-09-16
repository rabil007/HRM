<?php

namespace App\Http\Controllers\Settings\MasterData;

use App\Http\Controllers\Concerns\ReturnsQuickCreateJson;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Settings\MasterData\Concerns\PaginatesMasterDataIndex;
use App\Http\Requests\Settings\MasterData\StoreHotelRequest;
use App\Http\Requests\Settings\MasterData\UpdateHotelRequest;
use App\Models\Hotel;
use App\Support\MasterData\MasterDataUsage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class HotelController extends Controller
{
    use PaginatesMasterDataIndex;
    use ReturnsQuickCreateJson;

    public function index(Request $request)
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        $page = $this->withMasterDataUsage(
            $this->paginateMasterDataIndex(
                $request,
                Hotel::query()
                    ->forCompany($companyId)
                    ->orderBy('name')
                    ->select(['id', 'company_id', 'name', 'description', 'is_active']),
                ['name', 'description'],
            ),
            'settings.master-data.hotels.delete',
            $companyId,
            Hotel::class,
        );

        return Inertia::render('settings/master-data/hotels', [
            'hotels' => $page['items'],
            'pagination' => $page['pagination'],
            'search' => $page['search'],
        ]);
    }

    public function store(StoreHotelRequest $request): JsonResponse|RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $data = $request->validated();
        $data['company_id'] = $companyId;
        $data['is_active'] = $data['is_active'] ?? true;

        return $this->createOrReturnExistingQuickCreate(
            $request,
            Hotel::class,
            $data,
            redirect()->route('settings.master-data.hotels.index'),
            scopeAttributes: ['company_id' => $companyId],
        );
    }

    public function update(UpdateHotelRequest $request, Hotel $hotel)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $hotel->company_id === $companyId, 404);

        $hotel->update($request->validated());

        return redirect()->route('settings.master-data.hotels.index');
    }

    public function destroy(Request $request, Hotel $hotel)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $hotel->company_id === $companyId, 404);

        if ($blocked = MasterDataUsage::denyDeleteRedirect($hotel, 'settings.master-data.hotels.index', $companyId)) {
            return $blocked;
        }

        $hotel->delete();

        return redirect()->route('settings.master-data.hotels.index');
    }
}
