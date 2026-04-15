<?php

namespace App\Models;

use App\Models\Concerns\LogsActivityWithCompany;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Support\LogOptions;

class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use HasFactory;

    use LogsActivityWithCompany;

    protected $guarded = [];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'company_id',
                'user_id',
                'branch_id',
                'department_id',
                'position_id',
                'manager_id',
                'employee_no',
                'first_name',
                'last_name',
                'date_of_birth',
                'gender',
                'nationality',
                'marital_status',
                'personal_email',
                'work_email',
                'phone',
                'emergency_contact',
                'emergency_phone',
                'address',
                'hire_date',
                'probation_end_date',
                'contract_type',
                'contract_end_date',
                'basic_salary',
                'housing_allowance',
                'transport_allowance',
                'other_allowances',
                'bank_name',
                'bank_account_name',
                'iban',
                'visa_number',
                'visa_expiry',
                'visa_type',
                'emirates_id',
                'emirates_id_expiry',
                'passport_number',
                'passport_expiry',
                'work_permit_number',
                'work_permit_expiry',
                'labor_card_number',
                'labor_card_expiry',
                'mohre_uid',
                'status',
                'termination_date',
                'termination_reason',
            ])
            ->logOnlyDirty();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    public function directReports(): HasMany
    {
        return $this->hasMany(Employee::class, 'manager_id');
    }
}
