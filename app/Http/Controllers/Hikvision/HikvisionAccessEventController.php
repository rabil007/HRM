<?php

namespace App\Http\Controllers\Hikvision;

use App\Http\Controllers\Controller;
use App\Models\HikvisionAccessEvent;
use App\Models\HikvisionSetting;
use App\Services\HikvisionService;
use App\Support\Pagination\ResolvesPerPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class HikvisionAccessEventController extends Controller
{
    use ResolvesPerPage;

    public function __construct(private HikvisionService $hikvision) {}

    public function index(Request $request): Response
    {
        $perPage = $this->resolvePerPage($request);

        $paginator = HikvisionAccessEvent::query()
            ->accessRecords()
            ->orderByDesc('occurrence_time')
            ->paginate($perPage)
            ->withQueryString();

        $settings = HikvisionSetting::current();
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
            'can' => [
                'fetch' => $request->user()?->can('hikvision.events.fetch') ?? false,
            ],
        ]);
    }

    public function fetch(Request $request): RedirectResponse
    {
        try {
            $result = $this->hikvision->fetchAccessEvents();

            return back()->with('success', $result['message']);
        } catch (RuntimeException $exception) {
            return back()->withErrors([
                'fetch' => $exception->getMessage(),
            ]);
        }
    }
}
