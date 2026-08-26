<?php

namespace App\Support\Employees;

use App\Models\ApprovalLocation;
use App\Models\Branch;
use App\Models\CompanyVisaType;
use App\Models\Country;
use App\Models\Department;
use App\Models\Gender;
use App\Models\Position;
use App\Models\Rank;
use App\Models\SssaOption;
use App\Models\VisaType;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

final class EmployeeSmartSearchResolver
{
    public const STATUSES = [
        'active',
        'inactive',
        'on_leave',
        'terminated',
        'all',
    ];

    public const STATUS_ALL = 'all';

    private const STATUS_LABELS = [
        'active' => 'Active',
        'inactive' => 'Inactive',
        'on_leave' => 'On leave',
        'terminated' => 'Terminated',
        'all' => 'All statuses',
    ];

    /** @var array<string, list<array{id: int, label: string, codes: list<string>}>> */
    private array $namedLookups = [];

    /**
     * @param  array<string, mixed>  $intent
     */
    public function resolve(int $companyId, array $intent): EmployeeSmartSearchResult
    {
        $criteria = $this->criteria($intent);
        $unsupported = $this->stringList($intent['unsupported_terms'] ?? []);
        $ambiguous = [];
        $unresolved = [];

        foreach ($this->stringList($intent['ambiguous_terms'] ?? []) as $term) {
            $ambiguous[] = [
                'field' => 'query',
                'term' => $term,
                'reason' => 'needs_clarification',
            ];
        }

        $conflicts = $this->conflicts($criteria);

        if ($conflicts !== []) {
            return new EmployeeSmartSearchResult(
                filters: [],
                applied: [],
                unresolved: [],
                ambiguous: $conflicts,
                unsupported: $unsupported,
            );
        }

        $criteria = $this->dropRedundantPresent($criteria);

        $filters = [];
        $applied = [];
        $missing = [];
        $present = [];
        $resolvedDepartmentId = null;

        foreach ($criteria as $criterion) {
            $concept = $criterion['concept'];
            $operator = $criterion['operator'];
            $value = $criterion['value'];
            $definition = EmployeeSmartSearchConceptRegistry::definition($concept);

            if ($definition === null) {
                continue;
            }

            if ($operator === EmployeeSmartSearchConceptRegistry::OPERATOR_MISSING) {
                $missing[] = $concept;
                $applied[] = $this->appliedItem($concept, $operator, 'Missing');

                continue;
            }

            if ($operator === EmployeeSmartSearchConceptRegistry::OPERATOR_PRESENT) {
                $present[] = $concept;
                $applied[] = $this->appliedItem($concept, $operator, 'Present');

                continue;
            }

            if ($concept === 'status') {
                $this->resolveStatus($value, $filters, $applied, $unresolved);

                continue;
            }

            if ($concept === 'crew_status') {
                $this->resolveCrewStatus($value, $filters, $applied, $unresolved);

                continue;
            }

            if ($definition['lookup'] !== EmployeeSmartSearchConceptRegistry::LOOKUP_NAMED) {
                $unresolved[] = [
                    'field' => $concept,
                    'term' => $value ?? '',
                    'reason' => 'not_found',
                ];

                continue;
            }

            $resolvedId = $this->resolveNamedLookup(
                concept: $concept,
                term: $value,
                candidates: $this->namedCandidates($companyId, $concept, $resolvedDepartmentId),
                filterKey: (string) $definition['filter_key'],
                filters: $filters,
                applied: $applied,
                unresolved: $unresolved,
                ambiguous: $ambiguous,
            );

            if ($concept === 'department') {
                $resolvedDepartmentId = $resolvedId;
            }
        }

        if ($missing !== []) {
            $filters[EmployeeDirectoryCompleteness::MISSING_QUERY_KEY] = EmployeeDirectoryCompleteness::toCsv($missing);
        }

        if ($present !== []) {
            $filters[EmployeeDirectoryCompleteness::PRESENT_QUERY_KEY] = EmployeeDirectoryCompleteness::toCsv($present);
        }

        $directoryFilters = EmployeeDirectoryFilters::fromArray($filters);

        return new EmployeeSmartSearchResult(
            filters: $directoryFilters->toQueryArray(),
            applied: $applied,
            unresolved: $unresolved,
            ambiguous: $ambiguous,
            unsupported: $unsupported,
        );
    }

