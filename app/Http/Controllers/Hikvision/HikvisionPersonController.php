<?php

namespace App\Http\Controllers\Hikvision;

use App\Http\Controllers\Controller;
use App\Models\HikvisionPerson;
use App\Models\HikvisionSetting;
use App\Services\HikvisionService;
use App\Support\Pagination\ResolvesPerPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class HikvisionPersonController extends Controller
{
    use ResolvesPerPage;

    public function __construct(private HikvisionService $hikvision) {}

    public function index(Request $request): Response
    {
        $perPage = $this->resolvePerPage($request);

        $paginator = HikvisionPerson::query()
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate($perPage)
            ->withQueryString();

        $settings = HikvisionSetting::current();
        $lastSyncedAt = $settings->persons_last_synced_at
            ?? HikvisionPerson::query()->max('synced_at');

        return Inertia::render('hikvision/persons', [
            'persons' => $paginator->getCollection()
                ->map(fn (HikvisionPerson $person) => [
                    'id' => $person->id,
                    'person_id' => $person->person_id,
                    'name' => $person->displayName(),
                    'phone' => $person->phone,
                    'email' => $person->email,
                    'is_expired' => $person->is_expired,
                    'synced_at' => $person->synced_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'pagination' => $this->paginationMeta($paginator),
            'is_configured' => HikvisionSetting::current()->isConfigured(),
            'last_synced_at' => $lastSyncedAt instanceof \DateTimeInterface
                ? $lastSyncedAt->format(\DateTimeInterface::ATOM)
                : ($lastSyncedAt ? (string) $lastSyncedAt : null),
            'can' => [
                'sync' => $request->user()?->can('hikvision.persons.sync') ?? false,
            ],
        ]);
    }

    public function sync(Request $request): RedirectResponse
    {
        try {
            $result = $this->hikvision->syncPersons();

            return back()->with('success', $result['message']);
        } catch (RuntimeException $exception) {
            return back()->withErrors([
                'sync' => $exception->getMessage(),
            ]);
        }
    }
}
