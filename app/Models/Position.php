<?php

namespace App\Models;

use App\Models\Concerns\LogsActivityWithCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Support\LogOptions;

class Position extends Model
{
    /** @use HasFactory */
    use HasFactory;

    use LogsActivityWithCompany;
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'department_id',
        'title',
        'description',
        'grade',
        'min_salary',
        'max_salary',
        'status',
        'is_crew_position',
        'max_tour_of_duty_days',
        'attachment_path',
        'attachment_original_name',
        'attachment_mime_type',
        'attachment_size_bytes',
        'attachment_checksum',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_crew_position' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_crew_position' => 'boolean',
            'max_tour_of_duty_days' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'company_id',
                'department_id',
                'title',
                'description',
                'grade',
                'min_salary',
                'max_salary',
                'status',
                'is_crew_position',
                'max_tour_of_duty_days',
                'attachment_original_name',
                'attachment_mime_type',
                'attachment_size_bytes',
            ])
            ->logOnlyDirty();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
