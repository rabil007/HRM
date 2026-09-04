<?php

namespace App\Support\Documents\Journey;

use App\Models\BulkDocumentEmailSend;
use App\Models\DocumentGenerationRunItem;
use App\Models\DocumentInstance;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Support\BulkDocuments\BulkDocumentTypeRegistry;

final class DocumentJourneyQuery
{
    /**
     * @param  array{
     *     document_instance_id?: int|string|null,
     *     employee_document_id?: int|string|null,
     *     employee_id?: int|string|null,
     *     version_id?: int|string|null,
     *     document_type_key?: string|null,
     *     generation_run_id?: int|string|null,
     * }  $identifiers
     * @return array{
     *     employee: Employee,
     *     instance: DocumentInstance|null,
     *     employee_document: EmployeeDocument|null,
     *     run_item: DocumentGenerationRunItem|null,
     *     copy_email_send: BulkDocumentEmailSend|null,
     * }|null
     */
    public function resolve(int $companyId, array $identifiers, ?User $viewer = null): ?array
    {
        $instanceId = ! empty($identifiers['document_instance_id']) ? (int) $identifiers['document_instance_id'] : null;
        $employeeDocId = ! empty($identifiers['employee_document_id']) ? (int) $identifiers['employee_document_id'] : null;
        $employeeId = ! empty($identifiers['employee_id']) ? (int) $identifiers['employee_id'] : null;
        $versionId = ! empty($identifiers['version_id']) ? (int) $identifiers['version_id'] : null;
        $documentTypeKey = ! empty($identifiers['document_type_key']) ? (string) $identifiers['document_type_key'] : null;
        $runId = ! empty($identifiers['generation_run_id']) ? (int) $identifiers['generation_run_id'] : null;

        /** @var DocumentInstance|null $instance */
        $instance = null;
        /** @var EmployeeDocument|null $employeeDocument */
        $employeeDocument = null;
        /** @var Employee|null $employee */
        $employee = null;

        // 1. By DocumentInstance ID
        if ($instanceId !== null) {
            $instance = DocumentInstance::query()
                ->forCompany($companyId)
                ->where('id', $instanceId)
                ->with($this->instanceRelations())
                ->first();

            if ($instance !== null) {
                $employee = $instance->employee;
                $employeeDocument = $instance->employeeDocument;
            }
        }

        // 2. By EmployeeDocument ID
        if ($instance === null && $employeeDocId !== null) {
            $instance = DocumentInstance::query()
                ->forCompany($companyId)
                ->where('employee_document_id', $employeeDocId)
                ->with($this->instanceRelations())
                ->first();

            if ($instance !== null) {
                $employee = $instance->employee;
                $employeeDocument = $instance->employeeDocument;
            } else {
                $employeeDocument = EmployeeDocument::query()
                    ->where('company_id', $companyId)
                    ->where('id', $employeeDocId)
                    ->with(['employee.department', 'employee.position', 'documentType'])
                    ->first();

                if ($employeeDocument !== null) {
                    $employee = $employeeDocument->employee;
                }
            }
        }

        // 3. By Employee ID + Template Version ID
        if ($instance === null && $employeeId !== null && $versionId !== null) {
            $instance = DocumentInstance::query()
                ->forCompany($companyId)
                ->where('employee_id', $employeeId)
                ->where('document_generation_template_version_id', $versionId)
                ->withLibraryDocument()
                ->with($this->instanceRelations())
                ->orderByDesc('id')
                ->first();

            if ($instance !== null) {
                $employee = $instance->employee;
                $employeeDocument = $instance->employeeDocument;
            }
        }

        // 4. By Employee ID + Document Type Key (e.g. Salary Certificate)
        if ($instance === null && $employeeDocument === null && $employeeId !== null && $documentTypeKey !== null) {
            $documentType = BulkDocumentTypeRegistry::resolveDocumentType($documentTypeKey);
            $employeeDocument = EmployeeDocument::query()
                ->where('company_id', $companyId)
                ->where('employee_id', $employeeId)
                ->where('document_type_id', $documentType->id)
                ->with(['employee.department', 'employee.position', 'documentType'])
                ->orderByDesc('id')
                ->first();

            if ($employeeDocument !== null) {
                $employee = $employeeDocument->employee;
            }
        }

        // If employee not resolved yet but employeeId exists
        if ($employee === null && $employeeId !== null) {
            $employee = Employee::query()
                ->where('company_id', $companyId)
                ->where('id', $employeeId)
                ->with(['department:id,name', 'position:id,title'])
                ->first();
        }

        if ($employee === null) {
            return null;
        }

        // Resolve generation run item if applicable
        $runItem = null;
        if ($runId !== null) {
            $runItem = DocumentGenerationRunItem::query()
                ->where('company_id', $companyId)
                ->where('document_generation_run_id', $runId)
                ->where('employee_id', $employee->id)
                ->orderByDesc('id')
                ->first();
        } elseif ($instance !== null && $instance->document_generation_run_id !== null) {
            $runItem = DocumentGenerationRunItem::query()
                ->where('company_id', $companyId)
                ->where('document_generation_run_id', $instance->document_generation_run_id)
                ->where('employee_id', $employee->id)
                ->orderByDesc('id')
                ->first();
        }

        // Resolve latest copy email send
        $copyEmailSend = BulkDocumentEmailSend::query()
            ->where('employee_id', $employee->id)
            ->where('status', 'sent')
            ->whereHas('batch', fn ($q) => $q->where('company_id', $companyId))
            ->orderByDesc('sent_at')
            ->first();

        return [
            'employee' => $employee,
            'instance' => $instance,
            'employee_document' => $employeeDocument,
            'run_item' => $runItem,
            'copy_email_send' => $copyEmailSend,
        ];
    }

    /**
     * @return list<string>
     */
    private function instanceRelations(): array
    {
        return [
            'employee.department',
            'employee.position',
            'employeeDocument.documentType',
            'templateVersion.template',
            'currentVersion',
            'versions.creator',
            'generatedBy',
            'lifecycleAutomation.workflowRequest.stages.tasks.decidedByUser',
            'lifecycleAutomation.workflowRequest.stages.tasks.assignee',
            'lifecycleAutomation.workflowRequest.requester',
            'lifecycleAutomation.signingFlow.recipientRequests.deliveries',
            'lifecycleAutomation.signingFlow.recipientRequests.recipientUser',
            'lifecycleAutomation.signingFlow.recipientRequests.events.actor',
            'recipientRequests.deliveries',
            'recipientRequests.recipientUser',
            'recipientRequests.events.actor',
        ];
    }
}
