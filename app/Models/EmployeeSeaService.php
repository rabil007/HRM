<?php

namespace App\Models;

use Database\Factories\EmployeeSeaServiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmployeeSeaService extends Model
{
    /** @use HasFactory<EmployeeSeaServiceFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'vessel_type_id' => 'integer',
            'rank_id' => 'integer',
            'start_date' => 'date',
            'end_date' => 'date',
            'client_id' => 'integer',
            'total_months' => 'integer',
            'total_days' => 'integer',
            'grt' => 'decimal:2',
            'bhp' => 'integer',
            'is_offshore' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function vesselType(): BelongsTo
    {
        return $this->belongsTo(VesselType::class);
    }

    public function rank(): BelongsTo
    {
        return $this->belongsTo(Rank::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
