<?php

namespace App\Support\Documents\Actions;

use App\Enums\DocumentGenerationTemplateStatus;
use App\Models\DocumentGenerationTemplate;
use App\Models\User;

final class DuplicateDocumentGenerationTemplate
{
    public function handle(DocumentGenerationTemplate $template, ?User $actor = null): DocumentGenerationTemplate
    {
        $uniqueName = $this->resolveUniqueCopyName($template);

        $copy = DocumentGenerationTemplate::query()->create([
            'company_id' => $template->company_id,
            'name' => $uniqueName,
            'description' => $template->description,
            'document_type_id' => $template->document_type_id,
            'content' => $template->content,
            'status' => DocumentGenerationTemplateStatus::Draft,
            'created_by' => $actor?->id,
            'updated_by' => $actor?->id,
        ]);

        activity()
            ->performedOn($copy)
            ->causedBy($actor)
            ->event('duplicated')
            ->withProperties([
                'source_id' => $template->id,
                'source_name' => $template->name,
                'name' => $copy->name,
                'company_id' => $copy->company_id,
            ])
            ->log("Duplicated template '{$template->name}' as '{$copy->name}'");

        return $copy;
    }

    private function resolveUniqueCopyName(DocumentGenerationTemplate $template): string
    {
        $baseName = $template->name;
        $candidate = "{$baseName} (Copy)";

        $counter = 1;
        while (DocumentGenerationTemplate::query()
            ->where('company_id', $template->company_id)
            ->where('name', $candidate)
            ->exists()
        ) {
            $counter++;
            $candidate = "{$baseName} (Copy {$counter})";
        }

        return $candidate;
    }
}
