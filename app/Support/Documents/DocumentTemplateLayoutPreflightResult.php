<?php

namespace App\Support\Documents;

use App\Support\Documents\Exceptions\DocumentTemplateLayoutException;

final class DocumentTemplateLayoutPreflightResult
{
    /**
     * @param  array<string, float|null>  $effectiveFontSizes
     * @param  list<array{
     *     code: string,
     *     severity: string,
     *     placement_id: string|null,
     *     field_key: string|null,
     *     field_label: string|null,
     *     page: int|null,
     *     message: string,
     *     test_value?: string|null
     * }>  $issues
     */
    public function __construct(
        public readonly bool $valid,
        public readonly array $effectiveFontSizes,
        public readonly array $issues,
    ) {}

    /**
     * @return array{
     *     valid: bool,
     *     effective_font_sizes: array<string, float|null>,
     *     issues: list<array<string, mixed>>
     * }
     */
    public function toArray(): array
    {
        return [
            'valid' => $this->valid,
            'effective_font_sizes' => $this->effectiveFontSizes,
            'issues' => $this->issues,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function blockingIssues(): array
    {
        return array_values(array_filter(
            $this->issues,
            fn (array $issue): bool => ($issue['severity'] ?? 'error') === 'error',
        ));
    }

    public function firstOverflowException(): ?DocumentTemplateLayoutException
    {
        foreach ($this->issues as $issue) {
            if (($issue['code'] ?? '') !== PdfOverlayLayoutPreflight::ISSUE_LAYOUT_OVERFLOW) {
                continue;
            }

            return new DocumentTemplateLayoutException(
                fieldKey: (string) ($issue['field_key'] ?? ''),
                pageNumber: (int) ($issue['page'] ?? 1),
                message: (string) ($issue['message'] ?? ''),
                placementId: isset($issue['placement_id']) ? (string) $issue['placement_id'] : null,
            );
        }

        return null;
    }

    public static function valid(array $effectiveFontSizes = []): self
    {
        return new self(true, $effectiveFontSizes, []);
    }
}
