<?php

namespace App\Support\Documents\Actions;

use App\Models\DocumentGenerationTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class UpdateDocumentGenerationTemplate
{
    /**
     * @param  array{
     *     name?: string,
     *     description?: ?string,
     *     document_type_id?: ?int
     * }  $data
     */
    public function handle(DocumentGenerationTemplate $template, array $data, ?User $actor = null): DocumentGenerationTemplate
    {
        return DB::transaction(function () use ($template, $data, $actor): DocumentGenerationTemplate {
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

            $template->update($payload);

            return $template->fresh(['publishedVersion', 'draftVersion']);
        });
    }
}
