<?php

namespace App\Support\Documents\Signing\Actions;

use App\Enums\DocumentSigningPresetStatus;
use App\Models\DocumentSigningPreset;
use App\Models\User;
use App\Support\Documents\Signing\DocumentSigningPresetActivityLogger;
use Illuminate\Support\Facades\DB;

final class ActivateDocumentSigningPreset
{
    public function __construct(
        private DocumentSigningPresetActivityLogger $activityLogger = new DocumentSigningPresetActivityLogger,
    ) {}

    public function handle(DocumentSigningPreset $preset, User $actor, int $companyId): DocumentSigningPreset
    {
        abort_unless((int) $preset->company_id === $companyId, 404);

        return DB::transaction(function () use ($preset, $actor, $companyId): DocumentSigningPreset {
            /** @var DocumentSigningPreset $locked */
            $locked = DocumentSigningPreset::query()
                ->whereKey($preset->id)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->firstOrFail();

            $locked->update([
                'status' => DocumentSigningPresetStatus::Active,
                'updated_by' => $actor->id,
            ]);

            $this->activityLogger->log(
                description: 'Document signing preset activated',
                event: 'signing_preset_activated',
                preset: $locked->fresh(),
                actor: $actor,
            );

            return $locked->fresh(['steps.targetUser']);
        });
    }
}
