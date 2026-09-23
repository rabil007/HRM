<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecruitmentRequirementSequence extends Model
{
    protected $fillable = [
        'company_id',
        'year',
        'current_number',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'year' => 'integer',
            'current_number' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
