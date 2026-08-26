<?php

namespace App\Support\EmployeeDocuments;

use App\Models\Company;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

final class UnmappedEmployeeDocumentTypeMatcher
{
    /**
     * @var array<string, array{id: int, title: string}|false>|null
     */
    private ?array $catalog = null;

    public static function normalize(?string $value): string
    {
        return mb_strtolower(trim((string) $value));
    }

    /**
     * @return array{
     *     status: 'match'|'ambiguous'|'unmatched',
     *     document_type_id: int|null,
     *     document_type_title: string|null
     * }
     */
    public function classify(?string $legacyValue): array
    {
        $key = self::normalize($legacyValue);

        if ($key === '') {
            return $this->unmatched();
        }

        $mapped = $this->catalog()[$key] ?? null;

        if ($mapped === false) {
            return [
                'status' => 'ambiguous',
                'document_type_id' => null,
                'document_type_title' => null,
            ];
        }

        if (is_array($mapped)) {
            return [
                'status' => 'match',
                'document_type_id' => $mapped['id'],
                'document_type_title' => $mapped['title'],
            ];
        }

        return $this->unmatched();
    }

    /**
     * @return array{
     *     total: int,
     *     match: int,
     *     ambiguous: int,
     *     unmatched: int,
     *     by_company: array<int, int>,
     *     legacy_values: list<array{company_id: int, legacy_value: string, rows: int, status: string, document_type_id: int|null, document_type_title: string|null}>
     * }
     */
    public function audit(?int $companyId): array
    {
        $totals = [
            'total' => 0,
            'match' => 0,
            'ambiguous' => 0,
            'unmatched' => 0,
            'by_company' => [],
            'legacy_values' => [],
        ];

        /** @var array<string, array{company_id: int, legacy_value: string, rows: int, status: string, document_type_id: int|null, document_type_title: string|null}> $grouped */
        $grouped = [];

        $this->unmappedQuery($companyId)
            ->select(['id', 'company_id', 'document_type'])
            ->orderBy('id')
            ->chunkById(500, function ($documents) use (&$totals, &$grouped): void {
                foreach ($documents as $document) {
                    $classification = $this->classify($document->document_type);
                    $legacyValue = trim((string) $document->document_type);
                    $displayValue = $legacyValue === '' ? '(empty)' : $legacyValue;
                    $groupKey = $document->company_id.'|'.$displayValue;

                    $totals['total']++;
                    $totals[$classification['status']]++;
                    $totals['by_company'][(int) $document->company_id] = ($totals['by_company'][(int) $document->company_id] ?? 0) + 1;

                    if (! isset($grouped[$groupKey])) {
                        $grouped[$groupKey] = [
                            'company_id' => (int) $document->company_id,
                            'legacy_value' => $displayValue,
                            'rows' => 0,
                            'status' => $classification['status'],
                            'document_type_id' => $classification['document_type_id'],
                            'document_type_title' => $classification['document_type_title'],
                        ];
                    }

                    $grouped[$groupKey]['rows']++;
                }
            });

        ksort($totals['by_company']);
        $totals['legacy_values'] = array_values($grouped);

        Log::info('Audited unmapped employee document types.', [
            'company_id' => $companyId,
            'total' => $totals['total'],
            'match' => $totals['match'],
            'ambiguous' => $totals['ambiguous'],
            'unmatched' => $totals['unmatched'],
        ]);

        return $totals;
    }

    /**
     * @return array{mapped: int, ambiguous: int, unmatched: int}
     */
    public function backfill(?int $companyId, bool $dryRun): array
    {
        $counts = [
            'mapped' => 0,
            'ambiguous' => 0,
            'unmatched' => 0,
        ];

        /** @var array<int, array<int, list<int>>> $updates */
        $updates = [];

        $this->unmappedQuery($companyId)
            ->select(['id', 'company_id', 'document_type'])
            ->orderBy('id')
            ->chunkById(500, function ($documents) use (&$counts, &$updates): void {
                foreach ($documents as $document) {
                    $classification = $this->classify($document->document_type);

                    if ($classification['status'] !== 'match' || $classification['document_type_id'] === null) {
                        $counts[$classification['status']]++;

                        continue;
                    }

                    $counts['mapped']++;
                    $updates[(int) $document->company_id][$classification['document_type_id']][] = (int) $document->id;
                }
            });

        if (! $dryRun) {
            foreach ($updates as $rowCompanyId => $idsByType) {
                foreach ($idsByType as $documentTypeId => $ids) {
                    EmployeeDocument::query()
                        ->where('company_id', $rowCompanyId)
                        ->whereNull('document_type_id')
                        ->whereIn('id', $ids)
                        ->update(['document_type_id' => $documentTypeId]);
                }
            }
        }

        Log::info('Backfilled unmapped employee document types.', [
            'company_id' => $companyId,
            'dry_run' => $dryRun,
            'mapped' => $counts['mapped'],
            'ambiguous' => $counts['ambiguous'],
            'unmatched' => $counts['unmatched'],
        ]);

        return $counts;
    }

    public function resolveCompanyOption(mixed $option): ?int
    {
        if ($option === null || $option === '') {
            return null;
        }

        if (is_int($option) && $option > 0) {
            $companyId = $option;
        } elseif (is_string($option) && ctype_digit($option) && (int) $option > 0) {
            $companyId = (int) $option;
        } else {
            throw new InvalidArgumentException('The --company option must be a positive integer company ID.');
        }

        if (! Company::query()->whereKey($companyId)->exists()) {
            throw new InvalidArgumentException("Company [{$companyId}] was not found.");
        }

        return $companyId;
    }

    /**
     * @return Builder<EmployeeDocument>
     */
    public function unmappedQuery(?int $companyId): Builder
    {
        return EmployeeDocument::query()
            ->whereNull('document_type_id')
            ->when($companyId !== null, fn (Builder $query) => $query->where('company_id', $companyId));
    }

    /**
     * @return array<string, array{id: int, title: string}|false>
     */
    public function catalog(): array
    {
        return $this->catalog ??= $this->buildCatalog();
    }

    /**
     * @return array{status: 'unmatched', document_type_id: null, document_type_title: null}
     */
    private function unmatched(): array
    {
        return [
            'status' => 'unmatched',
            'document_type_id' => null,
            'document_type_title' => null,
        ];
    }

    /**
     * @return array<string, array{id: int, title: string}|false>
     */
    private function buildCatalog(): array
    {
        $hasSlug = Schema::hasColumn('document_types', 'slug');
        $columns = $hasSlug ? ['id', 'title', 'slug'] : ['id', 'title'];

        /** @var array<string, list<array{id: int, title: string}>> $matchesByKey */
        $matchesByKey = [];

        DocumentType::query()
            ->get($columns)
            ->each(function (DocumentType $type) use (&$matchesByKey, $hasSlug): void {
                $entry = [
                    'id' => (int) $type->id,
                    'title' => (string) $type->title,
                ];
                $keys = [self::normalize($type->title)];

                if ($hasSlug) {
                    $keys[] = self::normalize((string) $type->getAttribute('slug'));
                }

                foreach (array_unique($keys) as $key) {
                    if ($key === '') {
                        continue;
                    }

                    $matchesByKey[$key][] = $entry;
                }
            });

        $catalog = [];

        foreach ($matchesByKey as $key => $matches) {
            $uniqueIds = array_values(array_unique(array_column($matches, 'id')));
            $catalog[$key] = count($uniqueIds) === 1 ? $matches[0] : false;
        }

        return $catalog;
    }
}
