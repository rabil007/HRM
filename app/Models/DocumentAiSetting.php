<?php

namespace App\Models;

use App\Enums\DocumentAiMode;
use App\Models\Concerns\LogsActivityWithCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Support\LogOptions;

class DocumentAiSetting extends Model
{
    use LogsActivityWithCompany;

    protected $fillable = [
        'company_id',
        'mode',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'mode' => DocumentAiMode::class,
            'updated_by' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['mode'])
            ->logOnlyDirty();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
