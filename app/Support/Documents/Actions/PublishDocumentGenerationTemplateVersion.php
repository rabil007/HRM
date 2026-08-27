<?php

namespace App\Support\Documents\Actions;

use App\Enums\DocumentGenerationTemplateStatus;
use App\Enums\DocumentGenerationTemplateVersionStatus;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use DomainException;
use Illuminate\Support\Facades\DB;

final class PublishDocumentGenerationTemplateVersion
{
    public function handle(DocumentGenerationTemplateVersion $version, ?int $userId = null): DocumentGenerationTemplateVersion
    {
        if (! $version->isDraft()) {
            throw new DomainException('Only draft template versions can be published.');
        }

        return DB::transaction(function () use ($version, $userId): DocumentGenerationTemplateVersion {
            /** @var DocumentGenerationTemplate $template */
            $template = DocumentGenerationTemplate::query()
                ->whereKey($version->document_generation_template_id)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var DocumentGenerationTemplateVersion $lockedVersion */
            $lockedVersion = DocumentGenerationTemplateVersion::query()
                ->whereKey($version->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedVersion->isDraft()) {
                throw new DomainException('Only draft template versions can be published.');
            }

            // 1. Archive any previously published versions for this template
            DocumentGenerationTemplateVersion::query()
                ->where('document_generation_template_id', $template->id)
                ->where('status', DocumentGenerationTemplateVersionStatus::Published)
                ->update([
                    'status' => DocumentGenerationTemplateVersionStatus::Archived,
                    'updated_by' => $userId,
                ]);

            // 2. Publish this version
            $lockedVersion->status = DocumentGenerationTemplateVersionStatus::Published;
            $lockedVersion->published_at = now();
            $lockedVersion->updated_by = $userId;
            $lockedVersion->save();

            // 3. Update parent template with new published version pointer and active status
            $template->published_version_id = $lockedVersion->id;
            $template->status = DocumentGenerationTemplateStatus::Active;
            if ($lockedVersion->content !== null) {
                $template->content = $lockedVersion->content;
            }
            $template->updated_by = $userId;
            $template->save();

            activity('document_templates')
                ->performedOn($template)
                ->causedBy($userId)
                ->withProperties([
                    'action' => 'template_version_published',
                    'version' => $lockedVersion->version,
                ])
                ->log("Published version {$lockedVersion->version} for template {$template->name}");

            return $lockedVersion;
        });
    }
}