    /**
     * @param  array<string, mixed>  $intent
     * @return list<array{concept: string, operator: string, value: string|null}>
     */
    private function criteria(array $intent): array
    {
        $criteria = $intent['criteria'] ?? [];

        if (! is_array($criteria)) {
            return [];
        }

        $normalized = [];

        foreach ($criteria as $item) {
            if (! is_array($item)) {
                continue;
            }

            $concept = $this->stringValue($item['concept'] ?? null);
            $operator = $this->stringValue($item['operator'] ?? null);

            if ($concept === null || $operator === null) {
                continue;
            }

            $normalized[] = [
                'concept' => strtolower($concept),
                'operator' => strtolower($operator),
                'value' => $this->stringValue($item['value'] ?? null),
            ];
        }

        return $normalized;
    }

    /**
     * @param  list<array{concept: string, operator: string, value: string|null}>  $criteria
     * @return list<array{field: string, term: string, reason: string}>
     */
    private function conflicts(array $criteria): array
    {
        $grouped = [];

        foreach ($criteria as $criterion) {
            $grouped[$criterion['concept']][] = $criterion;
        }

        $conflicts = [];

        foreach ($grouped as $concept => $items) {
            $operators = array_values(array_unique(array_column($items, 'operator')));
            $equalsValues = array_values(array_unique(array_filter(
                array_map(fn (array $item): ?string => $item['operator'] === EmployeeSmartSearchConceptRegistry::OPERATOR_EQUALS
                    ? $item['value']
                    : null, $items),
            )));

            $hasMissing = in_array(EmployeeSmartSearchConceptRegistry::OPERATOR_MISSING, $operators, true);
            $hasPresent = in_array(EmployeeSmartSearchConceptRegistry::OPERATOR_PRESENT, $operators, true);
            $hasEquals = in_array(EmployeeSmartSearchConceptRegistry::OPERATOR_EQUALS, $operators, true);

            if ($hasMissing && $hasPresent) {
                $conflicts[] = [
                    'field' => $concept,
                    'term' => EmployeeSmartSearchConceptRegistry::label($concept),
                    'reason' => 'conflict',
                ];

                continue;
            }

            if ($hasEquals && $hasMissing) {
                $conflicts[] = [
                    'field' => $concept,
                    'term' => EmployeeSmartSearchConceptRegistry::label($concept),
                    'reason' => 'conflict',
                ];

                continue;
            }

            if (count($equalsValues) > 1 && EmployeeSmartSearchConceptRegistry::isSingleValued($concept)) {
                $conflicts[] = [
                    'field' => $concept,
                    'term' => implode(' / ', $equalsValues),
                    'reason' => 'multiple_values',
                ];
            }
        }

        return $conflicts;
    }

    /**
     * @param  list<array{concept: string, operator: string, value: string|null}>  $criteria
     * @return list<array{concept: string, operator: string, value: string|null}>
     */
    private function dropRedundantPresent(array $criteria): array
    {
        $equalsConcepts = [];

        foreach ($criteria as $criterion) {
            if ($criterion['operator'] === EmployeeSmartSearchConceptRegistry::OPERATOR_EQUALS) {
                $equalsConcepts[$criterion['concept']] = true;
            }
        }

        return array_values(array_filter(
            $criteria,
            fn (array $criterion): bool => ! (
                $criterion['operator'] === EmployeeSmartSearchConceptRegistry::OPERATOR_PRESENT
                && isset($equalsConcepts[$criterion['concept']])
            ),
        ));
    }

