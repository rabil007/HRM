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
     *     document_type_id?: ?int,
     *     content?: string
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

            if (array_key_exists('content', $data) && $template->isContent()) {
                // Authoritative content read/write moves to the Version model
                $draft = (new BranchDocumentGenerationTemplateDraft)->handle($template, $actor?->id);
                $draft->content = (string) $data['content'];
                $draft->updated_by = $actor?->id;
                $draft->save();

                // Keep parent content in sync for legacy compatibility ONLY IF never published
                if ($template->published_version_id === null) {
                    $payload['content'] = (string) $data['content'];
                }
            }

            $template->update($payload);

            return $template->fresh(['publishedVersion', 'draftVersion']);
        });
    }
}
