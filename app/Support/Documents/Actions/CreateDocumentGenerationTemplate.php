<?php

namespace App\Support\Documents\Actions;

use App\Enums\DocumentGenerationTemplateStatus;
use App\Models\DocumentGenerationTemplate;
use App\Models\User;

final class CreateDocumentGenerationTemplate
{
    /**
     * @param  array{
     *     name: string,
     *     description?: ?string,
     *     document_type_id?: ?int,
     *     content: string,
     *     status?: string|DocumentGenerationTemplateStatus
     * }  $data
     */
    public function handle(int $companyId, array $data, ?User $actor = null): DocumentGenerationTemplate
    {
        $status = $data['status'] ?? DocumentGenerationTemplateStatus::Draft;

        if (is_string($status)) {
            $status = DocumentGenerationTemplateStatus::from($status);
        }

        return DocumentGenerationTemplate::query()->create([
            'company_id' => $companyId,
            'name' => trim($data['name']),
            'description' => isset($data['description']) && trim($data['description']) !== '' ? trim($data['description']) : null,
            'document_type_id' => ! empty($data['document_type_id']) ? (int) $data['document_type_id'] : null,
            'content' => $data['content'],
            'status' => $status,
            'created_by' => $actor?->id,
            'updated_by' => $actor?->id,
        ]);
    }
}
