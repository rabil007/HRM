<?php

namespace App\Models;

use App\Enums\LeaveApprovalApproverType;
use App\Models\Concerns\LogsActivityWithCompany;
use Database\Factories\LeaveApprovalPolicyStepFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Support\LogOptions;

class LeaveApprovalPolicyStep extends Model
{
    /** @use HasFactory<LeaveApprovalPolicyStepFactory> */
    use HasFactory;

    use LogsActivityWithCompany;

    protected $fillable = [
        'company_id',
        'leave_approval_policy_id',
        'sequence',
        'approver_type',
        'approver_employee_id',
        'is_required',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'leave_approval_policy_id' => 'integer',
            'sequence' => 'integer',
            'approver_type' => LeaveApprovalApproverType::class,
            'approver_employee_id' => 'integer',
            'is_required' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'company_id',
                'leave_approval_policy_id',
                'sequence',
                'approver_type',
                'approver_employee_id',
                'is_required',
            ])
            ->logOnlyDirty();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(LeaveApprovalPolicy::class, 'leave_approval_policy_id');
    }

    public function approverEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approver_employee_id');
    }
}
