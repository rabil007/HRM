<?php

namespace App\Models;

use App\Models\Concerns\LogsActivityWithCompany;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
                'spouse_name',
                'spouse_birthdate',
                'dependent_children_count',
                'personal_email',
                'work_email',
                'phone',
                'gender_id',
                'nearest_airport',
                'phone_home_country',
                'cv_source',
                'emergency_contact',
                'emergency_phone',
                'emergency_contact_home_country',
                'emergency_phone_home_country',
                'address',
                'marital_status',
                'bank_id',
                'iban',
                'emirates_id',
                'passport_number',
                'labor_card_number',
                'status',
                'termination_date',
                'termination_reason',
                'place_of_birth',
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

    public function contracts(): HasMany
    {
        return $this->hasMany(EmployeeContract::class);
    }

    public function currentContract(): HasOne
    {
        return $this->hasOne(EmployeeContract::class)->ofMany(
            ['id' => 'max'],
            fn ($q) => $q->where('status', 'active')
        );
    }

    public function religionRef(): BelongsTo
    {
        return $this->belongsTo(Religion::class, 'religion_id');
    }

    public function genderRef(): BelongsTo
    {
        return $this->belongsTo(Gender::class, 'gender_id');
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }
}
