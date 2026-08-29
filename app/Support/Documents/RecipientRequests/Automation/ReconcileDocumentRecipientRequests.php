<?php

namespace App\Support\Documents\RecipientRequests\Automation;

use App\Enums\DocumentRecipientRequestDeliveryChannel;
use App\Enums\DocumentRecipientRequestDeliveryPurpose;
use App\Enums\DocumentRecipientRequestDeliveryStatus;
use App\Enums\DocumentRecipientRequestStatus;
use App\Enums\DocumentSigningFlowStatus;
use App\Models\DocumentRecipientRequest;
use App\Models\DocumentRecipientRequestDelivery;
use App\Models\DocumentSigningFlow;
use App\Support\Documents\RecipientRequests\Actions\ExpireDocumentRecipientRequest;
use App\Support\Documents\RecipientRequests\Delivery\QueueDocumentRecipientRequestEmail;
use App\Support\Documents\Signing\Actions\BlockDocumentSigningFlow;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ReconcileDocumentRecipientRequests
{
    public const BATCH_LIMIT = 100;

    public function __construct(
        private ExpireDocumentRecipientRequest $expireRequest,
        private DocumentRecipientAutomationPolicy $policy,
        private QueueDocumentRecipientRequestEmail $queueEmail,
    ) {}

    /**
     * @return array{expired: int, flows_repaired: int, reminders_queued: int, reminders_suppressed: int, skipped: int}
     */
    public function handle(?int $onlyCompanyId = null): array
    {
        $expired = $this->expireOverdue($onlyCompanyId);
        $flowsRepaired = $this->reconcileExpiredSigningFlows($onlyCompanyId);
        $reminders = $this->processReminders($onlyCompanyId);

        return [
            'expired' => $expired,
            'flows_repaired' => $flowsRepaired,
            'reminders_queued' => $reminders['queued'],
            'reminders_suppressed' => $reminders['suppressed'],
            'skipped' => $reminders['skipped'],
        ];
    }

    public function expireOverdue(?int $onlyCompanyId = null): int
    {
        $expiredCount = 0;

        DocumentRecipientRequest::query()
            ->where('status', DocumentRecipientRequestStatus::AwaitingAction)
            ->where('expires_at', '<=', now())
            ->when(
                $onlyCompanyId !== null,
                fn ($query) => $query->where('company_id', $onlyCompanyId),
            )
            ->orderBy('id')
            ->limit(self::BATCH_LIMIT)
            ->pluck('id')
            ->each(function (int $requestId) use (&$expiredCount): void {
                try {
                    $result = $this->expireRequest->handle(
                        DocumentRecipientRequest::query()->findOrFail($requestId),
                    );

                    if ($result !== null) {
                        $expiredCount++;
                    }
                } catch (Throwable $exception) {
                    report($exception);
                    Log::warning('Document recipient request expiry reconciliation failed', [
                        'document_recipient_request_id' => $requestId,
                        'exception_class' => $exception::class,
                    ]);
                }
            });

        return $expiredCount;
    }

    public function reconcileExpiredSigningFlows(?int $onlyCompanyId = null): int
    {
        $repaired = 0;
        $requestTable = (new DocumentRecipientRequest)->getTable();
        $flowTable = (new DocumentSigningFlow)->getTable();

        DocumentRecipientRequest::query()
            ->select("{$requestTable}.*")
            ->join($flowTable, "{$flowTable}.id", '=', "{$requestTable}.document_signing_flow_id")
            ->where("{$requestTable}.status", DocumentRecipientRequestStatus::Expired)
            ->whereNotNull("{$requestTable}.document_signing_flow_id")
            ->where("{$flowTable}.status", DocumentSigningFlowStatus::Active)
            ->whereColumn("{$flowTable}.company_id", "{$requestTable}.company_id")
            ->whereColumn("{$flowTable}.current_step_sequence", "{$requestTable}.signing_step_sequence")
            ->when(
                $onlyCompanyId !== null,
                fn ($query) => $query->where("{$requestTable}.company_id", $onlyCompanyId),
            )
            ->orderBy("{$requestTable}.id")
            ->limit(self::BATCH_LIMIT)
            ->get()
            ->each(function (DocumentRecipientRequest $request) use (&$repaired): void {
                try {
                    if ($request->document_signing_flow_id === null) {
                        return;
                    }

                    app(BlockDocumentSigningFlow::class)->handle(
                        (int) $request->document_signing_flow_id,
                        (int) $request->company_id,
                        ExpireDocumentRecipientRequest::FLOW_BLOCK_REASON,
                    );
                    $repaired++;
                } catch (Throwable $exception) {
                    report($exception);
                    Log::warning('Document recipient expired signing-flow repair failed', [
                        'document_recipient_request_id' => $request->id,
                        'document_signing_flow_id' => $request->document_signing_flow_id,
                        'exception_class' => $exception::class,
                    ]);
                }
            });

        return $repaired;
    }

    /**
     * @return array{queued: int, suppressed: int, skipped: int}
     */
    public function processReminders(?int $onlyCompanyId = null): array
    {
        $queued = 0;
        $suppressed = 0;
        $skipped = 0;

        DocumentRecipientRequest::query()
            ->where('status', DocumentRecipientRequestStatus::AwaitingAction)
            ->whereNotNull('next_reminder_at')
            ->where('next_reminder_at', '<=', now())
            ->where('expires_at', '>', now())
            ->when(
                $onlyCompanyId !== null,
                fn ($query) => $query->where('company_id', $onlyCompanyId),
            )
            ->orderBy('next_reminder_at')
            ->orderBy('id')
            ->limit(self::BATCH_LIMIT)
            ->pluck('id')
            ->each(function (int $requestId) use (&$queued, &$suppressed, &$skipped): void {
                try {
                    $result = $this->reconcileRemindersForRequest($requestId);
                    $queued += $result['queued'];
                    $suppressed += $result['suppressed'];
                    $skipped += $result['skipped'];
                } catch (Throwable $exception) {
                    report($exception);
                    Log::warning('Document recipient reminder reconciliation failed', [
                        'document_recipient_request_id' => $requestId,
                        'exception_class' => $exception::class,
                    ]);
                    $skipped++;
                }
            });

        return [
            'queued' => $queued,
            'suppressed' => $suppressed,
            'skipped' => $skipped,
        ];
    }

    /**
     * @return array{queued: int, suppressed: int, skipped: int}
     */
    private function reconcileRemindersForRequest(int $requestId): array
    {
        return DB::transaction(function () use ($requestId): array {
            /** @var DocumentRecipientRequest|null $locked */
            $locked = DocumentRecipientRequest::query()
                ->whereKey($requestId)
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof DocumentRecipientRequest) {
                return ['queued' => 0, 'suppressed' => 0, 'skipped' => 1];
            }

            if ($locked->status !== DocumentRecipientRequestStatus::AwaitingAction) {
                $this->clearNextReminderAt($locked);

                return ['queued' => 0, 'suppressed' => 0, 'skipped' => 1];
            }

            if ($locked->expires_at === null || $locked->expires_at->lessThanOrEqualTo(now())) {
                $this->clearNextReminderAt($locked);

                return ['queued' => 0, 'suppressed' => 0, 'skipped' => 1];
            }

            if ($locked->next_reminder_at === null || $locked->next_reminder_at->greaterThan(now())) {
                return ['queued' => 0, 'suppressed' => 0, 'skipped' => 1];
            }

            $slots = $this->policy->reminderSlotsForRequest($locked);

            if ($slots === []) {
                $this->clearNextReminderAt($locked);

                return ['queued' => 0, 'suppressed' => 0, 'skipped' => 1];
            }

            $consumedKeys = DocumentRecipientRequestDelivery::query()
                ->where('document_recipient_request_id', $locked->id)
                ->where('channel', DocumentRecipientRequestDeliveryChannel::Email)
                ->where('purpose', DocumentRecipientRequestDeliveryPurpose::Reminder)
                ->whereNotNull('automation_key')
                ->pluck('automation_key')
                ->all();

            $selection = $this->policy->selectDueReminderSlots($slots, $consumedKeys);

            $suppressedCount = 0;
            $newlyConsumed = [];

            foreach ($selection['missed'] as $missed) {
                if ($this->createMissedWindowSuppression($locked, $missed)) {
                    $suppressedCount++;
                    $newlyConsumed[] = $missed['automation_key'];
                }
            }

            $queuedCount = 0;

            if ($selection['active'] !== null) {
                $delivery = $this->queueEmail->forReminder(
                    $locked,
                    $selection['active']['automation_key'],
                    $selection['active']['scheduled_for'],
                );

                // Slot is consumed whether queued, suppressed (email/template), or already present.
                $newlyConsumed[] = $selection['active']['automation_key'];

                if ($delivery instanceof DocumentRecipientRequestDelivery) {
                    if ($delivery->status === DocumentRecipientRequestDeliveryStatus::Suppressed) {
                        $suppressedCount++;
                    } elseif ($delivery->status === DocumentRecipientRequestDeliveryStatus::Queued) {
                        $queuedCount++;
                    }
                }
            }

            $allConsumed = array_values(array_unique([
                ...$consumedKeys,
                ...$newlyConsumed,
            ]));

            $locked->update([
                'next_reminder_at' => $this->policy->nextReminderAt($locked, $allConsumed),
            ]);

            return [
                'queued' => $queuedCount,
                'suppressed' => $suppressedCount,
                'skipped' => 0,
            ];
        });
    }

    private function clearNextReminderAt(DocumentRecipientRequest $request): void
    {
        if ($request->next_reminder_at !== null) {
            $request->update(['next_reminder_at' => null]);
        }
    }

    /**
     * @param  array{days: int, automation_key: string, scheduled_for: CarbonInterface}  $slot
     */
    private function createMissedWindowSuppression(DocumentRecipientRequest $request, array $slot): bool
    {
        $exists = DocumentRecipientRequestDelivery::query()
            ->where('document_recipient_request_id', $request->id)
            ->where('channel', DocumentRecipientRequestDeliveryChannel::Email)
            ->where('automation_key', $slot['automation_key'])
            ->exists();

        if ($exists) {
            return false;
        }

        try {
            $sequence = DocumentRecipientRequestDelivery::query()
                ->where('document_recipient_request_id', $request->id)
                ->where('channel', DocumentRecipientRequestDeliveryChannel::Email)
                ->lockForUpdate()
                ->max('delivery_sequence');

            DocumentRecipientRequestDelivery::query()->create([
                'company_id' => $request->company_id,
                'document_recipient_request_id' => $request->id,
                'channel' => DocumentRecipientRequestDeliveryChannel::Email,
                'purpose' => DocumentRecipientRequestDeliveryPurpose::Reminder,
                'automation_key' => $slot['automation_key'],
                'scheduled_for' => $slot['scheduled_for'],
                'delivery_sequence' => ((int) $sequence) + 1,
                'destination_snapshot' => null,
                'template_slug' => QueueDocumentRecipientRequestEmail::REMINDER_TEMPLATE_SLUG,
                'status' => DocumentRecipientRequestDeliveryStatus::Suppressed,
                'failed_at' => now(),
                'failure_category' => 'reminder_window_missed',
                'requested_by' => $request->requested_by,
            ]);

            activity()
                ->performedOn($request)
                ->tap(fn ($activity) => $activity->company_id = (int) $request->company_id)
                ->withProperties([
                    'action' => 'recipient_email_suppressed',
                    'document_recipient_request_id' => $request->id,
                    'channel' => DocumentRecipientRequestDeliveryChannel::Email->value,
                    'purpose' => DocumentRecipientRequestDeliveryPurpose::Reminder->value,
                    'status' => DocumentRecipientRequestDeliveryStatus::Suppressed->value,
                    'failure_category' => 'reminder_window_missed',
                    'automation_key' => $slot['automation_key'],
                ])
                ->log('Recipient request email suppressed');

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }
}
