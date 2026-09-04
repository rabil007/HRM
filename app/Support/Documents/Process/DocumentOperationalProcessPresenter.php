<?php

namespace App\Support\Documents\Process;

use App\Enums\DocumentRecipientRequestStatus;
use App\Enums\DocumentRecipientRole;
use App\Enums\DocumentWorkflowStageStatus;
use App\Enums\DocumentWorkflowTaskStatus;
use App\Models\DocumentGenerationRunItem;
use App\Models\DocumentInstance;
use App\Models\DocumentRecipientRequest;
use App\Models\DocumentRecipientRequestDelivery;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

final class DocumentOperationalProcessPresenter
{
    /**
     * @return array{
     *     status: string,
     *     label: string,
     *     tone: 'neutral'|'info'|'warning'|'success'|'danger',
     *     stage: 'review'|'signing'|'delivery'|'generation'|null,
     *     waiting_for: string|null,
     *     last_activity: array{
     *         event: string,
     *         timestamp: string|null,
     *         relative: string|null,
     *     }|null,
     *     action_email: array{
     *         status: string,
     *         failure_category: string|null,
     *         failure_message: string|null,
     *         attempted_at: string|null,
     *     }|null,
     *     document_copy_email: array{
     *         status: string,
     *         sent_at: string|null,
     *     },
     *     authorized_action_url: string|null,
     *     workflow_request_id: int|null,
     *     signing_flow_id: int|null,
     *     recipient_request_id: int|null,
     *     document_instance_id: int|null,
     *     employee_document_id: int|null,
     * }
     */
    public function present(
        Employee $employee,
        ?DocumentInstance $instance = null,
        ?EmployeeDocument $employeeDocument = null,
        ?DocumentGenerationRunItem $runItem = null,
        ?CarbonInterface $copyEmailSentAt = null,
        ?string $legacySignatureStatus = null,
        ?User $viewer = null,
    ): array {
        $doc = $instance?->employeeDocument ?? $employeeDocument;
        $lifecycle = $instance?->lifecycleAutomation;
        $workflow = $lifecycle?->workflowRequest;
        $signingFlow = $lifecycle?->signingFlow;

        $lifecycleStatus = $lifecycle?->status instanceof DocumentLifecycleAutomationStatus
            ? $lifecycle->status->value
            : ($lifecycle?->status !== null ? (string) ($lifecycle->status->value ?? $lifecycle->status) : null);

        $lifecycleStage = $lifecycle?->stage instanceof DocumentLifecycleAutomationStage
            ? $lifecycle->stage->value
            : ($lifecycle?->stage !== null ? (string) ($lifecycle->stage->value ?? $lifecycle->stage) : null);

        // Collect recipient requests from signingFlow or directly from instance
        $recipientRequests = $signingFlow?->recipientRequests
            ?? $instance?->recipientRequests
            ?? collect();

        // 1. Generation Run Failed
        if ($runItem !== null && $runItem->status === 'failed') {
            return [
                'status' => 'failed',
                'label' => 'Failed',
                'tone' => 'danger',
                'stage' => 'generation',
                'waiting_for' => null,
                'last_activity' => $this->resolveLastActivity('Generation failed', $runItem->updated_at),
                'action_email' => null,
                'document_copy_email' => [
                    'status' => 'not_sent',
                    'sent_at' => null,
                ],
                'authorized_action_url' => null,
                'workflow_request_id' => null,
                'signing_flow_id' => null,
                'recipient_request_id' => null,
                'document_instance_id' => $instance?->id,
                'employee_document_id' => $doc?->id,
            ];
        }

        // 2. Lifecycle Blocked
        if ($lifecycle !== null && $lifecycleStatus === 'blocked') {
            return [
                'status' => 'blocked',
                'label' => 'Needs attention',
                'tone' => 'warning',
                'stage' => $lifecycleStage,
                'waiting_for' => null,
                'last_activity' => $this->resolveLastActivity('Lifecycle blocked', $lifecycle->blocked_at ?? $lifecycle->updated_at),
                'action_email' => $this->resolveActionEmail($recipientRequests),
                'document_copy_email' => $this->resolveCopyEmail($copyEmailSentAt),
                'authorized_action_url' => null,
                'workflow_request_id' => $workflow?->id,
                'signing_flow_id' => $signingFlow?->id,
                'recipient_request_id' => null,
                'document_instance_id' => $instance?->id,
                'employee_document_id' => $doc?->id,
            ];
        }

        // 3. Actively Generating
        if ($runItem !== null && in_array($runItem->status, ['pending', 'processing'], true)) {
            return [
                'status' => 'generating',
                'label' => 'Generating',
                'tone' => 'info',
                'stage' => 'generation',
                'waiting_for' => null,
                'last_activity' => $this->resolveLastActivity('Generation in progress', $runItem->updated_at ?? $runItem->created_at),
                'action_email' => null,
                'document_copy_email' => [
                    'status' => 'not_sent',
                    'sent_at' => null,
                ],
                'authorized_action_url' => null,
                'workflow_request_id' => null,
                'signing_flow_id' => null,
                'recipient_request_id' => null,
                'document_instance_id' => $instance?->id,
                'employee_document_id' => $doc?->id,
            ];
        }

        // 4. Not Started / Not Generated
        if ($instance === null && $doc === null) {
            return [
                'status' => 'not_generated',
                'label' => 'Not started',
                'tone' => 'neutral',
                'stage' => null,
                'waiting_for' => null,
                'last_activity' => null,
                'action_email' => null,
                'document_copy_email' => [
                    'status' => 'not_sent',
                    'sent_at' => null,
                ],
                'authorized_action_url' => null,
                'workflow_request_id' => null,
                'signing_flow_id' => null,
                'recipient_request_id' => null,
                'document_instance_id' => null,
                'employee_document_id' => null,
            ];
        }

        // 5. Lifecycle-driven document
        if ($lifecycle !== null) {
            $actionEmail = $this->resolveActionEmail($recipientRequests);
            $copyEmail = $this->resolveCopyEmail($copyEmailSentAt);

            if ($lifecycleStatus === 'completed') {
                return [
                    'status' => 'completed',
                    'label' => 'Completed',
                    'tone' => 'success',
                    'stage' => null,
                    'waiting_for' => null,
                    'last_activity' => $this->resolveLastActivity('Completed', $lifecycle->completed_at ?? $instance?->updated_at),
                    'action_email' => $actionEmail,
                    'document_copy_email' => $copyEmail,
                    'authorized_action_url' => null,
                    'workflow_request_id' => $workflow?->id,
                    'signing_flow_id' => $signingFlow?->id,
                    'recipient_request_id' => null,
                    'document_instance_id' => $instance?->id,
                    'employee_document_id' => $doc?->id,
                ];
            }

            if ($lifecycleStage === 'review' && $workflow !== null) {
                // Find active stage and pending task
                $activeStage = $workflow->stages->first(fn ($s) => $s->status === DocumentWorkflowStageStatus::Active)
                    ?? $workflow->stages->first();
                $pendingTask = $activeStage?->tasks->first(fn ($t) => $t->status === DocumentWorkflowTaskStatus::Pending);

                $waitingFor = $pendingTask?->assignee_name_snapshot
                    ?? $pendingTask?->assignee?->name
                    ?? 'Approver';

                $authorizedActionUrl = null;
                if ($viewer !== null && $pendingTask !== null && (int) $pendingTask->assignee_user_id === (int) $viewer->id) {
                    $authorizedActionUrl = route('organization.documents.requests', [
                        'tab' => 'review',
                        'search' => $workflow->requester_name_snapshot ?? '',
                    ]);
                }

                return [
                    'status' => 'awaiting_approval',
                    'label' => 'Awaiting approval',
                    'tone' => 'info',
                    'stage' => 'review',
                    'waiting_for' => $waitingFor,
                    'last_activity' => $this->resolveLastActivity('Review requested', $activeStage?->created_at ?? $workflow->requested_at),
                    'action_email' => $actionEmail,
                    'document_copy_email' => $copyEmail,
                    'authorized_action_url' => $authorizedActionUrl,
                    'workflow_request_id' => $workflow->id,
                    'signing_flow_id' => $signingFlow?->id,
                    'recipient_request_id' => null,
                    'document_instance_id' => $instance?->id,
                    'employee_document_id' => $doc?->id,
                ];
            }

            if ($lifecycleStage === 'signing') {
                // Find active recipient request
                $activeRequest = $recipientRequests->first(fn (DocumentRecipientRequest $r) => $r->status === DocumentRecipientRequestStatus::AwaitingAction)
                    ?? $recipientRequests->first();

                $role = $activeRequest?->recipient_role;
                $statusKey = 'awaiting_employee_signature';
                $statusLabel = 'Awaiting employee signature';

                if ($role === DocumentRecipientRole::Manager || $role?->value === 'manager') {
                    $statusKey = 'awaiting_manager_signature';
                    $statusLabel = 'Awaiting manager signature';
                } elseif ($role === DocumentRecipientRole::CompanySignatory || $role?->value === 'company_signatory') {
                    $statusKey = 'awaiting_company_signature';
                    $statusLabel = 'Awaiting company signature';
                }

                $waitingFor = $activeRequest?->recipient_name_snapshot
                    ?? ($role === DocumentRecipientRole::Subject ? $employee->name : 'Signatory');

                $authorizedActionUrl = null;
                if ($viewer !== null && $activeRequest !== null && $activeRequest->isInternalSigner() && (int) $activeRequest->recipient_user_id === (int) $viewer->id) {
                    $authorizedActionUrl = route('organization.documents.recipient-requests.respond', [
                        'recipientRequest' => $activeRequest->id,
                    ]);
                }

                $lastActivity = null;
                if ($actionEmail !== null && $actionEmail['status'] === 'failed') {
                    $lastActivity = $this->resolveLastActivity('Action email failed', $actionEmail['attempted_at']);
                } elseif ($actionEmail !== null && $actionEmail['status'] === 'sent') {
                    $lastActivity = $this->resolveLastActivity('Action email sent', $actionEmail['attempted_at']);
                } else {
                    $lastActivity = $this->resolveLastActivity('Signing requested', $activeRequest?->requested_at);
                }

                return [
                    'status' => $statusKey,
                    'label' => $statusLabel,
                    'tone' => 'info',
                    'stage' => 'signing',
                    'waiting_for' => $waitingFor,
                    'last_activity' => $lastActivity,
                    'action_email' => $actionEmail,
                    'document_copy_email' => $copyEmail,
                    'authorized_action_url' => $authorizedActionUrl,
                    'workflow_request_id' => $workflow?->id,
                    'signing_flow_id' => $signingFlow?->id,
                    'recipient_request_id' => $activeRequest?->id,
                    'document_instance_id' => $instance?->id,
                    'employee_document_id' => $doc?->id,
                ];
            }

            if ($lifecycleStage === 'delivery') {
                return [
                    'status' => 'in_progress',
                    'label' => 'Delivering',
                    'tone' => 'info',
                    'stage' => 'delivery',
                    'waiting_for' => null,
                    'last_activity' => $this->resolveLastActivity('Delivery in progress', $lifecycle->updated_at),
                    'action_email' => $actionEmail,
                    'document_copy_email' => $copyEmail,
                    'authorized_action_url' => null,
                    'workflow_request_id' => $workflow?->id,
                    'signing_flow_id' => $signingFlow?->id,
                    'recipient_request_id' => null,
                    'document_instance_id' => $instance?->id,
                    'employee_document_id' => $doc?->id,
                ];
            }
        }

        // 6. Direct Recipient Requests (without lifecycle automation)
        if ($recipientRequests->isNotEmpty()) {
            $actionEmail = $this->resolveActionEmail($recipientRequests);
            $copyEmail = $this->resolveCopyEmail($copyEmailSentAt);
            $activeRequest = $recipientRequests->first(fn (DocumentRecipientRequest $r) => $r->status === DocumentRecipientRequestStatus::AwaitingAction);

            if ($activeRequest !== null) {
                $role = $activeRequest->recipient_role;
                $statusKey = 'awaiting_employee_signature';
                $statusLabel = 'Awaiting employee signature';

                if ($role === DocumentRecipientRole::Manager || $role?->value === 'manager') {
                    $statusKey = 'awaiting_manager_signature';
                    $statusLabel = 'Awaiting manager signature';
                } elseif ($role === DocumentRecipientRole::CompanySignatory || $role?->value === 'company_signatory') {
                    $statusKey = 'awaiting_company_signature';
                    $statusLabel = 'Awaiting company signature';
                }

                $waitingFor = $activeRequest->recipient_name_snapshot
                    ?? ($role === DocumentRecipientRole::Subject ? $employee->name : 'Signatory');

                $authorizedActionUrl = null;
                if ($viewer !== null && $activeRequest->isInternalSigner() && (int) $activeRequest->recipient_user_id === (int) $viewer->id) {
                    $authorizedActionUrl = route('organization.documents.recipient-requests.respond', [
                        'recipientRequest' => $activeRequest->id,
                    ]);
                }

                return [
                    'status' => $statusKey,
                    'label' => $statusLabel,
                    'tone' => 'info',
                    'stage' => 'signing',
                    'waiting_for' => $waitingFor,
                    'last_activity' => $this->resolveLastActivity('Signing requested', $activeRequest->requested_at),
                    'action_email' => $actionEmail,
                    'document_copy_email' => $copyEmail,
                    'authorized_action_url' => $authorizedActionUrl,
                    'workflow_request_id' => null,
                    'signing_flow_id' => null,
                    'recipient_request_id' => $activeRequest->id,
                    'document_instance_id' => $instance?->id,
                    'employee_document_id' => $doc?->id,
                ];
            }

            $allCompleted = $recipientRequests->every(fn ($r) => $r->status === DocumentRecipientRequestStatus::Completed);
            if ($allCompleted) {
                return [
                    'status' => 'completed',
                    'label' => 'Completed',
                    'tone' => 'success',
                    'stage' => null,
                    'waiting_for' => null,
                    'last_activity' => $this->resolveLastActivity('Signing completed', $recipientRequests->max('completed_at')),
                    'action_email' => $actionEmail,
                    'document_copy_email' => $copyEmail,
                    'authorized_action_url' => null,
                    'workflow_request_id' => null,
                    'signing_flow_id' => null,
                    'recipient_request_id' => null,
                    'document_instance_id' => $instance?->id,
                    'employee_document_id' => $doc?->id,
                ];
            }
        }

        // 7. Legacy Signature Status (e.g. Salary Certificate)
        if ($legacySignatureStatus !== null) {
            $copyEmail = $this->resolveCopyEmail($copyEmailSentAt);
            if ($legacySignatureStatus === 'pending_review') {
                return [
                    'status' => 'awaiting_approval',
                    'label' => 'Awaiting approval',
                    'tone' => 'info',
                    'stage' => 'review',
                    'waiting_for' => 'Approver',
                    'last_activity' => $this->resolveLastActivity('Submitted for review', $doc?->created_at),
                    'action_email' => null,
                    'document_copy_email' => $copyEmail,
                    'authorized_action_url' => null,
                    'workflow_request_id' => null,
                    'signing_flow_id' => null,
                    'recipient_request_id' => null,
                    'document_instance_id' => $instance?->id,
                    'employee_document_id' => $doc?->id,
                ];
            }

            if ($legacySignatureStatus === 'awaiting_signature') {
                return [
                    'status' => 'awaiting_employee_signature',
                    'label' => 'Awaiting employee signature',
                    'tone' => 'info',
                    'stage' => 'signing',
                    'waiting_for' => $employee->name,
                    'last_activity' => $this->resolveLastActivity('Awaiting signature', $doc?->created_at),
                    'action_email' => null,
                    'document_copy_email' => $copyEmail,
                    'authorized_action_url' => null,
                    'workflow_request_id' => null,
                    'signing_flow_id' => null,
                    'recipient_request_id' => null,
                    'document_instance_id' => $instance?->id,
                    'employee_document_id' => $doc?->id,
                ];
            }

            if ($legacySignatureStatus === 'approved') {
                return [
                    'status' => 'completed',
                    'label' => 'Completed',
                    'tone' => 'success',
                    'stage' => null,
                    'waiting_for' => null,
                    'last_activity' => $this->resolveLastActivity('Signed & approved', $doc?->created_at),
                    'action_email' => null,
                    'document_copy_email' => $copyEmail,
                    'authorized_action_url' => null,
                    'workflow_request_id' => null,
                    'signing_flow_id' => null,
                    'recipient_request_id' => null,
                    'document_instance_id' => $instance?->id,
                    'employee_document_id' => $doc?->id,
                ];
            }

            if ($legacySignatureStatus === 'rejected') {
                return [
                    'status' => 'blocked',
                    'label' => 'Needs attention',
                    'tone' => 'warning',
                    'stage' => 'review',
                    'waiting_for' => null,
                    'last_activity' => $this->resolveLastActivity('Rejected', $doc?->created_at),
                    'action_email' => null,
                    'document_copy_email' => $copyEmail,
                    'authorized_action_url' => null,
                    'workflow_request_id' => null,
                    'signing_flow_id' => null,
                    'recipient_request_id' => null,
                    'document_instance_id' => $instance?->id,
                    'employee_document_id' => $doc?->id,
                ];
            }
        }

        // 8. Generated instance with no downstream workflow
        $generatedAt = $instance?->generated_at ?? $doc?->created_at;

        return [
            'status' => 'generated',
            'label' => 'Generated',
            'tone' => 'neutral',
            'stage' => null,
            'waiting_for' => null,
            'last_activity' => $this->resolveLastActivity('Generated', $generatedAt),
            'action_email' => null,
            'document_copy_email' => $this->resolveCopyEmail($copyEmailSentAt),
            'authorized_action_url' => null,
            'workflow_request_id' => null,
            'signing_flow_id' => null,
            'recipient_request_id' => null,
            'document_instance_id' => $instance?->id,
            'employee_document_id' => $doc?->id,
        ];
    }

