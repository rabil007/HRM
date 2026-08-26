<?php

namespace App\Support\Employees;

use App\Models\Country;
use App\Models\Department;
use App\Models\Position;
use App\Models\Rank;
use Illuminate\Support\Str;

final class EmployeeSmartSearchResolver
{
    public const STATUSES = [
        'active',
        'inactive',
        'on_leave',
        'terminated',
    ];

    private const STATUS_LABELS = [
        'active' => 'Active',
        'inactive' => 'Inactive',
        'on_leave' => 'On leave',
        'terminated' => 'Terminated',
    ];

    /**
     * @param  array<string, mixed>  $intent
     */
    public function resolve(int $companyId, array $intent): EmployeeSmartSearchResult
    {
        $filters = [];
        $labels = [];
        $unresolved = [];
        $unsupported = $this->unsupportedTerms($intent);

        $this->resolveStatus($intent, $filters, $labels, $unresolved);
        $this->resolveCrewStatus($intent, $filters, $labels, $unresolved);
        $this->resolveEmiratesIdPresence($intent, $filters, $labels, $unresolved);

        $departmentId = $this->resolveNamedLookup(
            field: 'department',
            term: $this->stringValue($intent['department'] ?? null),
            candidates: $this->departments($companyId),
            filterKey: 'department_id',
            labelKey: 'department',
            filters: $filters,
            labels: $labels,
            unresolved: $unresolved,
        );

        $this->resolveNamedLookup(
            field: 'position',
            term: $this->stringValue($intent['position'] ?? null),
            candidates: $this->positions($companyId, $departmentId),
            filterKey: 'position_id',
            labelKey: 'position',
            filters: $filters,
            labels: $labels,
            unresolved: $unresolved,
        );

        $this->resolveNamedLookup(
            field: 'nationality',
            term: $this->stringValue($intent['nationality'] ?? null),
            candidates: $this->countries(),
            filterKey: 'nationality_id',
            labelKey: 'nationality',
            filters: $filters,
            labels: $labels,
            unresolved: $unresolved,
        );

        $this->resolveNamedLookup(
            field: 'rank',
            term: $this->stringValue($intent['rank'] ?? null),
            candidates: $this->ranks(),
            filterKey: 'rank_id',
            labelKey: 'rank',
            filters: $filters,
            labels: $labels,
            unresolved: $unresolved,
        );

        $directoryFilters = EmployeeDirectoryFilters::fromArray($filters);

        return new EmployeeSmartSearchResult(
            filters: $directoryFilters->toQueryArray(),
            labels: $labels,
            unresolved: $unresolved,
            unsupported: $unsupported,
        );
    }

    /**
     * @param  array<string, mixed>  $intent
     * @param  array<string, string>  $filters
     * @param  array<string, string>  $labels
     * @param  list<array{field: string, term: string, reason: string}>  $unresolved
     */
    private function resolveStatus(array $intent, array &$filters, array &$labels, array &$unresolved): void
    {
        $term = $this->stringValue($intent['status'] ?? null);

        if ($term === null) {
            return;
        }

        $normalized = $this->normalize($term);

        if (! in_array($normalized, self::STATUSES, true)) {
            $unresolved[] = [
                'field' => 'status',
                'term' => $term,
                'reason' => 'not_found',
            ];

            return;
        }

        $filters['status'] = $normalized;
        $labels['status'] = self::STATUS_LABELS[$normalized];
    }

    /**
     * @param  array<string, mixed>  $intent
     * @param  array<string, string>  $filters
     * @param  array<string, string>  $labels
     * @param  list<array{field: string, term: string, reason: string}>  $unresolved
     */
    private function resolveCrewStatus(array $intent, array &$filters, array &$labels, array &$unresolved): void
    {
        $term = $this->stringValue($intent['crew_status'] ?? null);

        if ($term === null) {
            return;
        }

        $options = EmployeeCrewStatusFilter::options();
        $normalized = $this->normalize($term);
        $matches = [];

        foreach ($options as $value => $label) {
            if ($this->normalize($value) === $normalized || $this->normalize($label) === $normalized) {
                $matches[$value] = $label;
            }
        }

        if (count($matches) === 1) {
            $value = array_key_first($matches);
            $filters['crew_status'] = $value;
            $labels['crew_status'] = $matches[$value];

            return;
        }

        $unresolved[] = [
            'field' => 'crew_status',
            'term' => $term,
            'reason' => $matches === [] ? 'not_found' : 'ambiguous',
        ];
    }

