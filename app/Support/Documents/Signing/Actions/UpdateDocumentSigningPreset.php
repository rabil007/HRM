<?php

namespace App\Support\Documents\Signing\Actions;

use App\Models\DocumentSigningPreset;
use App\Models\User;
use App\Support\Documents\Signing\DocumentSigningPresetActivityLogger;
use App\Support\Documents\Signing\DocumentSigningPresetValidator;
use Illuminate\Support\Facades\DB;

final class UpdateDocumentSigningPreset
{
    public function __construct(
        private DocumentSigningPresetValidator $validator = new DocumentSigningPresetValidator,
        private DocumentSigningPresetActivityLogger $activityLogger = new DocumentSigningPresetActivityLogger,
        private StoreDocumentSigningPreset $store = new StoreDocumentSigningPreset,
    ) {}

    /**
     * @param  list<array{recipient_role: string, target_type?: string|null, target_user_id?: int|null}>  $steps
     */
    public function handle(
        DocumentSigningPreset $preset,
        User $actor,
        int $companyId,
        string $name,
        ?string $description,
        array $steps,
    ): DocumentSigningPreset {
        abort_unless((int) $preset->company_id === $companyId, 404);

        $this->validator->validateSteps($companyId, $steps);

        return DB::transaction(function () use ($preset, $actor, $companyId, $name, $description, $steps): DocumentSigningPreset {
            /** @var DocumentSigningPreset $locked */
            $locked = DocumentSigningPreset::query()
                ->whereKey($preset->id)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->firstOrFail();

            $locked->update([
                'name' => $name,
                'description' => $description,
                'updated_by' => $actor->id,
            ]);

            $this->store->syncSteps($locked, $companyId, $steps);

            $this->activityLogger->log(
                description: 'Document signing preset updated',
                event: 'signing_preset_updated',
                preset: $locked->fresh(),
                actor: $actor,
            );

            return $locked->fresh(['steps.targetUser']);
        });
    }
}
