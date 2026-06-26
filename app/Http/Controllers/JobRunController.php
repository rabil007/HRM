<?php

namespace App\Http\Controllers;

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
            'tab' => ['nullable', 'string', 'in:history,failed,pending'],
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

    public function destroyFailed(string $uuid): RedirectResponse
    {
        $deleted = DB::table('failed_jobs')->where('uuid', $uuid)->delete();

        if ($deleted === 0) {
            return back()->with('error', 'Failed job not found.');
        }

        return back()->with('success', 'Failed job removed.');
    }
}
