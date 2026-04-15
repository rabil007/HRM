<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Spatie\Activitylog\Models\Activity;

class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $companyId = (int) $request->attributes->get('current_company_id');

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

        $logs = Activity::query()
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
            ->paginate(30)
            ->through(function (Activity $log) {
                $changes = $log->attribute_changes?->toArray() ?? [];
                $subject = $log->subject;
                $subjectLabel = null;

                if ($subject) {
                    $subjectLabel = Arr::first(
                        [
                            data_get($subject, 'name'),
                            data_get($subject, 'email'),
                            data_get($subject, 'code'),
                            data_get($subject, 'slug'),
                        ],
                        fn ($v) => is_string($v) && $v !== ''
                    );
                }

                return [
                    'id' => $log->id,
                    'event' => $log->event,
                    'subject_type' => $log->subject_type,
                    'subject_name' => Str::afterLast($log->subject_type, '\\'),
                    'subject_id' => $log->subject_id,
                    'subject_label' => $subjectLabel ?: null,
                    'description' => $log->description ?: null,
                    'causer' => $log->causer ? [
                        'id' => $log->causer->id,
                        'name' => $log->causer->name,
                        'email' => $log->causer->email,
                    ] : null,
                    'old_values' => $changes['old'] ?? null,
                    'new_values' => $changes['attributes'] ?? null,
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
            'logs' => $logs,
            'filters' => $filters,
            'subject_types' => $subjectTypes,
        ]);
    }
}
