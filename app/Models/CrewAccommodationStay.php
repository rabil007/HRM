<?php

namespace App\Models;

use App\Enums\CrewAccommodationStatus;
use App\Enums\CrewAccommodationStayType;
use App\Models\Concerns\LogsActivityWithCompany;
use App\Support\CrewAccommodation\CrewAccommodationStayIntegrity;
use Database\Factories\CrewAccommodationStayFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Support\LogOptions;

class CrewAccommodationStay extends Model
{
    /** @use HasFactory<CrewAccommodationStayFactory> */
    use HasFactory;

    use LogsActivityWithCompany;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::saving(function (CrewAccommodationStay $stay): void {
            CrewAccommodationStayIntegrity::assertValid($stay);
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'company_id',
                'crew_assignment_id',
                'hotel_id',
                'room_type_id',
                'stay_type',
                'accommodation_status',
                'check_in_date',
                'check_out_date',
                'started_from_phase_id',
            ])
            ->logOnlyDirty();
    }

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'crew_assignment_id' => 'integer',
            'hotel_id' => 'integer',
            'room_type_id' => 'integer',
            'started_from_phase_id' => 'integer',
            'created_by' => 'integer',
            'updated_by' => 'integer',
            'stay_type' => CrewAccommodationStayType::class,
            'accommodation_status' => CrewAccommodationStatus::class,
            'check_in_date' => 'date',
            'check_out_date' => 'date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(CrewAssignment::class, 'crew_assignment_id');
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    public function startedFromPhase(): BelongsTo
    {
        return $this->belongsTo(CrewAssignmentPhase::class, 'started_from_phase_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * @param  Builder<CrewAccommodationStay>  $query
     * @return Builder<CrewAccommodationStay>
     */
    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }
}
