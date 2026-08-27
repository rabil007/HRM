<?php

namespace App\Support\Documents\Actions;

use App\Enums\DocumentGenerationTemplateStatus;
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
     *     content?: string,
     *     status?: string|DocumentGenerationTemplateStatus
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

                // Keep parent content in sync for legacy compatibility
                $payload['content'] = (string) $data['content'];
            }

            if (array_key_exists('status', $data)) {
                $status = $data['status'];
                if (is_string($status)) {
                    $status = DocumentGenerationTemplateStatus::from($status);
                }

                if ($status === DocumentGenerationTemplateStatus::Active) {
                    // If publishing an active status, ensure draft gets published
                    $draft = $template->draftVersion;
                    if ($draft !== null) {
                        (new PublishDocumentGenerationTemplateVersion)->handle($draft, $actor?->id);
                    } else {
                        $payload['status'] = DocumentGenerationTemplateStatus::Active;
                    }
                } else {
                    $payload['status'] = $status;
                }
            }

            $template->update($payload);

            return $template->fresh(['publishedVersion', 'draftVersion']);
        });
    }
}
