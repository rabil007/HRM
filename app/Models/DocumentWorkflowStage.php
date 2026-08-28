<?php

namespace App\Models;

use App\Enums\DocumentWorkflowAction;
use App\Enums\DocumentWorkflowCompletionRule;
use App\Enums\DocumentWorkflowStageStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentWorkflowStage extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'document_workflow_request_id',
        'sequence',
        'action',
        'completion_rule',
        'status',
        'started_at',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'action' => DocumentWorkflowAction::class,
            'completion_rule' => DocumentWorkflowCompletionRule::class,
            'status' => DocumentWorkflowStageStatus::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(DocumentWorkflowRequest::class, 'document_workflow_request_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(DocumentWorkflowTask::class);
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }
}
