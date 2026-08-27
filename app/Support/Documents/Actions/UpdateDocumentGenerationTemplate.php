<?php

namespace App\Support\Documents\Actions;

use App\Enums\DocumentGenerationTemplateStatus;
use App\Models\DocumentGenerationTemplate;
use App\Models\User;

final class UpdateDocumentGenerationTemplate
{
    /**
     * @param  array{
     *     name?: string,
     *     description?: ?string,
     *     document_type_id?: ?int,
     *     content?: string,
     *     status?: string|DocumentGenerationTemplateStatus
     * }  $data
     */
    public function handle(DocumentGenerationTemplate $template, array $data, ?User $actor = null): DocumentGenerationTemplate
    {
        $payload = [
            'updated_by' => $actor?->id,
        ];

        if (array_key_exists('name', $data)) {
            $payload['name'] = trim($data['name']);
        }

        if (array_key_exists('description', $data)) {
            $payload['description'] = isset($data['description']) && trim($data['description']) !== '' ? trim($data['description']) : null;
        }

        if (array_key_exists('document_type_id', $data)) {
            $payload['document_type_id'] = ! empty($data['document_type_id']) ? (int) $data['document_type_id'] : null;
        }

        if (array_key_exists('content', $data)) {
            $payload['content'] = $data['content'];
        }

        if (array_key_exists('status', $data)) {
            $status = $data['status'];
            if (is_string($status)) {
                $status = DocumentGenerationTemplateStatus::from($status);
            }
            $payload['status'] = $status;
        }

        $template->update($payload);

        return $template;
    }
}
