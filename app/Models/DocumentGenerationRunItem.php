<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentGenerationRunItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'document_generation_run_id',
        'employee_id',
        'status',
        'document_instance_id',
        'error_code',
        'error_message',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(DocumentGenerationRun::class, 'document_generation_run_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(DocumentInstance::class, 'document_instance_id');
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }
}
