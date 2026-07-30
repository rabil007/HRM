<?php

namespace App\Support\Payroll\Actions;

use App\Enums\CrewTimesheetSource;
use App\Enums\PayrollCategory;
use App\Models\CrewTimesheet;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Support\Payroll\ApplyManualImportTimesheetAutoApproval;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateCrewTimesheetFinancials
{
    /**
     * @var list<string>
     */
    private const NON_NULLABLE_NUMERIC_FIELDS = [
        'overtime_hours',
        'overtime_amount',
        'additional_amount',
        'deduction_amount',
    ];

    public function __construct(
        private readonly ApplyManualImportTimesheetAutoApproval $autoApproval,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(
        PayrollPeriod $period,
        CrewTimesheet $timesheet,
        array $data,
        User $actor,
        int $companyId,
    ): CrewTimesheet {
        return DB::transaction(function () use ($period, $timesheet, $data, $actor, $companyId): CrewTimesheet {
            $period = PayrollPeriod::query()
                ->whereKey($period->id)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $period->isEditable()) {
                throw ValidationException::withMessages([
                    'period_id' => 'Timesheets can only be edited for draft payroll periods.',
                ]);
            }

            if (($period->payroll_category ?? PayrollCategory::Crew) !== PayrollCategory::Crew) {
                throw ValidationException::withMessages([
                    'period_id' => 'Crew timesheets can only be saved on crew pay periods.',
                ]);
            }

            $timesheet = CrewTimesheet::query()
                ->whereKey($timesheet->id)
                ->where('company_id', $companyId)
                ->where('period_id', $period->id)
                ->lockForUpdate()
                ->firstOrFail();

            $before = [
                'overtime_hours' => $timesheet->overtime_hours,
                'overtime_amount' => $timesheet->overtime_amount,
                'unpaid_leave_days' => $timesheet->unpaid_leave_days,
                'additional_amount' => $timesheet->additional_amount,
                'deduction_amount' => $timesheet->deduction_amount,
                'remarks' => $timesheet->remarks,
            ];

            $attributes = $this->financialAttributesFrom($data, $timesheet);

            $source = $timesheet->resolvedSource() ?? CrewTimesheetSource::Manual;

            if ($this->autoApproval->shouldAutoApprove($source)) {
                $attributes = array_merge(
                    $attributes,
                    $this->autoApproval->approvalAttributes((int) $actor->id),
                );
            }

            if ($attributes !== []) {
                $timesheet->fill($attributes);
                $timesheet->save();
            }

            $fresh = $timesheet->fresh() ?? $timesheet;

            $changed = [];

            foreach ($before as $key => $value) {
                $next = $fresh->getAttribute($key);

                if ((string) ($value ?? '') !== (string) ($next ?? '')) {
                    $changed[$key] = [
                        'from' => $value,
                        'to' => $next,
                    ];
                }
            }

            if ($changed !== []) {
                activity()
                    ->performedOn($fresh)
                    ->causedBy($actor)
                    ->withProperties([
                        'event' => 'crew_timesheet_financials_updated',
                        'company_id' => $companyId,
                        'payroll_period_id' => $period->id,
                        'crew_timesheet_id' => $fresh->id,
                        'employee_id' => $fresh->employee_id,
                        'changed' => $changed,
                    ])
                    ->log('Crew timesheet financial fields updated');
            }

            return $fresh;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function financialAttributesFrom(array $data, CrewTimesheet $timesheet): array
    {
        $attributes = [];

        foreach (self::NON_NULLABLE_NUMERIC_FIELDS as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            $attributes[$key] = $this->normalizeNonNullableNumeric($key, $data[$key]);
        }

        if (array_key_exists('remarks', $data)) {
            $attributes['remarks'] = $data['remarks'];
        }

        if (! $timesheet->isOperationallyLocked()
            && array_key_exists('unpaid_leave_days', $data)) {
            $attributes['unpaid_leave_days'] = $data['unpaid_leave_days'];
        }

        return $attributes;
    }

    private function normalizeNonNullableNumeric(string $key, mixed $value): float|int|string
    {
        if ($value === null || $value === '') {
            return 0;
        }

        if (! is_numeric($value)) {
            throw ValidationException::withMessages([
                $key => "The {$key} must be a number.",
            ]);
        }

        if ((float) $value < 0) {
            throw ValidationException::withMessages([
                $key => "The {$key} must be at least 0.",
            ]);
        }

        return $value;
    }
}
