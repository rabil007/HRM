<?php

namespace App\Support\Employees;

final readonly class EmployeeSmartSearchResult
{
    /**
     * @param  array<string, string>  $filters
     * @param  array<string, string>  $labels
     * @param  list<array{field: string, term: string, reason: string}>  $unresolved
     * @param  list<string>  $unsupported
     */
    public function __construct(
        public array $filters,
        public array $labels,
        public array $unresolved,
        public array $unsupported,
    ) {}

    /**
     * @return array{
     *     filters: array<string, string>,
     *     labels: array<string, string>,
     *     unresolved: list<array{field: string, term: string, reason: string}>,
     *     unsupported: list<string>
     * }
     */
    public function toArray(): array
    {
        return [
            'filters' => $this->filters,
            'labels' => $this->labels,
            'unresolved' => $this->unresolved,
            'unsupported' => $this->unsupported,
        ];
    }
}
