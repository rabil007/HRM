<?php

namespace App\Models;

use App\Models\Concerns\LogsActivityWithCompany;
use Database\Factories\CompanyLeaveApprovalSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Support\LogOptions;

class CompanyLeaveApprovalSetting extends Model
{
    /** @use HasFactory<CompanyLeaveApprovalSettingFactory> */
    use HasFactory;

    use LogsActivityWithCompany;

    protected $fillable = [
        'company_id',
        'default_hr_approver_employee_id',
        'fallback_approver_employee_id',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'default_hr_approver_employee_id' => 'integer',
            'fallback_approver_employee_id' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'company_id',
                'default_hr_approver_employee_id',
                'fallback_approver_employee_id',
                'updated_by',
            ])
            ->logOnlyDirty();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function defaultHrApprover(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'default_hr_approver_employee_id');
    }

    public function fallbackApprover(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'fallback_approver_employee_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Read-only settings lookup for workflow resolution.
     * Does not create a database row when settings are missing.
     */
    public static function findForCompany(int $companyId): self
    {
        $existing = self::query()
            ->where('company_id', $companyId)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return new self([
            'company_id' => $companyId,
            'default_hr_approver_employee_id' => null,
            'fallback_approver_employee_id' => null,
        ]);
    }

    /**
     * Persistable settings row for the settings management UI / explicit writes.
     */
    public static function forCompany(int $companyId): self
    {
        return self::query()->firstOrCreate(
            ['company_id' => $companyId],
            [
                'default_hr_approver_employee_id' => null,
                'fallback_approver_employee_id' => null,
            ],
        );
    }
}
