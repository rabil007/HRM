<?php

namespace App\Http\Controllers\Hikvision;

use App\Http\Controllers\Controller;
use App\Jobs\FetchHikvisionAccessEventsJob;
use App\Models\Employee;
use App\Models\HikvisionAccessEvent;
use App\Models\HikvisionSetting;
use App\Support\Pagination\ResolvesPerPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HikvisionAccessEventController extends Controller
{
    use ResolvesPerPage;

    public function index(Request $request): Response
    {
        $perPage = $this->resolvePerPage($request);

        $attendanceStatus = $request->string('attendance_status')->toString();

        if ($attendanceStatus !== '' && ! in_array($attendanceStatus, HikvisionAccessEvent::attendanceStatusOptions(), true)) {
            $attendanceStatus = '';
        }

        $deviceOptions = HikvisionAccessEvent::deviceNameOptions();
        $device = $request->string('device')->toString();

        if ($device !== '' && ! in_array($device, $deviceOptions, true)) {
            $device = '';
        }

        $filters = [
            'search' => $request->string('search')->toString(),
            'date_from' => $request->string('date_from')->toString(),
            'date_to' => $request->string('date_to')->toString(),
            'attendance_status' => $attendanceStatus,
            'device' => $device,
        ];

        $paginator = HikvisionAccessEvent::query()
            ->accessRecords()
            ->filtered($filters)
            ->orderByDesc('occurrence_time')
            ->paginate($perPage)
            ->withQueryString();

        $settings = HikvisionSetting::current();
        $settings->resolveStaleEventsFetch();
        $settings->refresh();

        $fetchResult = $settings->acknowledgeFetchResult();

        $lastFetchedAt = $settings->events_last_fetched_at
            ?? HikvisionAccessEvent::query()->max('fetched_at');

        $personHikvisionIds = $paginator->getCollection()
            ->pluck('person_hikvision_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $employeesByPersonId = $personHikvisionIds === []
            ? collect()
            : Employee::query()
                ->with('hikvisionPerson:id,person_id')
                ->whereHas('hikvisionPerson', fn ($query) => $query->whereIn('person_id', $personHikvisionIds))
                ->get()
                ->keyBy(fn (Employee $employee): string => (string) $employee->hikvisionPerson?->person_id);

        return Inertia::render('hikvision/access-events', [
            'events' => $paginator->getCollection()
                ->map(function (HikvisionAccessEvent $event) use ($employeesByPersonId) {
                    $linkedEmployee = $event->person_hikvision_id
                        ? $employeesByPersonId->get($event->person_hikvision_id)
                        : null;

                    return [
                        'id' => $event->id,
                        'occurrence_time' => $event->occurrence_time?->toIso8601String(),
                        'person_name' => $event->person_name,
                        'employee_name' => $linkedEmployee?->name,
                        'employee_id' => $linkedEmployee?->id,
                        'device_name' => $event->device_name,
                        'door_no' => $event->door_no,
                        'resource_name' => $event->resource_name,
                        'card_reader_no' => $event->card_reader_no,
                        'verify_mode' => $event->verify_mode,
                        'attendance_status' => $event->attendance_status,
                        'transaction_source' => $event->transaction_source,
                        'event_source' => $event->event_source,
                        'snap_urls' => $event->snap_urls ?? [],
                        'fetched_at' => $event->fetched_at?->toIso8601String(),
                    ];
                })
                ->values()
                ->all(),
            'pagination' => $this->paginationMeta($paginator),
            'filters' => $filters,
            'attendance_status_options' => [
                ['value' => HikvisionAccessEvent::ATTENDANCE_CHECK_IN, 'label' => 'Check in'],
                ['value' => HikvisionAccessEvent::ATTENDANCE_CHECK_OUT, 'label' => 'Check out'],
            ],
            'device_options' => array_map(
                fn (string $name): array => ['value' => $name, 'label' => $name],
                $deviceOptions,
            ),
            'is_configured' => $settings->isConfigured(),
            'last_fetched_at' => $lastFetchedAt instanceof \DateTimeInterface
                ? $lastFetchedAt->format(\DateTimeInterface::ATOM)
                : ($lastFetchedAt ? (string) $lastFetchedAt : null),
            'fetch_status' => $fetchResult['status'],
            'fetch_message' => $fetchResult['message'],
            'can' => [
                'fetch' => $request->user()?->can('hikvision.events.fetch') ?? false,
            ],
        ]);
    }

    public function fetch(Request $request): RedirectResponse
    {
        $settings = HikvisionSetting::current();

        if (! $settings->isConfigured()) {
            return back()->withErrors([
                'fetch' => 'Hikvision integration is not configured. Add credentials in Application settings.',
            ]);
        }

        if ($settings->isEventsFetchProcessing()) {
            return back()->with('info', 'A fetch is already in progress.');
        }

        $settings->beginEventsFetch();
        FetchHikvisionAccessEventsJob::dispatch();

        return back()->with('success', 'Fetch started. Records will update automatically when complete.');
    }
}
