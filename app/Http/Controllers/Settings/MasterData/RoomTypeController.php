<?php

namespace App\Http\Controllers\Settings\MasterData;

use App\Http\Controllers\Concerns\ReturnsQuickCreateJson;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Settings\MasterData\Concerns\PaginatesMasterDataIndex;
use App\Http\Requests\Settings\MasterData\StoreRoomTypeRequest;
use App\Http\Requests\Settings\MasterData\UpdateRoomTypeRequest;
use App\Models\RoomType;
use App\Support\MasterData\MasterDataUsage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RoomTypeController extends Controller
{
    use PaginatesMasterDataIndex;
    use ReturnsQuickCreateJson;

    public function index(Request $request)
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        $page = $this->withMasterDataUsage(
            $this->paginateMasterDataIndex(
                $request,
                RoomType::query()
                    ->forCompany($companyId)
                    ->orderBy('name')
                    ->select(['id', 'company_id', 'name', 'description', 'is_active']),
                ['name', 'description'],
            ),
            'settings.master-data.room-types.delete',
            $companyId,
            RoomType::class,
        );

        return Inertia::render('settings/master-data/room-types', [
            'room_types' => $page['items'],
            'pagination' => $page['pagination'],
            'search' => $page['search'],
        ]);
    }

    public function store(StoreRoomTypeRequest $request): JsonResponse|RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $data = $request->validated();
        $data['company_id'] = $companyId;
        $data['is_active'] = $data['is_active'] ?? true;

        return $this->createOrReturnExistingQuickCreate(
            $request,
            RoomType::class,
            $data,
            redirect()->route('settings.master-data.room-types.index'),
            scopeAttributes: ['company_id' => $companyId],
        );
    }

    public function update(UpdateRoomTypeRequest $request, RoomType $roomType)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $roomType->company_id === $companyId, 404);

        $roomType->update($request->validated());

        return redirect()->route('settings.master-data.room-types.index');
    }

    public function destroy(Request $request, RoomType $roomType)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $roomType->company_id === $companyId, 404);

        if ($blocked = MasterDataUsage::denyDeleteRedirect($roomType, 'settings.master-data.room-types.index', $companyId)) {
            return $blocked;
        }

        $roomType->delete();

        return redirect()->route('settings.master-data.room-types.index');
    }
}
