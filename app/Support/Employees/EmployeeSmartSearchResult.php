<?php

namespace App\Support\Employees;

final readonly class EmployeeSmartSearchResult
{
    /**
     * @param  array<string, string>  $filters
     * @param  list<array{key: string, label: string, value: string}>  $applied
     * @param  list<array{field: string, term: string, reason: string}>  $unresolved
     * @param  list<array{field: string, term: string, reason: string}>  $ambiguous
     * @param  list<string>  $unsupported
     */
    public function __construct(
        public array $filters,
        public array $applied,
        public array $unresolved,
        public array $ambiguous,
        public array $unsupported,
    ) {}

    /**
     * @return array{
     *     filters: array<string, string>,
     *     applied: list<array{key: string, label: string, value: string}>,
     *     unresolved: list<array{field: string, term: string, reason: string}>,
     *     ambiguous: list<array{field: string, term: string, reason: string}>,
     *     unsupported: list<string>
     * }
     */
    public function toArray(): array
    {
        return [
            'filters' => $this->filters,
            'applied' => $this->applied,
            'unresolved' => $this->unresolved,
            'ambiguous' => $this->ambiguous,
            'unsupported' => $this->unsupported,
        ];
    }
}
