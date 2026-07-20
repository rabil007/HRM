<?php

namespace App\Support\Payroll\CrewTimeline;

use App\Models\CrewTimesheetPreparation;
use App\Models\PayrollPeriod;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class CrewTimesheetPreparationReviewQuery
{
    public function findForReview(
        PayrollPeriod $period,
        int $preparationId,
        int $companyId,
    ): CrewTimesheetPreparation {
        if ((int) $period->company_id !== $companyId) {
            throw (new ModelNotFoundException)->setModel(PayrollPeriod::class, [$period->id]);
        }

        return CrewTimesheetPreparation::query()
            ->whereKey($preparationId)
            ->where('company_id', $companyId)
            ->where('payroll_period_id', $period->id)
            ->with([
                'preparedBy:id,name',
                'submittedBy:id,name',
                'approvedBy:id,name',
                'returnedBy:id,name',
                'appliedBy:id,name',
                'lines' => function ($query) use ($companyId): void {
                    $query->where('company_id', $companyId)
                        ->with([
                            'employee:id,employee_no,name,position_id',
                            'employee.position:id,name',
                            'assignment:id,assignment_no,vessel_id,rank_id',
                            'assignment.vessel:id,name',
                            'assignment.rank:id,name',
                        ])
                        ->orderBy('employee_id')
                        ->orderBy('from_date')
                        ->orderBy('id');
                },
            ])
            ->withCount([
                'crewTimesheets as linked_timesheet_count' => function ($query) use ($companyId, $period): void {
                    $query->where('company_id', $companyId)
                        ->where('period_id', $period->id);
                },
            ])
            ->firstOrFail();
    }

    public function latestForPeriod(
        PayrollPeriod $period,
        int $companyId,
    ): ?CrewTimesheetPreparation {
        if ((int) $period->company_id !== $companyId) {
            return null;
        }

        return CrewTimesheetPreparation::query()
            ->where('company_id', $companyId)
            ->where('payroll_period_id', $period->id)
            ->with([
                'preparedBy:id,name',
                'submittedBy:id,name',
                'approvedBy:id,name',
                'returnedBy:id,name',
                'appliedBy:id,name',
                'lines' => function ($query) use ($companyId): void {
                    $query->where('company_id', $companyId)
                        ->select([
                            'id',
                            'crew_timesheet_preparation_id',
                            'company_id',
                            'days',
                            'warning_code',
                            'pay_category',
                        ]);
                },
            ])
            ->withCount([
                'crewTimesheets as linked_timesheet_count' => function ($query) use ($companyId, $period): void {
                    $query->where('company_id', $companyId)
                        ->where('period_id', $period->id);
                },
            ])
            ->orderByDesc('version')
            ->first();
    }
}