    /**
     * @param  iterable<int, DocumentRecipientRequest>  $recipientRequests
     * @return array{
     *     status: string,
     *     failure_category: string|null,
     *     failure_message: string|null,
     *     attempted_at: string|null,
     * }|null
     */
    private function resolveActionEmail(iterable $recipientRequests): ?array
    {
        /** @var DocumentRecipientRequestDelivery|null $latestDelivery */
        $latestDelivery = null;

        foreach ($recipientRequests as $req) {
            if (! $req->relationLoaded('deliveries')) {
                continue;
            }
            foreach ($req->deliveries as $delivery) {
                if ($latestDelivery === null || $delivery->id > $latestDelivery->id) {
                    $latestDelivery = $delivery;
                }
            }
        }

        if ($latestDelivery === null) {
            return null;
        }

        $statusStr = $latestDelivery->status->value;
        $category = $latestDelivery->failure_category;
        $attemptedAt = $latestDelivery->sent_at ?? $latestDelivery->failed_at ?? $latestDelivery->last_attempt_at ?? $latestDelivery->created_at;

        return [
            'status' => $statusStr,
            'failure_category' => $category,
            'failure_message' => $category !== null ? $this->humanFailureMessage($category) : null,
            'attempted_at' => $attemptedAt?->toIso8601String(),
        ];
    }

