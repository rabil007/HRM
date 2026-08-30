<?php

namespace App\Support\Documents\Signing\Actions;

use App\Models\DocumentGenerationTemplateVersion;
use App\Models\DocumentSigningPreset;
use App\Models\DocumentSigningPresetStep;
use App\Models\User;
use App\Support\Documents\Signing\DocumentSigningPresetActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DeleteDocumentSigningPreset
{
    public function __construct(
        private DocumentSigningPresetActivityLogger $activityLogger = new DocumentSigningPresetActivityLogger,
    ) {}

    public function handle(DocumentSigningPreset $preset, User $actor, int $companyId): void
    {
        abort_unless((int) $preset->company_id === $companyId, 404);

        DB::transaction(function () use ($preset, $actor, $companyId): void {
            /** @var DocumentSigningPreset $locked */
            $locked = DocumentSigningPreset::query()
                ->whereKey($preset->id)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->signingFlows()->exists()) {
                throw ValidationException::withMessages([
                    'preset' => 'This preset has already been used and cannot be deleted. Deactivate it instead.',
                ]);
            }

            if (DocumentGenerationTemplateVersion::query()
                ->where('company_id', $companyId)
                ->where('document_signing_preset_id', $locked->id)
                ->exists()
            ) {
                throw ValidationException::withMessages([
                    'preset' => 'Preset is used by a document template version. Deactivate it instead.',
                ]);
            }

            DocumentSigningPresetStep::query()
                ->where('document_signing_preset_id', $locked->id)
                ->delete();

            $this->activityLogger->log(
                description: 'Document signing preset deleted',
                event: 'signing_preset_deleted',
                preset: $locked,
                actor: $actor,
            );

            $locked->delete();
        });
    }
}
