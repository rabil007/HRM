<?php

namespace App\Models;

use App\Models\Concerns\LogsActivityWithCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Support\LogOptions;

class Company extends Model
{
    /** @use HasFactory */
    use HasFactory;

    use LogsActivityWithCompany;
    use SoftDeletes;

    protected $guarded = [];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'name',
                'slug',
                'logo',
                'industry',
                'company_size',
                'registration_number',
                'tax_id',
                'country_id',
                'city',
                'address',
                'phone',
                'email',
                'website',
                'currency_id',
                'timezone',
                'fiscal_year_start',
                'payroll_cycle',
                'working_days',
                'wps_agent_code',
                'wps_mol_uid',
                'status',
                'deleted_at',
            ])
            ->logOnlyDirty();
    }

    protected function casts(): array
    {
        return [
            'working_days' => 'array',
        ];
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['status'])
            ->withTimestamps();
    }
}
