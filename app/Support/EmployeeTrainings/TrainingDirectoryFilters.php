<?php

namespace App\Support\EmployeeTrainings;

use Illuminate\Http\Request;

final class TrainingDirectoryFilters
{
    public function __construct(
        public readonly string $search = '',
        public readonly string $expiry = 'all',
        public readonly string $issueDate = '',
        public readonly string $courseId = '',
        public readonly string $institute = '',
        public readonly string $countryId = '',
        public readonly string $branchId = '',
        public readonly string $departmentId = '',
    ) {}

    public static function fromRequest(Request $request): self
    {
        $expiry = (string) $request->query('expiry', 'all');

        if (! TrainingExpiry::isValidFilter($expiry)) {
            $expiry = 'all';
        }

        $issueDate = trim((string) $request->query('issue_date', ''));

        if ($issueDate !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $issueDate)) {
            $issueDate = '';
        }

        $courseId = trim((string) $request->query('course_id', ''));
        if ($courseId !== '' && ! ctype_digit($courseId)) {
            $courseId = '';
        }

        $countryId = trim((string) $request->query('country_id', ''));
        if ($countryId !== '' && ! ctype_digit($countryId)) {
            $countryId = '';
        }

        return new self(
            search: trim((string) $request->query('search', '')),
            expiry: $expiry,
            issueDate: $issueDate,
            courseId: $courseId,
            institute: trim((string) $request->query('institute', '')),
            countryId: $countryId,
            branchId: (string) $request->query('branch_id', ''),
            departmentId: (string) $request->query('department_id', ''),
        );
    }

    /**
     * @return array<string, string>
     */
    public function toQueryArray(): array
    {
        $query = [];

        if ($this->search !== '') {
            $query['search'] = $this->search;
        }

        if ($this->expiry !== 'all') {
            $query['expiry'] = $this->expiry;
        }

        if ($this->issueDate !== '') {
            $query['issue_date'] = $this->issueDate;
        }

        if ($this->courseId !== '') {
            $query['course_id'] = $this->courseId;
        }

        if ($this->institute !== '') {
            $query['institute'] = $this->institute;
        }

        if ($this->countryId !== '') {
            $query['country_id'] = $this->countryId;
        }

        if ($this->branchId !== '') {
            $query['branch_id'] = $this->branchId;
        }

        if ($this->departmentId !== '') {
            $query['department_id'] = $this->departmentId;
        }

        return $query;
    }
}
