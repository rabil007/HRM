<?php

namespace App\Models;

use App\Models\Concerns\LogsActivityWithCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Support\LogOptions;

class LeaveRequestApprovalReassignment extends Model
{
    use LogsActivityWithCompany;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'leave_request_id' => 'integer',
            'leave_request_approval_id' => 'integer',
            'sequence' => 'integer',
            'from_approver_employee_id' => 'integer',
            'from_approver_user_id' => 'integer',
            'to_approver_employee_id' => 'integer',
            'to_approver_user_id' => 'integer',
            'reassigned_by_user_id' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'company_id',
                'leave_request_id',
                'leave_request_approval_id',
                'sequence',
                'from_approver_employee_id',
                'to_approver_employee_id',
                'reason',
                'reassigned_by_user_id',
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

    public function leaveRequestApproval(): BelongsTo
    {
        return $this->belongsTo(LeaveRequestApproval::class);
    }

    public function fromApproverEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'from_approver_employee_id');
    }

    public function toApproverEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'to_approver_employee_id');
    }

    public function reassignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reassigned_by_user_id');
    }
}
