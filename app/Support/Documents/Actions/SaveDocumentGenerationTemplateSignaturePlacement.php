<?php

namespace App\Support\Documents\Actions;

use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Support\Documents\RecipientRequests\DocumentSignaturePlacementValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Spatie\Activitylog\Models\Activity;

final class SaveDocumentGenerationTemplateSignaturePlacement
{
    /**
     * @param  array{schema_version?: mixed, placements?: mixed}  $config
     */
    public function handle(
        DocumentGenerationTemplateVersion $version,
        array $config,
        ?int $userId = null,
    ): DocumentGenerationTemplateVersion {
        return DB::transaction(function () use ($version, $config, $userId): DocumentGenerationTemplateVersion {
            /** @var DocumentGenerationTemplate $lockedTemplate */
            $lockedTemplate = DocumentGenerationTemplate::query()
                ->whereKey($version->document_generation_template_id)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var DocumentGenerationTemplateVersion $lockedVersion */
            $lockedVersion = DocumentGenerationTemplateVersion::query()
                ->whereKey($version->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $lockedVersion->document_generation_template_id !== (int) $lockedTemplate->id
                || (int) $lockedVersion->company_id !== (int) $lockedTemplate->company_id
            ) {
                throw ValidationException::withMessages([
                    'version' => 'Template version does not match parent template.',
                ]);
            }

            if (! $lockedVersion->isDraft()) {
                throw ValidationException::withMessages([
                    'version' => 'Published or archived template versions cannot be edited.',
                ]);
            }

            if (! $lockedTemplate->isPdfOverlay()) {
                throw ValidationException::withMessages([
                    'template' => 'Cannot save signature placement for a content template.',
                ]);
            }

            $pageCount = (int) ($lockedVersion->source_pdf_page_count ?? 0);

            if ($pageCount < 1) {
                throw ValidationException::withMessages([
                    'signature_placement_config' => 'Template PDF page count is unavailable.',
                ]);
            }

            try {
                $subjectPlacement = DocumentSignaturePlacementValidator::validateSubjectSignature(
                    $config,
                    $pageCount,
                );
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages([
                    'signature_placement_config' => $exception->getMessage(),
                ]);
            }

            $lockedVersion->signature_placement_config = [
                'schema_version' => 1,
                'placements' => [[
                    'id' => $subjectPlacement['id'],
                    'type' => $subjectPlacement['type'],
                    'role' => $subjectPlacement['role'],
                    'page' => $subjectPlacement['page'],
                    'x' => round($subjectPlacement['x'], 6),
                    'y' => round($subjectPlacement['y'], 6),
                    'width' => round($subjectPlacement['width'], 6),
                    'height' => round($subjectPlacement['height'], 6),
                    'required' => $subjectPlacement['required'],
                ]],
            ];
            $lockedVersion->updated_by = $userId;
            $lockedVersion->save();

            $companyId = (int) $lockedTemplate->company_id;
            activity('document_templates')
                ->performedOn($lockedTemplate)
                ->causedBy($userId)
                ->tap(function (Activity $activity) use ($companyId): void {
                    $activity->company_id = $companyId;
                })
                ->withProperties([
                    'action' => 'template_signature_placement_updated',
                    'template_id' => $lockedTemplate->id,
                    'version' => $lockedVersion->version,
                    'page' => $subjectPlacement['page'],
                    'page_count' => $pageCount,
                ])
                ->log("Updated employee signature placement for template {$lockedTemplate->name} (v{$lockedVersion->version})");

            return $lockedVersion;
        });
    }
}
