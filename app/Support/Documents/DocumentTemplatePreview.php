<?php

namespace App\Support\Documents;

use App\Models\Company;
use App\Models\DocumentGenerationTemplate;
use App\Models\Employee;

final class DocumentTemplatePreview
{
    /**
     * @return array{
     *     name: string,
     *     content_html: string,
     *     unresolved_placeholders: list<string>,
     *     preview_mode: 'sample'|'employee',
     *     employee_name: ?string
     * }
     */
    public function renderTemplate(
        DocumentGenerationTemplate $template,
        ?Employee $employee = null,
        ?int $companyId = null,
    ): array {
        return $this->render(
            name: $template->name,
            content: $template->content,
            employee: $employee,
            companyId: $companyId ?? $template->company_id,
        );
    }

    /**
     * @return array{
     *     name: string,
     *     content_html: string,
     *     unresolved_placeholders: list<string>,
     *     preview_mode: 'sample'|'employee',
     *     employee_name: ?string
     * }
     */
    public function render(
        string $name,
        string $content,
        ?Employee $employee = null,
        ?int $companyId = null,
    ): array {
        $companyName = null;

        if ($companyId !== null && $companyId > 0) {
            $companyName = Company::query()->whereKey($companyId)->value('name');
        }

        if ($employee !== null) {
            $values = DocumentTemplateMergeFields::valuesForEmployee($employee);
            $previewMode = 'employee';
            $employeeName = $employee->name;
        } else {
            $values = DocumentTemplateMergeFields::sampleValues($companyName);
            $previewMode = 'sample';
            $employeeName = null;
        }

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
            'preview_mode' => $previewMode,
            'employee_name' => $employeeName,
        ];
    }
}
