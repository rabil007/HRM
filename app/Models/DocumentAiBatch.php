<?php

namespace App\Models;

use App\Enums\DocumentAiBatchStatus;
use App\Support\EmployeeDocuments\DocumentAiBatchLifecycle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentAiBatch extends Model
{
    protected $fillable = [
        'company_id',
        'user_id',
        'employee_id',
        'batch_request_id',
        'status',
        'total_items',
        'completed_items',
        'failed_items',
        'expires_at',
    ];

    protected static function booted(): void
    {
        static::deleting(function (DocumentAiBatch $batch): void {
            DocumentAiBatchLifecycle::deleteTemporaryFiles($batch);
        });
    }

    protected function casts(): array
    {
        return [
            'status' => DocumentAiBatchStatus::class,
            'expires_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(DocumentAiBatchItem::class);
    }
}