    /**
     * @return array{
     *     status: string,
     *     sent_at: string|null,
     * }
     */
    private function resolveCopyEmail(?CarbonInterface $sentAt): array
    {
        return [
            'status' => $sentAt !== null ? 'sent' : 'not_sent',
            'sent_at' => $sentAt?->toIso8601String(),
        ];
    }

    /**
     * @return array{
     *     event: string,
     *     timestamp: string|null,
     *     relative: string|null,
     * }|null
     */
    private function resolveLastActivity(string $event, mixed $timestamp): ?array
    {
        if ($timestamp === null) {
            return [
                'event' => $event,
                'timestamp' => null,
                'relative' => null,
            ];
        }

        $carbon = $timestamp instanceof CarbonInterface ? $timestamp : Carbon::parse($timestamp);

        return [
            'event' => $event,
            'timestamp' => $carbon->toIso8601String(),
            'relative' => $carbon->diffForHumans(),
        ];
    }

    public static function humanFailureMessage(string $category): string
    {
        return match ($category) {
            'recipient_email_missing' => 'Recipient email address is missing.',
            'email_transport', 'email_transport_exhausted' => 'Email delivery could not be sent. Please retry or re-send.',
            'recipient_no_longer_actionable' => 'Recipient is no longer actionable.',
            'access_token_revoked' => 'Access link has been regenerated.',
            'reminder_window_missed' => 'Scheduled reminder window was missed.',
            default => 'Action email delivery could not be completed.',
        };
    }
}
