<?php

namespace App\Models;

use Database\Factories\EmployeeVaccinationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeVaccination extends Model
{
    /** @use HasFactory<EmployeeVaccinationFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'first_dose_date' => 'date',
            'second_dose_date' => 'date',
            'booster_dose_date' => 'date',
            'sort_order' => 'integer',
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

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }
}
