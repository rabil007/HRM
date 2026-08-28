<?php

namespace App\Models;

use App\Enums\DocumentWorkflowAction;
use App\Enums\DocumentWorkflowCompletionRule;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentWorkflowPresetStage extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'document_workflow_preset_id',
        'sequence',
        'action',
        'completion_rule',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => DocumentWorkflowAction::class,
            'completion_rule' => DocumentWorkflowCompletionRule::class,
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function preset(): BelongsTo
    {
        return $this->belongsTo(DocumentWorkflowPreset::class, 'document_workflow_preset_id');
    }

    public function targets(): HasMany
    {
        return $this->hasMany(DocumentWorkflowPresetTarget::class);
    }
}
