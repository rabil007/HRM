<?php

namespace App\Http\Controllers\Settings\MasterData;

use App\Http\Controllers\Concerns\ReturnsQuickCreateJson;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Settings\MasterData\Concerns\PaginatesMasterDataIndex;
use App\Http\Requests\Settings\MasterData\AssignLegacyRoomTypeRequest;
use App\Http\Requests\Settings\MasterData\ReconcileLegacyRoomTypeRequest;
use App\Http\Requests\Settings\MasterData\StoreHotelRequest;
use App\Http\Requests\Settings\MasterData\UpdateHotelRequest;
use App\Models\Hotel;
use App\Models\RoomType;
use App\Support\MasterData\MasterDataUsage;
use App\Support\MasterData\ReconcileLegacyRoomTypes;
use App\Support\MasterData\SyncHotelRoomTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
                    ->with(['roomTypes' => fn ($query) => $query->orderBy('name')])
                    ->orderBy('name')
                    ->select(['id', 'company_id', 'name', 'description', 'is_active']),
                ['name', 'description', 'roomTypes.name'],
                fn (Hotel $hotel): array => $this->presentHotel($hotel, $companyId, $request),
            ),
            'settings.master-data.hotels.delete',
            $companyId,
            Hotel::class,
        );

        $unassignedRoomTypes = ReconcileLegacyRoomTypes::unresolvedForCompany($companyId);

        return Inertia::render('settings/master-data/hotels', [
            'hotels' => $page['items'],
            'pagination' => $page['pagination'],
            'search' => $page['search'],
            'unassigned_room_types' => $unassignedRoomTypes,
            'hotels_for_assignment' => Hotel::query()
                ->forCompany($companyId)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Hotel $hotel): array => [
                    'id' => $hotel->id,
                    'name' => $hotel->name,
                ])
                ->values()
                ->all(),
        ]);
    }

    public function store(StoreHotelRequest $request): JsonResponse|RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $data = $request->validated();
        $roomTypes = $request->validatedRoomTypes();
        unset($data['room_types'], $data['removed_room_type_ids']);
        $data['company_id'] = $companyId;
        $data['is_active'] = $data['is_active'] ?? true;

        if ($request->expectsJson()) {
            $labelValue = trim((string) ($data['name'] ?? ''));
            $existing = $this->findExistingQuickCreate(
                Hotel::class,
                'name',
                $labelValue,
                ['company_id' => $companyId],
            );

            if ($existing instanceof Hotel) {
                return $this->storeRedirectOrQuickCreateJson(
                    $request,
                    $existing,
                    redirect()->route('settings.master-data.hotels.index'),
                );
            }

            $hotel = DB::transaction(function () use ($data, $roomTypes, $companyId): Hotel {
                $hotel = Hotel::query()->create($data);

                if ($roomTypes !== []) {
                    SyncHotelRoomTypes::handle($hotel, $roomTypes, [], $companyId);
                }

                return $hotel;
            });

            return $this->storeRedirectOrQuickCreateJson(
                $request,
                $hotel,
                redirect()->route('settings.master-data.hotels.index'),
            );
        }

        DB::transaction(function () use ($data, $roomTypes, $companyId): void {
            $hotel = Hotel::query()->create($data);

            if ($roomTypes !== []) {
                SyncHotelRoomTypes::handle($hotel, $roomTypes, [], $companyId);
            }
        });

        return redirect()->route('settings.master-data.hotels.index');
    }

    public function update(UpdateHotelRequest $request, Hotel $hotel)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $hotel->company_id === $companyId, 404);

        $data = $request->validated();
        $roomTypes = $request->validatedRoomTypes();
        $removedRoomTypeIds = $request->validatedRemovedRoomTypeIds();
        $shouldSyncRoomTypes = $request->shouldSyncRoomTypes();
        unset($data['room_types'], $data['removed_room_type_ids']);

        DB::transaction(function () use ($hotel, $data, $roomTypes, $removedRoomTypeIds, $shouldSyncRoomTypes, $companyId): void {
            $hotel = Hotel::query()
                ->whereKey($hotel->id)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->firstOrFail();

            $hotel->update($data);

            if ($shouldSyncRoomTypes) {
                SyncHotelRoomTypes::handle($hotel, $roomTypes, $removedRoomTypeIds, $companyId);
            }
        });

        return redirect()->route('settings.master-data.hotels.index');
    }

    public function destroy(Request $request, Hotel $hotel)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $hotel->company_id === $companyId, 404);

        if ($blocked = MasterDataUsage::denyDeleteRedirect($hotel, 'settings.master-data.hotels.index', $companyId)) {
            return $blocked;
        }

        DB::transaction(function () use ($hotel, $companyId): void {
            $hotel = Hotel::query()
                ->whereKey($hotel->id)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->firstOrFail();

            SyncHotelRoomTypes::deleteAllForHotel($hotel, $companyId);
            $hotel->delete();
        });

        return redirect()->route('settings.master-data.hotels.index');
    }

    public function assignLegacyRoomType(AssignLegacyRoomTypeRequest $request): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $data = $request->validated();

        $roomType = RoomType::query()
            ->whereKey((int) $data['room_type_id'])
            ->where('company_id', $companyId)
            ->whereNull('hotel_id')
            ->firstOrFail();

        $hotel = Hotel::query()
            ->whereKey((int) $data['hotel_id'])
            ->where('company_id', $companyId)
            ->firstOrFail();

        ReconcileLegacyRoomTypes::assignToHotel($roomType, $hotel, $companyId);

        return redirect()->route('settings.master-data.hotels.index');
    }

    public function reconcileLegacyRoomType(ReconcileLegacyRoomTypeRequest $request): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $data = $request->validated();

        $roomType = RoomType::query()
            ->whereKey((int) $data['room_type_id'])
            ->where('company_id', $companyId)
            ->whereNull('hotel_id')
            ->firstOrFail();

        ReconcileLegacyRoomTypes::splitByHotelUsage($roomType, $companyId);

        return redirect()->route('settings.master-data.hotels.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function presentHotel(Hotel $hotel, int $companyId, Request $request): array
    {
        $canDeleteRoomTypes = $request->user()?->can('settings.master-data.hotels.update') ?? false;

        $roomTypes = MasterDataUsage::decorate(
            $hotel->roomTypes,
            $canDeleteRoomTypes,
            $companyId,
            RoomType::class,
        );

        return [
            'id' => $hotel->id,
            'name' => $hotel->name,
            'description' => $hotel->description,
            'is_active' => $hotel->is_active,
            'room_types' => collect($roomTypes)->map(function (RoomType|array $roomType): array {
                if ($roomType instanceof RoomType) {
                    return [
                        'id' => $roomType->id,
                        'name' => $roomType->name,
                        'description' => $roomType->description,
                        'is_active' => $roomType->is_active,
                        'is_in_use' => $roomType->is_in_use,
                        'can_delete' => $roomType->can_delete,
                        'usage_count' => $roomType->usage_count,
                        'usage_label' => $roomType->usage_label,
                    ];
                }

                return $roomType;
            })->values()->all(),
        ];
    }
}
