<?php

namespace App\Http\Controllers\Hikvision;

use App\Http\Controllers\Controller;
use App\Models\HikvisionDevice;
use App\Models\HikvisionSetting;
use App\Services\HikvisionService;
use App\Support\Pagination\ResolvesPerPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class HikvisionDeviceController extends Controller
{
    use ResolvesPerPage;

    public function __construct(private HikvisionService $hikvision) {}

    public function index(Request $request): Response
    {
        $perPage = $this->resolvePerPage($request);

        $paginator = HikvisionDevice::query()
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();

        $lastSyncedAt = HikvisionDevice::query()->max('synced_at');

        return Inertia::render('hikvision/devices', [
            'devices' => $paginator->getCollection()
                ->map(fn (HikvisionDevice $device) => [
                    'id' => $device->id,
                    'hikvision_id' => $device->hikvision_id,
                    'serial_no' => $device->serial_no,
                    'name' => $device->name,
                    'category' => $device->category,
                    'type' => $device->type,
                    'online_status' => $device->online_status,
                    'synced_at' => $device->synced_at?->toIso8601String(),
                    'detail' => $device->raw_detail_payload,
                ])
                ->values()
                ->all(),
            'pagination' => $this->paginationMeta($paginator),
            'is_configured' => HikvisionSetting::current()->isConfigured(),
            'last_synced_at' => $lastSyncedAt ? (string) $lastSyncedAt : null,
            'can' => [
                'sync' => $request->user()?->can('hikvision.devices.sync') ?? false,
            ],
        ]);
    }

    public function sync(Request $request): RedirectResponse
    {
        try {
            $result = $this->hikvision->syncDevices();

            return back()->with('success', $result['message']);
        } catch (RuntimeException $exception) {
            return back()->withErrors([
                'sync' => $exception->getMessage(),
            ]);
        }
    }
}
