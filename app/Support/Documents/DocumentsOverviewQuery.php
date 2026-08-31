<?php

namespace App\Support\Documents;

use App\Enums\BulkDocumentSignatureRequestStatus;
use App\Enums\DocumentRecipientRequestStatus;
use App\Enums\DocumentWorkflowRequestStatus;
use App\Models\BulkDocumentSignatureRequest;
use App\Models\User;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestRosterQuery;
use App\Support\Documents\Workflow\DocumentWorkflowPagePermissions;
use App\Support\Documents\Workflow\DocumentWorkflowRosterQuery;
use App\Support\EmployeeDocuments\DocumentBrowseQuery;
use App\Support\EmployeeDocuments\DocumentComplianceQuery;

final class DocumentsOverviewQuery
{
    public function __construct(
        private readonly DocumentBrowseQuery $browse,
        private readonly DocumentComplianceQuery $compliance,
        private readonly DocumentWorkflowRosterQuery $workflowRoster,
        private readonly DocumentRecipientRequestRosterQuery $recipientRoster,
    ) {}

    /**
     * @return array{
     *     summary: array{total_documents: int, expired: int, expiring_30: int, expiring_15: int, expiring_7: int},
     *     requirement_summary: array{required: int, valid: int, expiring: int, expired: int, missing: int},
     *     attention: list<array{
     *         key: string,
     *         label: string,
     *         count: int,
     *         action: string,
     *         destination: 'library'|'requests',
     *         query: array<string, string>
     *     }>,
     *     compliance_types: list<array{document_type_id: int, title: string, missing: int, expiring: int, expired: int}>
     * }
     */
    public function forCompany(int $companyId, ?User $user = null): array
    {
        $summary = $this->browse->expirySummary($companyId);
        $rollup = $this->compliance->overviewRollup($companyId);
        $permissions = DocumentWorkflowPagePermissions::for($user);

        return [
            'summary' => $summary,
            'requirement_summary' => $rollup['summary'],
            'attention' => $this->attention($summary, $rollup['summary'], $companyId, $user, $permissions),
            'compliance_types' => $rollup['types'],
        ];
    }

    /**
     * @param  array{total_documents: int, expired: int, expiring_30: int, expiring_15: int, expiring_7: int}  $summary
     * @param  array{required: int, valid: int, expiring: int, expired: int, missing: int}  $requirementSummary
     * @param  array{
     *     view: bool,
     *     view_signatures: bool,
     *     view_recipient_requests: bool,
     *     respond_recipient_requests: bool
     * }  $permissions
     * @return list<array{
     *     key: string,
     *     label: string,
     *     count: int,
     *     action: string,
     *     destination: 'library'|'requests',
     *     query: array<string, string>
     * }>
     */
    private function attention(
        array $summary,
        array $requirementSummary,
        int $companyId,
        ?User $user,
        array $permissions,
    ): array {
        $items = [];

        if ($requirementSummary['missing'] > 0) {
            $items[] = [
                'key' => 'missing',
                'label' => 'Missing Required',
                'count' => $requirementSummary['missing'],
                'action' => 'View employees',
                'destination' => 'library',
                'query' => ['requirement_status' => 'missing'],
            ];
        }

        if ($summary['expiring_7'] > 0) {
            $items[] = [
                'key' => 'expiring_7',
                'label' => 'Expiring Soon',
                'count' => $summary['expiring_7'],
                'action' => 'Review',
                'destination' => 'library',
                'query' => ['expiry' => 'expiring_7'],
            ];
        }

        if ($summary['expired'] > 0) {
            $items[] = [
                'key' => 'expired',
                'label' => 'Expired',
                'count' => $summary['expired'],
                'action' => 'Review',
                'destination' => 'library',
                'query' => ['expiry' => 'expired'],
            ];
        }

        $awaitingAction = $this->awaitingActionCount($companyId, $user, $permissions);

        if ($awaitingAction > 0) {
            $items[] = [
                'key' => 'awaiting_action',
                'label' => 'Awaiting Your Action',
                'count' => $awaitingAction,
                'action' => 'Review requests',
                'destination' => 'requests',
                'query' => [
                    'tab' => 'review',
                    'status' => DocumentWorkflowRequestStatus::Pending->value,
                    'assigned_to_me' => '1',
                ],
            ];
        }

        $awaitingSignature = $this->awaitingSignature($companyId, $user, $permissions);

        if ($awaitingSignature !== null) {
            $items[] = $awaitingSignature;
        }

        return $items;
    }

    /**
     * @param  array{view: bool}  $permissions
     */
    private function awaitingActionCount(int $companyId, ?User $user, array $permissions): int
    {
        if (! $permissions['view'] || $user === null) {
            return 0;
        }

        return $this->workflowRoster->count($companyId, [
            'status' => DocumentWorkflowRequestStatus::Pending->value,
            'assigned_to_me' => true,
        ], $user);
    }

    /**
     * @param  array{
     *     view_signatures: bool,
     *     view_recipient_requests: bool,
     *     respond_recipient_requests: bool
     * }  $permissions
     * @return array{
     *     key: string,
     *     label: string,
     *     count: int,
     *     action: string,
     *     destination: 'requests',
     *     query: array<string, string>
     * }|null
     */
    private function awaitingSignature(int $companyId, ?User $user, array $permissions): ?array
    {
        $recipientCount = 0;
        $canViewRecipients = $permissions['view_recipient_requests']
            || $permissions['respond_recipient_requests'];

        if ($canViewRecipients && $user !== null) {
            $recipientCount = $this->recipientRoster->count($companyId, [
                'status' => DocumentRecipientRequestStatus::AwaitingAction->value,
                'assigned_to_me' => ! $permissions['view_recipient_requests'],
            ], $user);
        }

        $bulkCount = 0;

        if ($permissions['view_signatures']) {
            $bulkCount = (int) BulkDocumentSignatureRequest::query()
                ->forCompany($companyId)
                ->where('status', BulkDocumentSignatureRequestStatus::AwaitingSignature)
                ->count();
        }

        $count = $recipientCount + $bulkCount;

        if ($count === 0) {
            return null;
        }

        $query = $recipientCount > 0
            ? [
                'tab' => 'recipient',
                'status' => DocumentRecipientRequestStatus::AwaitingAction->value,
            ]
            : [
                'tab' => 'signatures',
                'signature_filter' => 'awaiting_signature',
            ];

        return [
            'key' => 'awaiting_signature',
            'label' => 'Awaiting Signature',
            'count' => $count,
            'action' => 'Follow up',
            'destination' => 'requests',
            'query' => $query,
        ];
    }
}
