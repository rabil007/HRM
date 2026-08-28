<?php

namespace App\Models;

use App\Enums\DocumentWorkflowTargetType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Role;

class DocumentWorkflowPresetTarget extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'document_workflow_preset_stage_id',
        'target_type',
        'target_user_id',
        'target_role_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_type' => DocumentWorkflowTargetType::class,
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(DocumentWorkflowPresetStage::class, 'document_workflow_preset_stage_id');
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function targetRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'target_role_id');
    }
}
