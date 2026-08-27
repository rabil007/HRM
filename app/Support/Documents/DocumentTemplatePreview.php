<?php

namespace App\Support\Documents;

use App\Models\Company;
use App\Models\DocumentGenerationTemplate;

final class DocumentTemplatePreview
{
    /**
     * @return array{
     *     name: string,
     *     content_html: string,
     *     unresolved_placeholders: list<string>,
     *     preview_mode: 'sample'
     * }
     */
    public function renderTemplate(
        DocumentGenerationTemplate $template,
        ?int $companyId = null,
    ): array {
        return $this->render(
            name: $template->name,
            content: $template->content,
            companyId: $companyId ?? $template->company_id,
        );
    }

    /**
     * @return array{
     *     name: string,
     *     content_html: string,
     *     unresolved_placeholders: list<string>,
     *     preview_mode: 'sample'
     * }
     */
    public function render(
        string $name,
        string $content,
        ?int $companyId = null,
    ): array {
        $companyName = null;

        if ($companyId !== null && $companyId > 0) {
            $companyName = Company::query()->whereKey($companyId)->value('name');
        }

        // For Phase 3, preview custom templates with sample data only.
        $values = DocumentTemplateMergeFields::sampleValues($companyName);

        // Apply known placeholders
        $replaced = DocumentTemplateMergeFields::apply($content, $values);

        // Find any remaining unresolved placeholders
        preg_match_all('/\{\{[^}]+\}\}/', $replaced, $unresolvedMatches);
        $unresolved = array_values(array_unique($unresolvedMatches[0] ?? []));

        // Format safely for HTML display: HTML-escape first, then convert newlines to <br>
        $escaped = e($replaced);
        $html = nl2br($escaped);

        return [
            'name' => $name,
            'content_html' => $html,
            'unresolved_placeholders' => $unresolved,
            'preview_mode' => 'sample',
        ];
    }
}
