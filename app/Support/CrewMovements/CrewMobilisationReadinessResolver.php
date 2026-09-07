<?php

namespace App\Support\CrewMovements;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewMobilisationReadinessStatus;
use App\Models\CrewAssignment;
use App\Models\User;
use App\Support\EmployeeDocuments\DocumentComplianceQuery;
use App\Support\EmployeeDocuments\DocumentExpiry;
use App\Support\EmployeeDocuments\DocumentRequirementComplianceStatus;
use Illuminate\Support\Collection;

final class CrewMobilisationReadinessResolver
{
    public function __construct(
        private readonly DocumentComplianceQuery $complianceQuery = new DocumentComplianceQuery,
    ) {}

    public function appliesTo(CrewAssignment $assignment): bool
    {
        if (! in_array($assignment->status, [
            CrewAssignmentStatus::Draft,
            CrewAssignmentStatus::Active,
        ], true)) {
            return false;
        }

        $phase = $assignment->currentPhase?->phase_code;

        return $phase !== null && $phase->isPreJoin();
    }

    /**
     * @param  list<array<string, mixed>>|null  $preloadedComplianceItems
     */
    public function forAssignment(
        CrewAssignment $assignment,
        ?User $user = null,
        ?array $preloadedComplianceItems = null,
        bool $includeHrefs = true,
    ): CrewMobilisationReadinessResult {
        if (! $this->appliesTo($assignment)) {
            return CrewMobilisationReadinessResult::notApplicable();
        }

        $employee = $assignment->employee;
        $companyId = (int) $assignment->company_id;

        if ($employee === null || (int) $employee->company_id !== $companyId) {
            return $this->buildResult($assignment, [], $user, $includeHrefs);
        }

        $items = $preloadedComplianceItems ?? $this->complianceQuery->itemsForEmployee($employee);

        return $this->buildResult($assignment, $this->uniqueByDocumentType($items), $user, $includeHrefs);
    }

    /**
     * @param  Collection<int, CrewAssignment>  $assignments
     */
    public function attachForAssignments(Collection $assignments, int $companyId, ?User $user = null): void
    {
        $applicable = $assignments->filter(fn (CrewAssignment $assignment): bool => $this->appliesTo($assignment));
        $employeeIds = $applicable
            ->pluck('employee_id')
            ->filter(fn ($id): bool => $id !== null && (int) $id > 0)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $itemsByEmployee = $this->complianceQuery->itemsForEmployeeIds($companyId, $employeeIds);

        foreach ($assignments as $assignment) {
            if (! $this->appliesTo($assignment)) {
                $assignment->mobilisation_readiness = CrewMobilisationReadinessResult::notApplicable();

                continue;
            }

            $employeeId = (int) $assignment->employee_id;
            $employee = $assignment->employee;
            $items = $employee !== null && (int) $employee->company_id === $companyId
                ? ($itemsByEmployee->get($employeeId) ?? [])
                : [];

            $assignment->mobilisation_readiness = $this->buildResult(
                $assignment,
                $this->uniqueByDocumentType($items),
                $user,
                includeHrefs: false,
            );
        }
    }

    /**
     * @param  list<array<string, mixed>>  $complianceItems
     */
    private function buildResult(
        CrewAssignment $assignment,
        array $complianceItems,
        ?User $user,
        bool $includeHrefs,
    ): CrewMobilisationReadinessResult {
        $checks = [];
        $problems = [];
        $clear = 0;

        foreach ($complianceItems as $item) {
            $status = DocumentRequirementComplianceStatus::tryFrom((string) ($item['status'] ?? ''));
            $title = (string) ($item['document_type'] ?? 'Document');
            $check = $this->checkFromCompliance($title, $status, $item);
            $checks[] = $check;

            if ($check['severity'] === 'ok') {
                $clear++;
            } else {
                $problems[] = $check;
            }
        }

        $status = $this->resolveStatus($problems);
        $employeeId = $assignment->employee_id !== null ? (int) $assignment->employee_id : null;

        return new CrewMobilisationReadinessResult(
            status: $status,
            checksClear: $clear,
            checksTotal: count($checks),
            checks: $checks,
            problems: $problems,
            documentsHref: $includeHrefs ? $this->documentsHref($user, $employeeId) : null,
            applies: true,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $problems
     */
    private function resolveStatus(array $problems): CrewMobilisationReadinessStatus
    {
        foreach ($problems as $problem) {
            if (($problem['severity'] ?? '') === 'critical') {
                return CrewMobilisationReadinessStatus::NotReady;
            }
        }

        if ($problems !== []) {
            return CrewMobilisationReadinessStatus::Attention;
        }

        return CrewMobilisationReadinessStatus::Ready;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{code: string, severity: 'ok'|'warning'|'critical', label: string, message: string, document_type_id: int|null}
     */
    private function checkFromCompliance(
        string $title,
        ?DocumentRequirementComplianceStatus $status,
        array $item,
    ): array {
        $documentTypeId = isset($item['document_type_id']) ? (int) $item['document_type_id'] : null;

        if ($status === DocumentRequirementComplianceStatus::Missing) {
            return [
                'code' => 'document_missing',
                'severity' => 'critical',
                'label' => $title.' missing',
                'message' => $title.' is required and has no upload.',
                'document_type_id' => $documentTypeId,
            ];
        }

        if ($status === DocumentRequirementComplianceStatus::Expired) {
            return [
                'code' => 'document_expired',
                'severity' => 'critical',
                'label' => $title.' expired',
                'message' => $title.' is required and has expired.',
                'document_type_id' => $documentTypeId,
            ];
        }

        if ($status === DocumentRequirementComplianceStatus::Expiring) {
            $expiryLabel = (string) ($item['expiry_label'] ?? DocumentExpiry::humanLabel($item['expiry_date'] ?? null));

            return [
                'code' => 'document_expiring',
                'severity' => 'warning',
                'label' => $title.' expiring soon',
                'message' => $title.' '.$this->lowercaseFirst($expiryLabel).'.',
                'document_type_id' => $documentTypeId,
            ];
        }

        return [
            'code' => 'document_valid',
            'severity' => 'ok',
            'label' => $title.' valid',
            'message' => $title.' is current.',
            'document_type_id' => $documentTypeId,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function uniqueByDocumentType(array $items): array
    {
        $unique = [];

        foreach ($items as $item) {
            $typeId = (int) ($item['document_type_id'] ?? 0);
            $key = $typeId > 0 ? (string) $typeId : spl_object_hash((object) $item);

            if (isset($unique[$key])) {
                continue;
            }

            $unique[$key] = $item;
        }

        return array_values($unique);
    }

    private function lowercaseFirst(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        return mb_strtolower(mb_substr($value, 0, 1)).mb_substr($value, 1);
    }

    private function documentsHref(?User $user, ?int $employeeId): ?string
    {
        if ($user === null || $employeeId === null || ! $user->can('documents.view')) {
            return null;
        }

        return route('organization.documents.employee', ['employee' => $employeeId]);
    }
}
