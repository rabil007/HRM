<?php

namespace App\Models;

use App\Models\Concerns\LogsActivityWithCompany;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Support\LogOptions;

class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use HasFactory;

    use LogsActivityWithCompany;
    use SoftDeletes;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'hire_date' => 'date',
            'termination_date' => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'company_id',
                'user_id',
                'branch_id',
                'department_id',
                'position_id',
                'rank_id',
                'manager_id',
                'employee_no',
                'name',
                'date_of_birth',
                'hire_date',
                'spouse_name',
                'personal_email',
                'work_email',
                'phone',
                'gender_id',
                'visa_type_id',
                'company_visa_type_id',
                'nearest_airport',
                'phone_home_country',
                'emergency_contact',
                'emergency_phone',
                'address',
                'marital_status',
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

    public function hikvisionPerson(): BelongsTo
    {
        return $this->belongsTo(HikvisionPerson::class);
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

    public function rank(): BelongsTo
    {
        return $this->belongsTo(Rank::class);
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

    public function deployments(): HasMany
    {
        return $this->hasMany(EmployeeDeployment::class);
    }

    public function currentContract(): HasOne
    {
        return $this->hasOne(EmployeeContract::class)->ofMany(
            ['id' => 'max'],
            fn ($q) => $q->where('status', 'active')
        );
    }

    public function crewTimesheets(): HasMany
    {
        return $this->hasMany(CrewTimesheet::class);
    }

    public function religionRef(): BelongsTo
    {
        return $this->belongsTo(Religion::class, 'religion_id');
    }

    public function genderRef(): BelongsTo
    {
        return $this->belongsTo(Gender::class, 'gender_id');
    }

    public function visaTypeRef(): BelongsTo
    {
        return $this->belongsTo(VisaType::class, 'visa_type_id');
    }

    public function companyVisaTypeRef(): BelongsTo
    {
        return $this->belongsTo(CompanyVisaType::class, 'company_visa_type_id');
    }

    public function approvalLocations(): BelongsToMany
    {
        return $this->belongsToMany(ApprovalLocation::class, 'employee_approval_location');
    }

    public function sssaOptions(): BelongsToMany
    {
        return $this->belongsToMany(SssaOption::class, 'employee_sssa_option');
    }

    public function nationalityRef(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'nationality_id');
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(EmployeeBankAccount::class);
    }

    public function primaryBankAccount(): HasOne
    {
        return $this->hasOne(EmployeeBankAccount::class)->ofMany(
            ['id' => 'max'],
            fn ($q) => $q->where('is_primary', true)
        );
    }

    public function employeeProfileTemplate(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfileTemplate::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class)->latestUpload();
    }

    public function educationQualifications(): HasMany
    {
        return $this->hasMany(EmployeeEducationQualification::class)->orderByDesc('issue_date')->orderByDesc('id');
    }

    public function workExperiences(): HasMany
    {
        return $this->hasMany(EmployeeWorkExperience::class)->orderBy('sort_order')->orderByDesc('date_from')->orderByDesc('id');
    }

    public function vaccinations(): HasMany
    {
        return $this->hasMany(EmployeeVaccination::class)->orderBy('sort_order')->orderByDesc('id');
    }

    public function languages(): HasMany
    {
        return $this->hasMany(EmployeeLanguage::class)->orderBy('sort_order')->orderByDesc('id');
    }

    public function seaServices(): HasMany
    {
        return $this->hasMany(EmployeeSeaService::class)->orderBy('sort_order')->orderByDesc('id');
    }

    public function trainings(): HasMany
    {
        return $this->hasMany(EmployeeTraining::class)->orderBy('sort_order')->orderByDesc('issue_date')->orderByDesc('id');
    }

    /**
     * @return list<array{id: int, name: string, employee_no: string|null}>
     */
    public static function optionsForHikvisionLinking(int $companyId, ?int $currentPersonId = null): array
    {
        return self::query()
            ->where('company_id', $companyId)
            ->where(function (Builder $query) use ($currentPersonId): void {
                $query->whereNull('hikvision_person_id');

                if ($currentPersonId !== null) {
                    $query->orWhere('hikvision_person_id', $currentPersonId);
                }
            })
            ->orderBy('name')
            ->get(['id', 'name', 'employee_no'])
            ->map(fn (self $employee): array => [
                'id' => $employee->id,
                'name' => $employee->name,
                'employee_no' => $employee->employee_no,
            ])
            ->values()
            ->all();
    }
}
