<?php

namespace App\Http\Controllers;

use App\Models\JobRun;
use App\Support\Pagination\ResolvesPerPage;
use App\Support\Queue\JobRunQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class JobRunController extends Controller
{
    use ResolvesPerPage;

    public function index(Request $request, JobRunQuery $query): Response
    {
        $validated = $request->validate([
            'tab' => ['nullable', 'string', 'in:history,failed,pending,registry'],
            'status' => ['nullable', 'string', 'in:running,completed,failed'],
            'name' => ['nullable', 'string', 'max:255'],
            'q' => ['nullable', 'string', 'max:200'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $tab = $validated['tab'] ?? 'history';
        $perPage = $this->resolvePerPage($request, default: 25, allowed: [25, 50, 100]);
        $page = (int) ($validated['page'] ?? 1);

        $historyRuns = [];
        $failedJobs = [];
        $pendingJobs = [];
        $pagination = $this->paginationMeta(new LengthAwarePaginator([], 0, $perPage, $page));

        if ($tab === 'history') {
            $paginator = $query->paginateHistory(
                $validated['status'] ?? null,
                $validated['name'] ?? null,
                $validated['q'] ?? null,
                $validated['date_from'] ?? null,
                $validated['date_to'] ?? null,
                $page,
                $perPage,
            );

            $historyRuns = $paginator->through(fn ($run) => $query->mapHistoryRun($run))->items();
            $pagination = $this->paginationMeta($paginator);
        }

        if ($tab === 'failed') {
            $paginator = $query->paginateFailed($validated['q'] ?? null, $page, $perPage);
            $failedJobs = $paginator->items();
            $pagination = $this->paginationMeta($paginator);
        }

        if ($tab === 'pending') {
            $paginator = $query->paginatePending($validated['q'] ?? null, $page, $perPage);
            $pendingJobs = $paginator->items();
            $pagination = $this->paginationMeta($paginator);
        }

        return Inertia::render('jobs', [
            'tab' => $tab,
            'history_runs' => $historyRuns,
            'failed_jobs' => $failedJobs,
            'pending_jobs' => $pendingJobs,
            'pagination' => $pagination,
            'names' => $query->distinctHistoryNames(),
            'statuses' => JobRunQuery::HISTORY_STATUSES,
            'registry' => $this->getJobRegistry(),
            'stats' => [
                'history_count' => JobRun::query()->count(),
                'completed_count' => JobRun::query()->where('status', JobRun::STATUS_COMPLETED)->count(),
                'failed_count' => DB::table('failed_jobs')->count(),
                'pending_count' => DB::table('jobs')->count(),
                'avg_duration_ms' => (int) JobRun::query()->where('status', JobRun::STATUS_COMPLETED)->whereNotNull('duration_ms')->avg('duration_ms'),
            ],
            'filters' => [
                'status' => $validated['status'] ?? '',
                'name' => $validated['name'] ?? '',
                'q' => $validated['q'] ?? '',
                'date_from' => $validated['date_from'] ?? '',
                'date_to' => $validated['date_to'] ?? '',
            ],
        ]);
    }

    public function retryFailed(string $uuid): RedirectResponse
    {
        $exists = DB::table('failed_jobs')->where('uuid', $uuid)->exists();

        if (! $exists) {
            return back()->with('error', 'Failed job not found.');
        }

        Artisan::call('queue:retry', ['id' => [$uuid]]);

        return back()->with('success', 'Failed job queued for retry.');
    }

    public function retryAllFailed(): RedirectResponse
    {
        $count = DB::table('failed_jobs')->count();

        if ($count === 0) {
            return back()->with('error', 'No failed jobs to retry.');
        }

        Artisan::call('queue:retry', ['id' => ['all']]);

        return back()->with('success', "All {$count} failed jobs queued for retry.");
    }

    public function destroyFailed(string $uuid): RedirectResponse
    {
        $deleted = DB::table('failed_jobs')->where('uuid', $uuid)->delete();

        if ($deleted === 0) {
            return back()->with('error', 'Failed job not found.');
        }

        return back()->with('success', 'Failed job removed.');
    }

    public function destroyAllFailed(): RedirectResponse
    {
        $deleted = DB::table('failed_jobs')->delete();

        if ($deleted === 0) {
            return back()->with('error', 'No failed jobs to remove.');
        }

        return back()->with('success', "All {$deleted} failed jobs removed.");
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getJobRegistry(): array
    {
        return [
            [
                'type' => 'job',
                'name' => 'FetchHikvisionAccessEventsJob',
                'class' => 'App\Jobs\FetchHikvisionAccessEventsJob',
                'purpose' => 'Fetches raw access log events from local Hikvision devices/APIs for a specific date (or scheduled runs) and stores them in the database.',
                'trigger' => 'Dispatched automatically by daily fetch commands or manually from the Hikvision Settings dashboard.',
                'queue' => 'default',
                'connection' => 'database',
                'parameters' => [
                    'date' => 'Optional date string (Y-m-d). If null, fetches scheduled access events for yesterday.',
                ],
                'details' => 'Timeout is 180s when date is passed, or 300s when null. It automatically dispatches SyncHikvisionAttendanceJob upon successful run.',
                'code_snippet' => "dispatch(new \\App\\Jobs\\FetchHikvisionAccessEventsJob('2026-06-25'));",
            ],
            [
                'type' => 'job',
                'name' => 'SyncHikvisionAttendanceJob',
                'class' => 'App\Jobs\SyncHikvisionAttendanceJob',
                'purpose' => 'Processes and synchronizes raw Hikvision access event logs against employee profiles, schedules, shifts, and rosters to generate accurate daily attendance logs.',
                'trigger' => 'Automatically dispatched after FetchHikvisionAccessEventsJob finishes, or manually from the attendance recalculation UI.',
                'queue' => 'default',
                'connection' => 'database',
                'parameters' => [
                    'date' => 'Optional date string (Y-m-d). If null, syncs all scheduled/pending days.',
                ],
                'details' => 'Timeout is 600s. Has a maximum attempts count of 1.',
                'code_snippet' => "dispatch(new \\App\\Jobs\\SyncHikvisionAttendanceJob('2026-06-25'));",
            ],
            [
                'type' => 'job',
                'name' => 'ProcessHikvisionWebhookEventJob',
                'class' => 'App\Jobs\ProcessHikvisionWebhookEventJob',
                'purpose' => 'Processes real-time webhook scan logs sent directly from Hikvision face-recognition terminals when an employee scans in/out.',
                'trigger' => 'Hikvision Webhook controller endpoint when a scan event is received.',
                'queue' => 'default',
                'connection' => 'database',
                'parameters' => [
                    'payload' => 'Array containing Hikvision webhook event details.',
                ],
                'details' => 'Upserts events into hikvision_access_events and triggers real-time attendance recalculation for the scanning employee.',
                'code_snippet' => 'dispatch(new \\App\\Jobs\\ProcessHikvisionWebhookEventJob($webhookPayload));',
            ],
            [
                'type' => 'job',
                'name' => 'SendDocumentExpiryAlertJob',
                'class' => 'App\Jobs\SendDocumentExpiryAlertJob',
                'purpose' => 'Checks for expiring employee/company documents (visas, passports, licenses) and sends email alerts to HR managers.',
                'trigger' => 'Daily console command (documents:dispatch-expiry-alerts).',
                'queue' => 'default',
                'connection' => 'database',
                'parameters' => [
                    'companyId' => 'Integer company ID to scan documents for.',
                ],
                'details' => 'Tries up to 3 times with progressive backoff: 60s, 300s, 900s. Logs failures to document_alert_logs.',
                'code_snippet' => 'dispatch(new \\App\\Jobs\\SendDocumentExpiryAlertJob($companyId));',
            ],
            [
                'type' => 'command',
                'name' => 'documents:dispatch-expiry-alerts',
                'class' => 'App\Console\Commands\DispatchDocumentExpiryAlertsCommand',
                'purpose' => 'Scans all active companies and dispatches visa/passport/document expiry notification jobs for each.',
                'trigger' => 'Artisan Schedule (daily). Runs at 07:00 by default.',
                'schedule' => '0 7 * * * (Daily at 07:00)',
                'signature' => 'documents:dispatch-expiry-alerts {--company= : Limit to a single company ID}',
                'details' => 'Runs daily without overlapping to prevent duplicate emails.',
                'code_snippet' => 'php artisan documents:dispatch-expiry-alerts --company=1',
            ],
            [
                'type' => 'command',
                'name' => 'leave-balances:rollover',
                'class' => 'App\Console\Commands\RolloverLeaveBalancesCommand',
                'purpose' => 'Processes annual rollover of employee leave balances, resetting annual leaves and applying carryover rules.',
                'trigger' => 'Artisan Schedule (yearly). Runs on Jan 1st.',
                'schedule' => '30 0 1 1 * (Jan 1st at 00:30)',
                'signature' => 'leave-balances:rollover',
                'details' => 'Clears existing allocations and applies new annual accruals based on policy settings.',
                'code_snippet' => 'php artisan leave-balances:rollover',
            ],
            [
                'type' => 'command',
                'name' => 'hikvision:fetch-access-events',
                'class' => 'App\Console\Commands\FetchHikvisionAccessEventsCommand',
                'purpose' => 'Fetches the previous day\'s access log events from the local Hikvision device API.',
                'trigger' => 'Artisan Schedule (daily). Runs at 01:00.',
                'schedule' => '0 1 * * * (Daily at 01:00)',
                'signature' => 'hikvision:fetch-access-events',
                'details' => 'Only runs if enabled in Hikvision settings. Dispatches FetchHikvisionAccessEventsJob.',
                'code_snippet' => 'php artisan hikvision:fetch-access-events',
            ],
            [
                'type' => 'command',
                'name' => 'hikvision:fetch-todays-access-events',
                'class' => 'App\Console\Commands\FetchTodaysHikvisionAccessEventsCommand',
                'purpose' => 'Fetches the current day\'s (evening) access log events from local Hikvision devices.',
                'trigger' => 'Artisan Schedule (daily). Runs at 19:30.',
                'schedule' => '30 19 * * * (Daily at 19:30)',
                'signature' => 'hikvision:fetch-todays-access-events',
                'details' => 'Ensures recent evening punches are captured promptly for rosters. Only runs if enabled.',
                'code_snippet' => 'php artisan hikvision:fetch-todays-access-events',
            ],
            [
                'type' => 'command',
                'name' => 'leave-balances:sync',
                'class' => 'App\Console\Commands\SyncLeaveBalancesCommand',
                'purpose' => 'Synchronizes employee leave balances and allocations based on approved requests.',
                'trigger' => 'Console / Manual run.',
                'schedule' => 'Manual run',
                'signature' => 'leave-balances:sync',
                'details' => 'Re-calculates leave allocations against taken leaves to fix any balance mismatches.',
                'code_snippet' => 'php artisan leave-balances:sync',
            ],
        ];
    }
}
