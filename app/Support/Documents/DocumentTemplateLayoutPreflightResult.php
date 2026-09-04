<?php

namespace App\Support\Documents;

use App\Enums\DocumentTemplateLayoutPreflightStatus;
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
     *     test_value?: string|null,
     *     reference?: string|null
     * }>  $issues
     */
    public function __construct(
        public readonly DocumentTemplateLayoutPreflightStatus $status,
        public readonly bool $valid,
        public readonly array $effectiveFontSizes,
        public readonly array $issues,
        public readonly ?string $reference = null,
    ) {}

    /**
     * @return array{
     *     status: string,
     *     valid: bool,
     *     effective_font_sizes: array<string, float|null>,
     *     issues: list<array<string, mixed>>,
     *     reference: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'valid' => $this->valid,
            'effective_font_sizes' => $this->effectiveFontSizes,
            'issues' => $this->issues,
            'reference' => $this->reference,
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

    public function isUnavailable(): bool
    {
        return $this->status === DocumentTemplateLayoutPreflightStatus::Unavailable;
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
        return new self(
            status: DocumentTemplateLayoutPreflightStatus::Valid,
            valid: true,
            effectiveFontSizes: $effectiveFontSizes,
            issues: [],
        );
    }

    /**
     * @param  array<string, float|null>  $effectiveFontSizes
     * @param  list<array<string, mixed>>  $issues
     */
    public static function invalid(array $issues, array $effectiveFontSizes = []): self
    {
        return new self(
            status: DocumentTemplateLayoutPreflightStatus::Invalid,
            valid: false,
            effectiveFontSizes: $effectiveFontSizes,
            issues: $issues,
        );
    }

    /**
     * @param  array<string, float|null>  $effectiveFontSizes
     * @param  list<array<string, mixed>>  $issues
     */
    public static function unavailable(array $issues, array $effectiveFontSizes = [], ?string $reference = null): self
    {
        return new self(
            status: DocumentTemplateLayoutPreflightStatus::Unavailable,
            valid: false,
            effectiveFontSizes: $effectiveFontSizes,
            issues: $issues,
            reference: $reference,
        );
    }
}
