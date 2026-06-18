<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vessel extends Model
{
    use SoftDeletes;

    protected $guarded = [];

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
