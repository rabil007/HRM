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

        $paginator = HikvisionAccessEvent::query()
            ->accessRecords()
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
                    'fetched_at' => $event->fetched_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'pagination' => $this->paginationMeta($paginator),
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
