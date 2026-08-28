<?php

namespace App\Models;

use App\Enums\DocumentWorkflowTaskStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentWorkflowTask extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'document_workflow_stage_id',
        'assignee_user_id',
        'assignee_name_snapshot',
        'status',
        'decided_by',
        'decision_actor_name_snapshot',
        'decided_at',
        'decision_notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DocumentWorkflowTaskStatus::class,
            'decided_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(DocumentWorkflowStage::class, 'document_workflow_stage_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_user_id');
    }

    public function decidedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }
}
