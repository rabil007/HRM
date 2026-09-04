<?php

namespace App\Support\Documents;

final class PdfOverlaySourceInspection
{
    /**
     * @param  array<int, array{width: float, height: float, orientation: string}>  $pageSizes
     * @param  array{
     *     code: string,
     *     severity: string,
     *     placement_id: null,
     *     field_key: null,
     *     field_label: null,
     *     page: null,
     *     message: string
     * }|null  $issue
     */
    public function __construct(
        public readonly bool $ok,
        public readonly ?string $absolutePath,
        public readonly int $pageCount,
        public readonly array $pageSizes,
        public readonly ?array $issue,
    ) {}

    public static function failed(array $issue): self
    {
        return new self(false, null, 0, [], $issue);
    }
}
