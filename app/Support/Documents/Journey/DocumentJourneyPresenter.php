<?php

namespace App\Support\Documents\Journey;

use App\Enums\DocumentRecipientRequestDeliveryStatus;
use App\Enums\DocumentRecipientRequestStatus;
use App\Enums\DocumentWorkflowTaskStatus;
use App\Models\BulkDocumentEmailSend;
use App\Models\DocumentGenerationRunItem;
use App\Models\DocumentInstance;
use App\Models\DocumentLifecycleAutomation;
use App\Models\DocumentRecipientRequest;
use App\Models\DocumentRecipientRequestDelivery;
use App\Models\DocumentWorkflowRequest;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Support\Documents\Process\DocumentOperationalProcessPresenter;
use Illuminate\Support\Collection;

final class DocumentJourneyPresenter
{
    public function __construct(
        private DocumentOperationalProcessPresenter $processPresenter = new DocumentOperationalProcessPresenter,
    ) {}

    /**
     * @param  array{
     *     employee: Employee,
     *     instance: DocumentInstance|null,
     *     employee_document: EmployeeDocument|null,
     *     run_item: DocumentGenerationRunItem|null,
     *     copy_email_send: BulkDocumentEmailSend|null,
     * }  $journeyData
     * @return array<string, mixed>
     */
    public function present(array $journeyData, ?User $viewer = null): array
    {
        $employee = $journeyData['employee'];
        $instance = $journeyData['instance'];
        $doc = $journeyData['employee_document'] ?? $instance?->employeeDocument;
        $runItem = $journeyData['run_item'];
        $copyEmailSend = $journeyData['copy_email_send'];

        $lifecycle = $instance?->lifecycleAutomation;
        $workflow = $lifecycle?->workflowRequest;
        $signingFlow = $lifecycle?->signingFlow;

        /** @var Collection<int, DocumentRecipientRequest> $recipientRequests */
        $recipientRequests = $signingFlow?->recipientRequests ?? $instance?->recipientRequests ?? collect();

        $process = $this->processPresenter->present(
            employee: $employee,
            instance: $instance,
            employeeDocument: $doc,
            runItem: $runItem,
            copyEmailSentAt: $copyEmailSend?->sent_at,
            legacySignatureStatus: null,
            viewer: $viewer,
        );

        $events = $this->buildTimelineEvents(
            $instance,
            $doc,
            $runItem,
            $lifecycle,
            $workflow,
            $recipientRequests,
            $copyEmailSend,
        );

        $actionEmailBanner = $this->buildActionEmailBanner($recipientRequests, $viewer);

        $viewUrl = $doc !== null ? route('organization.documents.files.preview', ['document' => $doc->id]) : null;
        $detailsUrl = $doc !== null ? route('organization.documents.employee.files.show', ['employee' => $employee->id, 'document' => $doc->id]) : null;

        $canViewDocuments = $viewer?->can('documents.view') ?? false;
        $canDownloadDocuments = $viewer?->can('documents.download') ?? false;
        $canResendActionEmail = $viewer?->can('documents.recipient-requests.respond') ?? false;

        return [
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->name,
                'employee_no' => $employee->employee_no,
                'department' => $employee->department?->name,
                'position' => $employee->position?->title,
            ],
            'document' => [
                'id' => $doc?->id,
                'instance_id' => $instance?->id,
                'title' => $doc?->title ?? $instance?->title_snapshot ?? 'Document',
                'document_type' => $doc?->documentType?->name ?? $instance?->template_name_snapshot,
                'version_number' => $instance?->template_version_number ?? $instance?->currentVersion?->version,
                'generated_at' => ($instance?->generated_at ?? $doc?->created_at)?->toIso8601String(),
                'view_url' => $canViewDocuments ? $viewUrl : null,
                'details_url' => $canViewDocuments ? $detailsUrl : null,
            ],
            'process' => $process,
            'events' => $events,
            'action_email_banner' => $actionEmailBanner,
            'permissions' => [
                'can_view_document' => $canViewDocuments,
                'can_download_document' => $canDownloadDocuments,
                'can_resend_action_email' => $canResendActionEmail,
                'can_retry_lifecycle' => $viewer?->can('bulk_documents.manage') ?? false,
            ],
        ];
    }

    /**
     * @param  Collection<int, DocumentRecipientRequest>  $recipientRequests
     * @return list<array<string, mixed>>
     */
    private function buildTimelineEvents(
        ?DocumentInstance $instance,
        ?EmployeeDocument $doc,
        ?DocumentGenerationRunItem $runItem,
        ?DocumentLifecycleAutomation $lifecycle,
        ?DocumentWorkflowRequest $workflow,
        Collection $recipientRequests,
        ?BulkDocumentEmailSend $copyEmailSend,
    ): array {
        $events = [];

        // 1. Generation Event
        $generatedAt = $instance?->generated_at ?? $doc?->created_at ?? $runItem?->updated_at;
        if ($generatedAt !== null) {
            $isFailed = $runItem !== null && $runItem->status === 'failed';
            $events[] = [
                'id' => 'evt_gen_'.($instance?->id ?? $doc?->id ?? 'run'),
                'type' => $isFailed ? 'failed' : 'generated',
                'title' => $isFailed ? 'Generation failed' : 'Document generated',
                'description' => $isFailed ? ($runItem->error_message ?? 'Template rendering failed') : null,
                'actor' => $instance?->generatedBy?->name ?? 'System',
                'status' => $isFailed ? 'failed' : 'completed',
                'timestamp' => $generatedAt->toIso8601String(),
                'relative' => $generatedAt->diffForHumans(),
                'metadata' => [],
            ];
        }

        // 2. Workflow Approval Events
        if ($workflow !== null) {
            $events[] = [
                'id' => 'evt_wf_req_'.$workflow->id,
                'type' => 'review_requested',
                'title' => 'Review requested',
                'description' => null,
                'actor' => $workflow->requester_name_snapshot ?? $workflow->requester?->name ?? 'System',
                'status' => 'completed',
                'timestamp' => $workflow->requested_at?->toIso8601String(),
                'relative' => $workflow->requested_at?->diffForHumans(),
                'metadata' => [],
            ];

            foreach ($workflow->stages as $stage) {
                foreach ($stage->tasks as $task) {
                    if ($task->status === DocumentWorkflowTaskStatus::Completed) {
                        $events[] = [
                            'id' => 'evt_wf_task_'.$task->id,
                            'type' => 'reviewed',
                            'title' => 'Approved by '.($task->decision_actor_name_snapshot ?? $task->decidedByUser?->name ?? 'Approver'),
                            'description' => $task->decision_notes,
                            'actor' => $task->decision_actor_name_snapshot ?? $task->decidedByUser?->name,
                            'status' => 'approved',
                            'timestamp' => $task->decided_at?->toIso8601String(),
                            'relative' => $task->decided_at?->diffForHumans(),
                            'metadata' => [],
                        ];
                    } elseif ($task->status === DocumentWorkflowTaskStatus::Rejected) {
                        $events[] = [
                            'id' => 'evt_wf_task_'.$task->id,
                            'type' => 'reviewed',
                            'title' => 'Rejected by '.($task->decision_actor_name_snapshot ?? $task->decidedByUser?->name ?? 'Approver'),
                            'description' => $task->decision_notes,
                            'actor' => $task->decision_actor_name_snapshot ?? $task->decidedByUser?->name,
                            'status' => 'rejected',
                            'timestamp' => $task->decided_at?->toIso8601String(),
                            'relative' => $task->decided_at?->diffForHumans(),
                            'metadata' => [],
                        ];
                    } elseif ($task->status === DocumentWorkflowTaskStatus::Pending) {
                        $events[] = [
                            'id' => 'evt_wf_task_'.$task->id,
                            'type' => 'review_requested',
                            'title' => 'Awaiting review from '.($task->assignee_name_snapshot ?? $task->assignee?->name ?? 'Approver'),
                            'description' => null,
                            'actor' => $task->assignee_name_snapshot ?? $task->assignee?->name,
                            'status' => 'pending',
                            'timestamp' => $task->created_at?->toIso8601String(),
                            'relative' => $task->created_at?->diffForHumans(),
                            'metadata' => [],
                        ];
                    }
                }
            }
        }

        // 3. Signing Flow & Recipient Request Events
        foreach ($recipientRequests as $req) {
            $events[] = [
                'id' => 'evt_sign_req_'.$req->id,
                'type' => 'signing_requested',
                'title' => 'Signature requested: '.$req->recipient_name_snapshot.' ('.$req->recipient_role->label().')',
                'description' => null,
                'actor' => $req->recipient_name_snapshot,
                'status' => $req->status->value,
                'timestamp' => $req->requested_at?->toIso8601String(),
                'relative' => $req->requested_at?->diffForHumans(),
                'metadata' => [
                    'recipient_role' => $req->recipient_role->value,
                    'step_sequence' => $req->signing_step_sequence,
                ],
            ];

            if ($req->relationLoaded('deliveries')) {
                foreach ($req->deliveries as $delivery) {
                    $ts = $delivery->sent_at ?? $delivery->failed_at ?? $delivery->last_attempt_at ?? $delivery->created_at;
                    if ($delivery->status === DocumentRecipientRequestDeliveryStatus::Sent) {
                        $events[] = [
                            'id' => 'evt_deliv_'.$delivery->id,
                            'type' => 'action_email_sent',
                            'title' => 'Action email sent to '.$req->recipient_name_snapshot,
                            'description' => null,
                            'actor' => 'System',
                            'status' => 'sent',
                            'timestamp' => $ts?->toIso8601String(),
                            'relative' => $ts?->diffForHumans(),
                            'metadata' => [],
                        ];
                    } elseif ($delivery->status === DocumentRecipientRequestDeliveryStatus::Failed) {
                        $events[] = [
                            'id' => 'evt_deliv_'.$delivery->id,
                            'type' => 'action_email_failed',
                            'title' => 'Action email delivery failed',
                            'description' => DocumentOperationalProcessPresenter::humanFailureMessage($delivery->failure_category ?? ''),
                            'actor' => 'System',
                            'status' => 'failed',
                            'timestamp' => $ts?->toIso8601String(),
                            'relative' => $ts?->diffForHumans(),
                            'metadata' => [
                                'failure_category' => $delivery->failure_category,
                                'recipient_request_id' => $req->id,
                            ],
                        ];
                    }
                }
            }

            if ($req->status === DocumentRecipientRequestStatus::Completed && $req->completed_at !== null) {
                $events[] = [
                    'id' => 'evt_signed_'.$req->id,
                    'type' => 'signed',
                    'title' => 'Signed by '.($req->signed_name ?? $req->recipient_name_snapshot),
                    'description' => null,
                    'actor' => $req->signed_name ?? $req->recipient_name_snapshot,
                    'status' => 'completed',
                    'timestamp' => $req->completed_at->toIso8601String(),
                    'relative' => $req->completed_at->diffForHumans(),
                    'metadata' => [
                        'recipient_role' => $req->recipient_role->value,
                    ],
                ];
            }
        }

        // 4. Document Copy Email Delivery Event
        if ($copyEmailSend !== null && $copyEmailSend->sent_at !== null) {
            $events[] = [
                'id' => 'evt_copy_email_'.$copyEmailSend->id,
                'type' => 'copy_email_sent',
                'title' => 'Document copy emailed to employee',
                'description' => null,
                'actor' => 'System',
                'status' => 'sent',
                'timestamp' => $copyEmailSend->sent_at->toIso8601String(),
                'relative' => $copyEmailSend->sent_at->diffForHumans(),
                'metadata' => [],
            ];
        }

        // 5. Lifecycle Blocked Event
        if ($lifecycle !== null && $lifecycle->status->value === 'blocked') {
            $blockedAt = $lifecycle->blocked_at ?? $lifecycle->updated_at;
            $events[] = [
                'id' => 'evt_blocked_'.$lifecycle->id,
                'type' => 'blocked',
                'title' => 'Process needs attention',
                'description' => $lifecycle->blocked_message ?? 'Automatic progression was halted.',
                'actor' => 'System',
                'status' => 'blocked',
                'timestamp' => $blockedAt?->toIso8601String(),
                'relative' => $blockedAt?->diffForHumans(),
                'metadata' => [
                    'blocked_code' => $lifecycle->blocked_code,
                ],
            ];
        }

        // 6. Final Completed Event
        if ($lifecycle !== null && $lifecycle->status->value === 'completed' && $lifecycle->completed_at !== null) {
            $events[] = [
                'id' => 'evt_completed_'.$lifecycle->id,
                'type' => 'completed',
                'title' => 'Document journey completed',
                'description' => 'All review, signing, and delivery steps finished successfully.',
                'actor' => 'System',
                'status' => 'completed',
                'timestamp' => $lifecycle->completed_at->toIso8601String(),
                'relative' => $lifecycle->completed_at->diffForHumans(),
                'metadata' => [],
            ];
        }

        // Sort events chronologically
        usort($events, function (array $a, array $b): int {
            $tsA = $a['timestamp'] ?? '';
            $tsB = $b['timestamp'] ?? '';

            return strcmp($tsA, $tsB);
        });

        return $events;
    }

    /**
     * @param  Collection<int, DocumentRecipientRequest>  $recipientRequests
     * @return array{
     *     show: bool,
     *     category: string|null,
     *     message: string|null,
     *     can_resend: bool,
     *     recipient_request_id: int|null,
     * }|null
     */
    private function buildActionEmailBanner(Collection $recipientRequests, ?User $viewer): ?array
    {
        foreach ($recipientRequests as $req) {
            if (! $req->relationLoaded('deliveries')) {
                continue;
            }

            /** @var DocumentRecipientRequestDelivery|null $latest */
            $latest = $req->deliveries->sortByDesc('delivery_sequence')->first();

            if ($latest instanceof DocumentRecipientRequestDelivery && $latest->status === DocumentRecipientRequestDeliveryStatus::Failed) {
                $category = $latest->failure_category ?? 'unknown';
                $canResend = $viewer !== null
                    && $viewer->can('documents.recipient-requests.respond')
                    && $req->isAwaitingAction();

                return [
                    'show' => true,
                    'category' => $category,
                    'message' => DocumentOperationalProcessPresenter::humanFailureMessage($category),
                    'can_resend' => $canResend,
                    'recipient_request_id' => $req->id,
                ];
            }
        }

        return null;
    }
}
