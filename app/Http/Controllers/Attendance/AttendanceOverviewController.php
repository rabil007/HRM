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
        $user = $request->user();
        abort_unless($user !== null, 403);

        return Inertia::render('attendance/overview', [
            'summary' => AttendanceOverviewSummary::forCompany($companyId, $user),
            'can' => [
                'view_records' => $user->can('attendance.records.view'),
                'view_leave_requests' => $user->can('attendance.leave-requests.view'),
                'approve_leave_requests' => $user->can('attendance.leave-requests.approve'),
                'view_calendar' => $user->can('attendance.leave-requests.view'),
            ],
        ]);
    }
}
