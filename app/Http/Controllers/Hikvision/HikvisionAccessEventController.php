<?php

namespace App\Http\Controllers\Hikvision;

use App\Http\Controllers\Controller;
use App\Jobs\FetchHikvisionAccessEventsJob;
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

        return Inertia::render('hikvision/access-events', [
            'events' => $paginator->getCollection()
                ->map(fn (HikvisionAccessEvent $event) => [
                    'id' => $event->id,
                    'occurrence_time' => $event->occurrence_time?->toIso8601String(),
                    'person_name' => $event->person_name,
                    'device_name' => $event->device_name,
                    'door_no' => $event->door_no,
                    'resource_name' => $event->resource_name,
                    'card_reader_no' => $event->card_reader_no,
                    'verify_mode' => $event->verify_mode,
                    'attendance_status' => $event->attendance_status,
                    'transaction_source' => $event->transaction_source,
                    'fetched_at' => $event->fetched_at?->toIso8601String(),
                ])
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
            'attendance_lookback_days' => max(1, (int) config('hikvision.attendance_lookback_days', 7)),
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
