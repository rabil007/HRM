<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Support\Attendance\AttendanceOverviewSummary;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class AttendanceOverviewController extends Controller
{
    public function __invoke(Request $request): InertiaResponse
    {
        abort_unless(
            $request->user()?->can('attendance.overview.view'),
            403,
        );

        $companyId = (int) $request->attributes->get('current_company_id');

        return Inertia::render('attendance/overview', [
            'summary' => AttendanceOverviewSummary::forCompany($companyId),
            'can' => [
                'view_records' => $request->user()?->can('attendance.records.view') ?? false,
                'view_leave_requests' => $request->user()?->can('attendance.leave-requests.view') ?? false,
                'approve_leave_requests' => $request->user()?->can('attendance.leave-requests.approve') ?? false,
                'view_calendar' => $request->user()?->can('attendance.leave-requests.view') ?? false,
            ],
        ]);
    }
}
