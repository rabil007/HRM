<?php

namespace App\Models;

use App\Models\Concerns\LogsActivityWithCompany;
use Database\Factories\CrewPlanningAssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Support\LogOptions;

class CrewPlanningAssignment extends Model
{
    /** @use HasFactory<CrewPlanningAssignmentFactory> */
    use HasFactory;

    use LogsActivityWithCompany;
    use SoftDeletes;

    protected $guarded = [];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'vessel_id',
                'rank_id',
                'employee_id',
                'relieves_employee_deployment_id',
                'planned_join_date',
                'planned_leave_date',
                'notes',
            ])
            ->logOnlyDirty();
    }

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'vessel_id' => 'integer',
            'rank_id' => 'integer',
            'employee_id' => 'integer',
            'employee_deployment_id' => 'integer',
            'relieves_employee_deployment_id' => 'integer',
            'planned_join_date' => 'date',
            'planned_leave_date' => 'date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function vessel(): BelongsTo
    {
        return $this->belongsTo(Vessel::class);
    }

    public function rank(): BelongsTo
    {
        return $this->belongsTo(Rank::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function employeeDeployment(): BelongsTo
    {
        return $this->belongsTo(EmployeeDeployment::class);
    }

    public function relievedDeployment(): BelongsTo
    {
        return $this->belongsTo(EmployeeDeployment::class, 'relieves_employee_deployment_id');
    }
}
