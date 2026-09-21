<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only leave approval reassignment history.
 * Relational history is authoritative; LeaveRequest activity feed records the user-facing event.
 */
class LeaveRequestApprovalReassignment extends Model
{
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
