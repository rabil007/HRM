<?php

namespace App\Http\Controllers;

use App\Models\JobRun;
use App\Support\Pagination\ResolvesPerPage;
use App\Support\Queue\JobRegistry;
use App\Support\Queue\JobRunQuery;
use App\Support\Settings\ApplicationTimezone;
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

        $historyStats = JobRun::query()
            ->selectRaw('COUNT(*) as history_count')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as completed_count', [JobRun::STATUS_COMPLETED])
            ->selectRaw(
                'AVG(CASE WHEN status = ? AND duration_ms IS NOT NULL THEN duration_ms END) as avg_duration_ms',
                [JobRun::STATUS_COMPLETED],
            )
            ->first();

        return Inertia::render('jobs', [
            'tab' => $tab,
            'history_runs' => $historyRuns,
            'failed_jobs' => $failedJobs,
            'pending_jobs' => $pendingJobs,
            'pagination' => $pagination,
            'names' => $query->distinctHistoryNames(),
            'statuses' => JobRunQuery::HISTORY_STATUSES,
            'registry' => JobRegistry::entries(),
            'scheduler_timezone' => ApplicationTimezone::identifier(),
            'stats' => [
                'history_count' => (int) ($historyStats?->history_count ?? 0),
                'completed_count' => (int) ($historyStats?->completed_count ?? 0),
                'failed_count' => DB::table('failed_jobs')->count(),
                'pending_count' => DB::table('jobs')->count(),
                'avg_duration_ms' => (int) ($historyStats?->avg_duration_ms ?? 0),
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

    public function destroyHistory(JobRun $jobRun): RedirectResponse
    {
        $jobRun->delete();

        return back()->with('success', 'Job run history removed.');
    }

    public function destroyAllHistory(): RedirectResponse
    {
        $deleted = JobRun::query()->count();

        if ($deleted === 0) {
            return back()->with('error', 'No job run history to remove.');
        }

        JobRun::query()->delete();

        return back()->with('success', "All {$deleted} job run history records removed.");
    }

    public function destroyPending(int $id): RedirectResponse
    {
        $deleted = DB::table('jobs')->where('id', $id)->delete();

        if ($deleted === 0) {
            return back()->with('error', 'Pending job not found.');
        }

        return back()->with('success', 'Pending job removed.');
    }

    public function destroyAllPending(): RedirectResponse
    {
        $deleted = DB::table('jobs')->delete();

        if ($deleted === 0) {
            return back()->with('error', 'No pending jobs to remove.');
        }

        return back()->with('success', "All {$deleted} pending jobs removed.");
    }
}
