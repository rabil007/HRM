<?php

namespace App\Support\Documents;

use App\Support\EmployeeDocuments\DocumentExpiry;
use App\Support\EmployeeDocuments\DocumentRequirementComplianceStatus;
use Illuminate\Http\Request;

final class DocumentsLibraryQueryState
{
    public function __construct(
        public readonly string $search = '',
        public readonly string $expiry = 'all',
        public readonly string $requirementStatus = '',
        public readonly string $departmentId = '',
        public readonly string $documentTypeId = '',
        public readonly int $page = 1,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $expiry = (string) $request->query('expiry', 'all');

        if (! DocumentExpiry::isValidFilter($expiry)) {
            $expiry = 'all';
        }

        $requirementStatus = trim((string) $request->query('requirement_status', ''));

        if ($requirementStatus !== '' && ! DocumentRequirementComplianceStatus::isValidFilter($requirementStatus)) {
            $requirementStatus = '';
        }

        $departmentId = trim((string) $request->query('department_id', ''));

        if ($departmentId !== '' && ! ctype_digit($departmentId)) {
            $departmentId = '';
        }

        $documentTypeId = trim((string) $request->query('document_type_id', ''));

        if ($documentTypeId !== '' && ! ctype_digit($documentTypeId)) {
            $documentTypeId = '';
        }

        $page = (int) $request->query('page', 1);

        if ($page < 1) {
            $page = 1;
        }

        return new self(
            search: trim((string) $request->query('search', '')),
            expiry: $expiry,
            requirementStatus: $requirementStatus,
            departmentId: $departmentId,
            documentTypeId: $documentTypeId,
            page: $page,
        );
    }

    public function hasBrowseState(): bool
    {
        return $this->toQuery() !== [];
    }

    /**
     * Supported Library query keys only. Unknown parameters, including company_id, are dropped.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        $query = [];

        if ($this->search !== '') {
            $query['search'] = $this->search;
        }

        if ($this->expiry !== 'all') {
            $query['expiry'] = $this->expiry;
        }

        if ($this->requirementStatus !== '') {
            $query['requirement_status'] = $this->requirementStatus;
        }

        if ($this->departmentId !== '') {
            $query['department_id'] = $this->departmentId;
        }

        if ($this->documentTypeId !== '') {
            $query['document_type_id'] = $this->documentTypeId;
        }

        if ($this->page > 1) {
            $query['page'] = (string) $this->page;
        }

        return $query;
    }
}