    /**
     * @param  array<string, mixed>  $intent
     * @param  array<string, string>  $filters
     * @param  array<string, string>  $labels
     * @param  list<array{field: string, term: string, reason: string}>  $unresolved
     */
    private function resolveEmiratesIdPresence(array $intent, array &$filters, array &$labels, array &$unresolved): void
    {
        $term = $this->stringValue($intent['emirates_id_presence'] ?? null);

        if ($term === null) {
            return;
        }

        if (! EmployeeDirectoryFilters::isValidEmiratesIdPresence($term)) {
            $unresolved[] = [
                'field' => 'emirates_id_presence',
                'term' => $term,
                'reason' => 'not_found',
            ];

            return;
        }

        $filters['emirates_id_presence'] = $term;
        $labels['emirates_id_presence'] = $term === EmployeeDirectoryFilters::EMIRATES_ID_PRESENCE_MISSING
            ? 'Missing'
            : 'Present';
    }

    /**
     * @param  list<array{id: int, label: string}>  $candidates
     * @param  array<string, string>  $filters
     * @param  array<string, string>  $labels
     * @param  list<array{field: string, term: string, reason: string}>  $unresolved
     */
    private function resolveNamedLookup(
        string $field,
        ?string $term,
        array $candidates,
        string $filterKey,
        string $labelKey,
        array &$filters,
        array &$labels,
        array &$unresolved,
    ): ?int {
        if ($term === null) {
            return null;
        }

        $normalized = $this->normalize($term);
        $matches = array_values(array_filter(
            $candidates,
            fn (array $candidate): bool => $this->normalize($candidate['label']) === $normalized,
        ));

        if (count($matches) === 1) {
            $filters[$filterKey] = (string) $matches[0]['id'];
            $labels[$labelKey] = $matches[0]['label'];

            return $matches[0]['id'];
        }

        $unresolved[] = [
            'field' => $field,
            'term' => $term,
            'reason' => $matches === [] ? 'not_found' : 'ambiguous',
        ];

        return null;
    }

    /**
     * @return list<array{id: int, label: string}>
     */
    private function departments(int $companyId): array
    {
        return Department::query()
            ->where('company_id', $companyId)
            ->get(['id', 'name'])
            ->map(fn (Department $department): array => [
                'id' => (int) $department->id,
                'label' => (string) $department->name,
            ])
            ->all();
    }

    /**
     * @return list<array{id: int, label: string}>
     */
    private function positions(int $companyId, ?int $departmentId): array
    {
        return Position::query()
            ->where('company_id', $companyId)
            ->when(
                $departmentId !== null,
                fn ($query) => $query->where('department_id', $departmentId),
            )
            ->get(['id', 'title', 'department_id'])
            ->map(fn (Position $position): array => [
                'id' => (int) $position->id,
                'label' => (string) $position->title,
            ])
            ->all();
    }

    /**
     * @return list<array{id: int, label: string}>
     */
    private function countries(): array
    {
        return Country::query()
            ->where('is_active', true)
            ->get(['id', 'name'])
            ->map(fn (Country $country): array => [
                'id' => (int) $country->id,
                'label' => (string) $country->name,
            ])
            ->all();
    }

    /**
     * @return list<array{id: int, label: string}>
     */
    private function ranks(): array
    {
        return Rank::query()
            ->where('is_active', true)
            ->get(['id', 'name'])
            ->map(fn (Rank $rank): array => [
                'id' => (int) $rank->id,
                'label' => (string) $rank->name,
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $intent
     * @return list<string>
     */
    private function unsupportedTerms(array $intent): array
    {
        $terms = $intent['unsupported_terms'] ?? [];

        if (! is_array($terms)) {
            return [];
        }

        $unsupported = [];

        foreach ($terms as $term) {
            $value = $this->stringValue($term);

            if ($value !== null) {
                $unsupported[] = $value;
            }
        }

        return array_values(array_unique($unsupported));
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function normalize(string $value): string
    {
        return Str::lower(Str::squish($value));
    }
}
