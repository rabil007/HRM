<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Support\Activity\ActivityChangePresenter;
use App\Support\Activity\ActivityLogIntelligence;
use App\Support\Activity\ActivityLogQuery;
use App\Support\Pagination\ResolvesPerPage;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

class ActivityLogController extends Controller
{
    use ResolvesPerPage;

    public function index(Request $request, ActivityLogQuery $activityLogQuery): Response
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $perPage = $this->resolvePerPage($request, default: 30);
        $result = $activityLogQuery->for($request, $companyId, $perPage);
        $paginator = $result['paginator'];

        ActivityChangePresenter::presentLogs(
            collect($paginator->items()),
            $companyId,
            $request->user(),
        );

        $logs = $paginator->through(
            fn (Activity $log): array => ActivityLogIntelligence::present($log, $request->user()),
        );

        return Inertia::render('organization/activity-logs', [
            'logs' => $logs->items(),
            'pagination' => $this->paginationMeta($paginator),
            'filters' => $result['filters'],
            'modules' => $result['modules'],
            'users' => $result['users'],
            'summary' => $result['summary'],
        ]);
    }
}
