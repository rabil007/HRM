<?php

namespace App\Models;

use App\Models\Concerns\LogsActivityWithCompany;
use Database\Factories\EmployeeLanguageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Support\LogOptions;

class EmployeeLanguage extends Model
{
    /** @use HasFactory<EmployeeLanguageFactory> */
    use HasFactory;

    use LogsActivityWithCompany;
    use SoftDeletes;

    protected $guarded = [];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'company_id',
                'employee_id',
                'sort_order',
                'language_name',
                'is_spoken',
                'is_written',
                'is_understood',
                'is_mother_tongue',
            ])
            ->logOnlyDirty();
    }

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
