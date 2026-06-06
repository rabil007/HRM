<?php

namespace App\Http\Controllers\Hikvision;

use App\Http\Controllers\Controller;
use App\Models\HikvisionPerson;
use App\Models\HikvisionPersonGroup;
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

        $groupOptions = HikvisionPersonGroup::filterOptions();
        $groupIds = array_column($groupOptions, 'value');
        $group = $request->string('group')->toString();

        if ($group !== '' && ! in_array($group, $groupIds, true)) {
            $group = '';
        }

        $credential = $request->string('credential')->toString();

        if ($credential !== '' && ! in_array($credential, HikvisionPerson::credentialFilterOptions(), true)) {
            $credential = '';
        }

        $filters = [
            'search' => $request->string('search')->toString(),
            'group' => $group,
            'credential' => $credential,
        ];

        $paginator = HikvisionPerson::query()
            ->with('group')
            ->filtered($filters)
            ->orderBy('full_name')
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
                    'person_code' => $person->person_code,
                    'full_name' => $person->full_name,
                    'group_name' => $person->group?->name,
                    'email' => $person->email,
                    'phone' => $person->phone,
                    'photo_url' => $person->photo_url,
                    'has_fingerprint' => $person->has_fingerprint,
                    'has_pin' => $person->has_pin,
                    'synced_at' => $person->synced_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'pagination' => $this->paginationMeta($paginator),
            'filters' => $filters,
            'group_options' => $groupOptions,
            'credential_options' => [
                ['value' => HikvisionPerson::CREDENTIAL_FINGERPRINT, 'label' => 'Fingerprint'],
                ['value' => HikvisionPerson::CREDENTIAL_PIN, 'label' => 'PIN'],
                ['value' => HikvisionPerson::CREDENTIAL_NONE, 'label' => 'No credentials'],
            ],
            'is_configured' => $settings->isConfigured(),
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