    /**
     * @param  array<string, string>  $filters
     * @param  list<array{key: string, label: string, value: string}>  $applied
     * @param  list<array{field: string, term: string, reason: string}>  $unresolved
     */
    private function resolveStatus(?string $term, array &$filters, array &$applied, array &$unresolved): void
    {
        if ($term === null) {
            $unresolved[] = [
                'field' => 'status',
                'term' => '',
                'reason' => 'not_found',
            ];

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
        $applied[] = $this->appliedItem('status', EmployeeSmartSearchConceptRegistry::OPERATOR_EQUALS, self::STATUS_LABELS[$normalized]);
    }

    /**
     * @param  array<string, string>  $filters
     * @param  list<array{key: string, label: string, value: string}>  $applied
     * @param  list<array{field: string, term: string, reason: string}>  $unresolved
     */
    private function resolveCrewStatus(?string $term, array &$filters, array &$applied, array &$unresolved): void
    {
        if ($term === null) {
            $unresolved[] = [
                'field' => 'crew_status',
                'term' => '',
                'reason' => 'not_found',
            ];

            return;
        }

        $options = EmployeeCrewStatusFilter::options();
        $normalized = $this->normalize($term);
        $aliases = EmployeeSmartSearchConceptRegistry::definition('crew_status')['aliases'] ?? [];
        $aliasTargets = $aliases[$normalized] ?? [];
        $matches = [];

        foreach ($options as $value => $label) {
            if (
                $this->normalize($value) === $normalized
                || $this->normalize($label) === $normalized
                || in_array($value, $aliasTargets, true)
            ) {
                $matches[$value] = $label;
            }
        }

        if (count($matches) === 1) {
            $value = (string) array_key_first($matches);
            $filters['crew_status'] = $value;
            $applied[] = $this->appliedItem(
                'crew_status',
                EmployeeSmartSearchConceptRegistry::OPERATOR_EQUALS,
                $matches[$value],
            );

            return;
        }

        $unresolved[] = [
            'field' => 'crew_status',
            'term' => $term,
            'reason' => $matches === [] ? 'not_found' : 'ambiguous',
        ];
    }

    /**
     * @param  list<array{id: int, label: string, codes: list<string>}>  $candidates
     * @param  array<string, string>  $filters
     * @param  list<array{key: string, label: string, value: string}>  $applied
     * @param  list<array{field: string, term: string, reason: string}>  $unresolved
     * @param  list<array{field: string, term: string, reason: string}>  $ambiguous
     */
    private function resolveNamedLookup(
        string $concept,
        ?string $term,
        array $candidates,
        string $filterKey,
        array &$filters,
        array &$applied,
        array &$unresolved,
        array &$ambiguous,
    ): ?int {
        if ($term === null) {
            $unresolved[] = [
                'field' => $concept,
                'term' => '',
                'reason' => 'not_found',
            ];

            return null;
        }

        $normalized = $this->normalize($term);
        $aliases = EmployeeSmartSearchConceptRegistry::definition($concept)['aliases'] ?? [];
        $aliasLabels = array_map(
            fn (string $label): string => $this->normalize($label),
            $aliases[$normalized] ?? [],
        );

        $matches = array_values(array_filter(
            $candidates,
            function (array $candidate) use ($normalized, $aliasLabels): bool {
                if ($this->normalize($candidate['label']) === $normalized) {
                    return true;
                }

                foreach ($candidate['codes'] as $code) {
                    if ($this->normalize($code) === $normalized) {
                        return true;
                    }
                }

                return in_array($this->normalize($candidate['label']), $aliasLabels, true);
            },
        ));

        if (count($matches) === 1) {
            $filters[$filterKey] = (string) $matches[0]['id'];
            $applied[] = $this->appliedItem(
                $concept,
                EmployeeSmartSearchConceptRegistry::OPERATOR_EQUALS,
                $matches[0]['label'],
            );

            return $matches[0]['id'];
        }

        $entry = [
            'field' => $concept,
            'term' => $term,
            'reason' => $matches === [] ? 'not_found' : 'ambiguous',
        ];

        if ($matches === []) {
            $unresolved[] = $entry;
        } else {
            $ambiguous[] = $entry;
        }

        return null;
    }

    /**
     * @return list<array{id: int, label: string, codes: list<string>}>
     */
    private function namedCandidates(int $companyId, string $concept, ?int $departmentId): array
    {
        $cacheKey = $concept.':'.$companyId.':'.($departmentId ?? 'any');

        if (isset($this->namedLookups[$cacheKey])) {
            return $this->namedLookups[$cacheKey];
        }

        $this->namedLookups[$cacheKey] = match ($concept) {
            'department' => $this->departments($companyId),
            'position' => $this->positions($companyId, $departmentId),
            'nationality' => $this->countries(),
            'rank' => $this->ranks(),
            'branch' => $this->branches($companyId),
            'gender' => $this->genders(),
            'visa_type' => $this->visaTypes(),
            'sponsor' => $this->companyVisaTypes(),
            'role' => $this->roles($companyId),
            'approval_location' => $this->approvalLocations(),
            'sssa_option' => $this->sssaOptions(),
            default => [],
        };

        return $this->namedLookups[$cacheKey];
    }

    /**
     * @return list<array{id: int, label: string, codes: list<string>}>
     */
    private function departments(int $companyId): array
    {
        return Department::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->get(['id', 'name', 'code'])
            ->map(fn (Department $department): array => [
                'id' => (int) $department->id,
                'label' => (string) $department->name,
                'codes' => array_values(array_filter([(string) $department->code])),
            ])
            ->all();
    }

    /**
     * @return list<array{id: int, label: string, codes: list<string>}>
     */
    private function positions(int $companyId, ?int $departmentId): array
    {
        return Position::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->when(
                $departmentId !== null,
                fn ($query) => $query->where('department_id', $departmentId),
            )
            ->get(['id', 'title'])
            ->map(fn (Position $position): array => [
                'id' => (int) $position->id,
                'label' => (string) $position->title,
                'codes' => [],
            ])
            ->all();
    }

    /**
     * @return list<array{id: int, label: string, codes: list<string>}>
     */
    private function countries(): array
    {
        return Country::query()
            ->where('is_active', true)
            ->get(['id', 'name', 'code'])
            ->map(fn (Country $country): array => [
                'id' => (int) $country->id,
                'label' => (string) $country->name,
                'codes' => array_values(array_filter([(string) $country->code])),
            ])
            ->all();
    }

    /**
     * @return list<array{id: int, label: string, codes: list<string>}>
     */
    private function ranks(): array
    {
        return Rank::query()
            ->where('is_active', true)
            ->get(['id', 'name'])
            ->map(fn (Rank $rank): array => [
                'id' => (int) $rank->id,
                'label' => (string) $rank->name,
                'codes' => [],
            ])
            ->all();
    }

    /**
     * @return list<array{id: int, label: string, codes: list<string>}>
     */
    private function branches(int $companyId): array
    {
        return Branch::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->get(['id', 'name', 'code'])
            ->map(fn (Branch $branch): array => [
                'id' => (int) $branch->id,
                'label' => (string) $branch->name,
                'codes' => array_values(array_filter([(string) $branch->code])),
            ])
            ->all();
    }

    /**
     * @return list<array{id: int, label: string, codes: list<string>}>
     */
    private function genders(): array
    {
        return Gender::query()
            ->where('is_active', true)
            ->get(['id', 'name'])
            ->map(fn (Gender $gender): array => [
                'id' => (int) $gender->id,
                'label' => (string) $gender->name,
                'codes' => [],
            ])
            ->all();
    }

    /**
     * @return list<array{id: int, label: string, codes: list<string>}>
     */
    private function visaTypes(): array
    {
        return VisaType::query()
            ->where('is_active', true)
            ->get(['id', 'name'])
            ->map(fn (VisaType $visaType): array => [
                'id' => (int) $visaType->id,
                'label' => (string) $visaType->name,
                'codes' => [],
            ])
            ->all();
    }

    /**
     * @return list<array{id: int, label: string, codes: list<string>}>
     */
    private function companyVisaTypes(): array
    {
        return CompanyVisaType::query()
            ->where('is_active', true)
            ->get(['id', 'name'])
            ->map(fn (CompanyVisaType $visaType): array => [
                'id' => (int) $visaType->id,
                'label' => (string) $visaType->name,
                'codes' => [],
            ])
            ->all();
    }

    /**
     * @return list<array{id: int, label: string, codes: list<string>}>
     */
    private function roles(int $companyId): array
    {
        return Role::query()
            ->where('company_id', $companyId)
            ->get(['id', 'name'])
            ->map(fn (Role $role): array => [
                'id' => (int) $role->id,
                'label' => (string) $role->name,
                'codes' => [],
            ])
            ->all();
    }

    /**
     * @return list<array{id: int, label: string, codes: list<string>}>
     */
    private function approvalLocations(): array
    {
        return ApprovalLocation::query()
            ->where('is_active', true)
            ->get(['id', 'name'])
            ->map(fn (ApprovalLocation $location): array => [
                'id' => (int) $location->id,
                'label' => (string) $location->name,
                'codes' => [],
            ])
            ->all();
    }

    /**
     * @return list<array{id: int, label: string, codes: list<string>}>
     */
    private function sssaOptions(): array
    {
        return SssaOption::query()
            ->where('is_active', true)
            ->get(['id', 'name'])
            ->map(fn (SssaOption $option): array => [
                'id' => (int) $option->id,
                'label' => (string) $option->name,
                'codes' => [],
            ])
            ->all();
    }

    /**
     * @return array{key: string, label: string, value: string}
     */
    private function appliedItem(string $concept, string $operator, string $value): array
    {
        return [
            'key' => $concept.':'.$operator,
            'label' => EmployeeSmartSearchConceptRegistry::label($concept),
            'value' => $value,
        ];
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $terms = [];

        foreach ($value as $term) {
            $normalized = $this->stringValue($term);

            if ($normalized !== null) {
                $terms[] = $normalized;
            }
        }

        return array_values(array_unique($terms));
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
