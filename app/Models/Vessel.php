<?php

namespace App\Models;

use App\Models\Concerns\LogsActivityWithCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Support\LogOptions;

class Vessel extends Model
{
    use LogsActivityWithCompany;
    use SoftDeletes;

    protected $guarded = [];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'name',
                'vessel_type_id',
                'grt',
                'bhp',
                'is_active',
            ])
            ->logOnlyDirty();
    }

    protected function casts(): array
    {
        return [
            'vessel_type_id' => 'integer',
            'grt' => 'decimal:2',
            'bhp' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function vesselType(): BelongsTo
    {
        return $this->belongsTo(VesselType::class);
    }

    public function seaServices(): HasMany
    {
        return $this->hasMany(EmployeeSeaService::class);
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(EmployeeDeployment::class);
    }

    public function manning(): HasMany
    {
        return $this->hasMany(VesselManning::class);
    }

    public static function normalizeName(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($name)) ?? '');
    }
}
