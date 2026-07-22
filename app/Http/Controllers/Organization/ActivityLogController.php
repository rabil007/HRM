<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Support\Activity\ActivityChangePresenter;
use App\Support\Pagination\ResolvesPerPage;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Spatie\Activitylog\Models\Activity;

class ActivityLogController extends Controller
{
    use ResolvesPerPage;

    public function index(Request $request)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $perPage = $this->resolvePerPage($request, default: 30);

        $filters = [
            'q' => $request->string('q')->toString(),
            'event' => $request->string('event')->toString(),
            'subject' => $request->string('subject')->toString(),
            'date_from' => $request->string('date_from')->toString(),
            'date_to' => $request->string('date_to')->toString(),
        ];

        $today = CarbonImmutable::today()->toDateString();
        $dateFrom = $filters['date_from'] ?: $today;
        $dateTo = $filters['date_to'] ?: $today;

        $filters['date_from'] = $dateFrom;
        $filters['date_to'] = $dateTo;

        $paginator = Activity::query()
            ->where('company_id', $companyId)
            ->whereDate('created_at', '>=', $dateFrom)
            ->whereDate('created_at', '<=', $dateTo)
            ->when($filters['event'], fn ($query, $event) => $query->where('event', $event))
            ->when($filters['subject'], fn ($query, $subject) => $query->where('subject_type', $subject))
            ->when($filters['q'], function ($query, $q) {
                $query->where(function ($sub) use ($q) {
                    $sub
                        ->where('description', 'like', '%'.$q.'%')
                        ->orWhere('subject_type', 'like', '%'.$q.'%')
                        ->orWhereHas('causer', fn ($u) => $u->where('name', 'like', '%'.$q.'%')->orWhere('email', 'like', '%'.$q.'%'));
                });
            })
            ->with(['causer:id,name,email', 'subject'])
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();

        ActivityChangePresenter::presentLogs(
            collect($paginator->items()),
            $companyId,
        );

        $logs = $paginator->through(function (Activity $log) {
            $presented = ActivityChangePresenter::toRecentActivityArray($log);

            return [
                'id' => $log->id,
                'event' => $log->event,
                'subject_type' => $log->subject_type,
                'subject_name' => Str::afterLast((string) $log->subject_type, '\\'),
                'subject_id' => $log->subject_id,
                'subject_label' => ActivityChangePresenter::subjectLabel($log->subject),
                'description' => $log->description ?: null,
                'causer' => $presented['causer'],
                'old_values' => $presented['old_values'],
                'new_values' => $presented['new_values'],
                'ip' => null,
                'created_at' => $log->created_at,
            ];
        });

        $subjectTypes = Activity::query()
            ->where('company_id', $companyId)
            ->select('subject_type')
            ->distinct()
            ->orderBy('subject_type')
            ->pluck('subject_type');

        return Inertia::render('organization/activity-logs', [
            'logs' => $logs->items(),
            'pagination' => $this->paginationMeta($paginator),
            'filters' => $filters,
            'subject_types' => $subjectTypes,
        ]);
    }
}
