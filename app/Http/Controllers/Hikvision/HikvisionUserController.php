<?php

namespace App\Http\Controllers\Hikvision;

use App\Http\Controllers\Controller;
use App\Models\HikvisionSetting;
use App\Models\HikvisionUser;
use App\Services\HikvisionService;
use App\Support\Pagination\ResolvesPerPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class HikvisionUserController extends Controller
{
    use ResolvesPerPage;

    public function __construct(private HikvisionService $hikvision) {}

    public function index(Request $request): Response
    {
        $perPage = $this->resolvePerPage($request);

        $paginator = HikvisionUser::query()
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();

        $lastSyncedAt = HikvisionUser::query()->max('synced_at');

        return Inertia::render('hikvision/users', [
            'users' => $paginator->getCollection()
                ->map(fn (HikvisionUser $user) => [
                    'id' => $user->id,
                    'hikvision_id' => $user->hikvision_id,
                    'name' => $user->name,
                    'synced_at' => $user->synced_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'pagination' => $this->paginationMeta($paginator),
            'is_configured' => HikvisionSetting::current()->isConfigured(),
            'last_synced_at' => $lastSyncedAt ? (string) $lastSyncedAt : null,
            'can' => [
                'sync' => $request->user()?->can('hikvision.users.sync') ?? false,
            ],
        ]);
    }

    public function sync(Request $request): RedirectResponse
    {
        try {
            $result = $this->hikvision->syncUsers();

            return back()->with('success', $result['message']);
        } catch (RuntimeException $exception) {
            return back()->withErrors([
                'sync' => $exception->getMessage(),
            ]);
        }
    }
}
