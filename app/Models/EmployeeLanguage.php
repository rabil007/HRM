<?php

namespace App\Models;

use Database\Factories\EmployeeLanguageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeLanguage extends Model
{
    /** @use HasFactory<EmployeeLanguageFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_spoken' => 'boolean',
            'is_written' => 'boolean',
            'is_understood' => 'boolean',
            'is_mother_tongue' => 'boolean',
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
}
