<?php

namespace App\Http\Controllers\Hikvision;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hikvision\LinkHikvisionPersonEmployeeRequest;
use App\Http\Requests\Hikvision\StoreHikvisionPersonRequest;
use App\Http\Requests\Hikvision\UpdateHikvisionPersonRequest;
use App\Models\Employee;
use App\Models\HikvisionPerson;
use App\Models\HikvisionPersonGroup;
use App\Models\HikvisionSetting;
use App\Services\HikvisionService;
use App\Support\Employees\Actions\SyncEmployeeHikvisionPersonLink;
use App\Support\Hikvision\HikvisionPersonPhotoStorage;
use App\Support\Hikvision\HikvisionPersonWritePayload;
use App\Support\Pagination\ResolvesPerPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            ->with(['group', 'employee:id,company_id,name,employee_no,hikvision_person_id'])
            ->filtered($filters)
            ->orderBy('full_name')
            ->paginate($perPage)
            ->withQueryString();

        $settings = HikvisionSetting::current();
        $lastSyncedAt = $settings->persons_last_synced_at
            ?? HikvisionPerson::query()->max('synced_at');
        $companyId = (int) $request->attributes->get('current_company_id');
        $canLinkEmployee = $request->user()?->can('hikvision.persons.link') ?? false;

        return Inertia::render('hikvision/persons', [
            'persons' => $paginator->getCollection()
                ->map(fn (HikvisionPerson $person) => [
                    'id' => $person->id,
                    'person_id' => $person->person_id,
                    'person_code' => $person->person_code,
                    'full_name' => $person->full_name,
                    'first_name' => $person->first_name,
                    'last_name' => $person->last_name,
                    'group_id' => $person->group_id,
                    'group_name' => $person->group?->name,
                    'email' => $person->email,
                    'phone' => $person->phone,
                    'photo_url' => $person->photo_url,
                    'has_fingerprint' => $person->has_fingerprint,
                    'has_pin' => $person->has_pin,
                    'linked_employee' => $person->employee && (int) $person->employee->company_id === $companyId
                        ? [
                            'id' => $person->employee->id,
                            'name' => $person->employee->name,
                            'employee_no' => $person->employee->employee_no,
                        ]
                        : null,
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
            'employees_for_linking' => $canLinkEmployee && $companyId > 0
                ? Employee::optionsForHikvisionLinking($companyId)
                : [],
            'can' => [
                'sync' => $request->user()?->can('hikvision.persons.sync') ?? false,
                'create' => $request->user()?->can('hikvision.persons.create') ?? false,
                'update' => $request->user()?->can('hikvision.persons.update') ?? false,
                'delete' => $request->user()?->can('hikvision.persons.delete') ?? false,
                'link' => $canLinkEmployee,
            ],
        ]);
    }

    public function linkEmployee(
        LinkHikvisionPersonEmployeeRequest $request,
        HikvisionPerson $person,
        SyncEmployeeHikvisionPersonLink $syncLink,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        $employeeId = $request->integer('employee_id') ?: null;

        if ($employeeId === null) {
            $linkedEmployee = Employee::query()
                ->where('company_id', $companyId)
                ->where('hikvision_person_id', $person->id)
                ->first();

            if ($linkedEmployee !== null) {
                $syncLink->handle($linkedEmployee, null);
            }

            return back()->with('success', 'Employee link removed.');
        }

        $employee = Employee::query()
            ->where('company_id', $companyId)
            ->whereKey($employeeId)
            ->first();

        if ($employee === null) {
            abort(404);
        }

        $syncLink->handle($employee, $person->id);

        return back()->with('success', 'Employee linked to Hikvision person.');
    }

    public function store(StoreHikvisionPersonRequest $request): RedirectResponse
    {
        try {
            $this->ensureHikvisionConfigured();

            $result = $this->hikvision->createPerson(
                HikvisionPersonWritePayload::forCreate($request->validated()),
            );
            $personId = (string) ($result['personId'] ?? '');

            if ($personId === '') {
                throw new RuntimeException('Hikvision did not return a person ID.');
            }

            $detail = $this->hikvision->getPersonDetail($personId);

            HikvisionPerson::upsertFromApi([
                'personInfo' => $detail,
                'fingerList' => [],
                'pinCode' => '',
            ]);

            return back()->with('success', 'Person created in Hikvision.');
        } catch (RuntimeException $exception) {
            return back()->withErrors([
                'person' => $exception->getMessage(),
            ]);
        }
    }

    public function update(UpdateHikvisionPersonRequest $request, HikvisionPerson $person): RedirectResponse
    {
        try {
            $this->ensureHikvisionConfigured();

            $detail = $this->hikvision->getPersonDetail($person->person_id);

            $this->hikvision->updatePerson(
                HikvisionPersonWritePayload::forUpdate($request->validated(), $detail),
            );

            HikvisionPerson::upsertFromApi([
                'personInfo' => HikvisionPersonWritePayload::mergeUpdatedDetail($request->validated(), $detail),
                'fingerList' => [],
                'pinCode' => '',
            ], $person);

            return back()->with('success', 'Person updated in Hikvision.');
        } catch (RuntimeException $exception) {
            return back()->withErrors([
                'person' => $exception->getMessage(),
            ]);
        }
    }

    public function destroy(HikvisionPerson $person): RedirectResponse
    {
        try {
            $this->ensureHikvisionConfigured();

            DB::transaction(function () use ($person): void {
                $this->hikvision->deletePerson($person->person_id);

                Employee::query()
                    ->where('hikvision_person_id', $person->id)
                    ->update(['hikvision_person_id' => null]);

                HikvisionPersonPhotoStorage::delete($person);

                $person->delete();
            });

            return back()->with('success', 'Person deleted from Hikvision.');
        } catch (RuntimeException $exception) {
            return back()->withErrors([
                'person' => $exception->getMessage(),
            ]);
        }
    }

    public function uploadPhoto(Request $request, HikvisionPerson $person): RedirectResponse
    {
        $request->validate([
            'photo' => ['required', 'image', 'max:5120'],
        ]);

        try {
            $this->ensureHikvisionConfigured();

            $photoBase64 = base64_encode((string) file_get_contents($request->file('photo')->getRealPath()));
            $this->hikvision->uploadPersonPhoto($person->person_id, $photoBase64);
            $detail = $this->hikvision->getPersonDetail($person->person_id);

            $person = HikvisionPerson::upsertFromApi([
                'personInfo' => $detail,
                'fingerList' => [],
                'pinCode' => '',
            ], $person);

            HikvisionPersonPhotoStorage::applyUploadedFile(
                $person,
                $request->file('photo'),
                filled($detail['headPicUrl'] ?? null) ? (string) $detail['headPicUrl'] : null,
            );

            return back()->with('success', 'Person photo uploaded.');
        } catch (RuntimeException $exception) {
            return back()->withErrors([
                'photo' => $exception->getMessage(),
            ]);
        }
    }

    public function sync(Request $request): RedirectResponse
    {
        try {
            $this->ensureHikvisionConfigured();

            $result = $this->hikvision->syncPersons();

            return back()->with('success', $result['message']);
        } catch (RuntimeException $exception) {
            return back()->withErrors([
                'sync' => $exception->getMessage(),
            ]);
        }
    }

    private function ensureHikvisionConfigured(): void
    {
        if (! HikvisionSetting::current()->isConfigured()) {
            throw new RuntimeException('Hikvision integration is not configured. Add credentials in Application settings.');
        }
    }
}
