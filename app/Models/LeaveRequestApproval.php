<?php

namespace App\Models;

use App\Enums\LeaveApprovalApproverType;
use App\Enums\LeaveRequestApprovalStatus;
use App\Models\Concerns\LogsActivityWithCompany;
use Database\Factories\LeaveRequestApprovalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Support\LogOptions;

class LeaveRequestApproval extends Model
{
    /** @use HasFactory<LeaveRequestApprovalFactory> */
    use HasFactory;

    use LogsActivityWithCompany;

    protected $fillable = [
        'company_id',
        'leave_request_id',
        'sequence',
        'approver_type',
        'approver_employee_id',
        'approver_user_id',
        'source_department_id',
        'policy_step_id',
        'status',
        'is_required',
        'acted_at',
        'comments',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'leave_request_id' => 'integer',
            'sequence' => 'integer',
            'approver_type' => LeaveApprovalApproverType::class,
            'approver_employee_id' => 'integer',
            'approver_user_id' => 'integer',
            'source_department_id' => 'integer',
            'policy_step_id' => 'integer',
            'status' => LeaveRequestApprovalStatus::class,
            'is_required' => 'boolean',
            'acted_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'company_id',
                'leave_request_id',
                'sequence',
                'approver_type',
                'approver_employee_id',
                'approver_user_id',
                'source_department_id',
                'policy_step_id',
                'status',
                'is_required',
                'acted_at',
                'comments',
            ])
            ->logOnlyDirty();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }

    public function approverEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approver_employee_id');
    }

    public function approverUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }

    public function sourceDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'source_department_id');
    }

    public function policyStep(): BelongsTo
    {
        return $this->belongsTo(LeaveApprovalPolicyStep::class, 'policy_step_id');
    }
}
