<?php

namespace App\Support\Documents\Signing;

use App\Enums\DocumentSigningPresetStatus;
use App\Models\DocumentSigningPreset;

final class DocumentSigningPresetPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function detail(DocumentSigningPreset $preset): array
    {
        $preset->loadMissing(['steps.targetUser:id,name,email']);

        $steps = $preset->steps->map(fn ($step): array => [
            'sequence' => (int) $step->sequence,
            'recipient_role' => $step->recipient_role->value,
            'recipient_role_label' => match ($step->recipient_role->value) {
                'subject' => 'Subject employee',
                'manager' => 'Department manager',
                'company_signatory' => 'Company signatory',
                default => $step->recipient_role->value,
            },
            'target_type' => $step->target_type->value,
            'target_user_id' => $step->target_user_id,
            'target_user' => $step->targetUser !== null ? [
                'id' => $step->targetUser->id,
                'name' => $step->targetUser->name,
                'email' => $step->targetUser->email,
            ] : null,
        ])->values()->all();

        return [
            'id' => $preset->id,
            'name' => $preset->name,
            'description' => $preset->description,
            'status' => $preset->status->value,
            'status_label' => $preset->status->label(),
            'is_active' => $preset->status === DocumentSigningPresetStatus::Active,
            'routing_summary' => $this->routingSummary($steps),
            'steps' => $steps,
            'created_at' => $preset->created_at?->toIso8601String(),
            'updated_at' => $preset->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  list<array{recipient_role_label: string}>  $steps
     */
    public function routingSummary(array $steps): string
    {
        return collect($steps)
            ->pluck('recipient_role_label')
            ->implode(' → ');
    }
}
