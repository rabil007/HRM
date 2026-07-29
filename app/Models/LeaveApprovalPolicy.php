<?php

namespace App\Models;

use App\Models\Concerns\LogsActivityWithCompany;
use Database\Factories\LeaveApprovalPolicyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Support\LogOptions;

class LeaveApprovalPolicy extends Model
{
    /** @use HasFactory<LeaveApprovalPolicyFactory> */
    use HasFactory;

    use LogsActivityWithCompany;
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'name',
        'description',
        'is_default',
        'status',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'is_default' => 'boolean',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'company_id',
                'name',
                'description',
                'is_default',
                'status',
                'created_by',
                'updated_by',
            ])
            ->logOnlyDirty();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(LeaveApprovalPolicyStep::class)
            ->orderBy('sequence');
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class, 'leave_approval_policy_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function markAsCompanyDefault(): void
    {
        DB::transaction(function (): void {
            // Lock a stable company-owned row so concurrent default switches serialize.
            Company::query()
                ->whereKey($this->company_id)
                ->lockForUpdate()
                ->firstOrFail();

            self::query()
                ->where('company_id', $this->company_id)
                ->whereKeyNot($this->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);

            $this->forceFill([
                'is_default' => true,
                'status' => 'active',
            ])->save();
        });
    }

    public function isSafelyDeletable(): bool
    {
        if ($this->departments()->exists()) {
            return false;
        }

        return ! LeaveRequestApproval::query()
            ->where('company_id', $this->company_id)
            ->where(function ($query): void {
                $query
                    ->where('policy_id', $this->id)
                    ->orWhereHas('policyStep', fn ($stepQuery) => $stepQuery->where('leave_approval_policy_id', $this->id));
            })
            ->exists();
    }
}
