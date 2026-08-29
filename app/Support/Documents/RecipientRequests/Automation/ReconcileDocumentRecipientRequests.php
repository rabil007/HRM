<?php

namespace App\Support\Documents\RecipientRequests\Automation;

use App\Enums\DocumentRecipientRequestDeliveryChannel;
use App\Enums\DocumentRecipientRequestDeliveryPurpose;
use App\Enums\DocumentRecipientRequestDeliveryStatus;
use App\Enums\DocumentRecipientRequestStatus;
use App\Models\DocumentRecipientRequest;
use App\Models\DocumentRecipientRequestDelivery;
use App\Support\Documents\RecipientRequests\Actions\ExpireDocumentRecipientRequest;
use App\Support\Documents\RecipientRequests\Delivery\QueueDocumentRecipientRequestEmail;
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
     * @return array{expired: int, reminders_queued: int, reminders_suppressed: int, skipped: int}
     */
    public function handle(?int $onlyCompanyId = null): array
    {
        $expired = $this->expireOverdue($onlyCompanyId);
        $reminders = $this->processReminders($onlyCompanyId);

        return [
            'expired' => $expired,
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
            ->where('expires_at', '>', now())
            ->whereNotNull('reminder_policy_snapshot')
            ->when(
                $onlyCompanyId !== null,
                fn ($query) => $query->where('company_id', $onlyCompanyId),
            )
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
                return ['queued' => 0, 'suppressed' => 0, 'skipped' => 1];
            }

            if ($locked->expires_at === null || $locked->expires_at->isPast()) {
                return ['queued' => 0, 'suppressed' => 0, 'skipped' => 1];
            }

            $slots = $this->policy->reminderSlotsForRequest($locked);

            if ($slots === []) {
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

            foreach ($selection['missed'] as $missed) {
                if ($this->createMissedWindowSuppression($locked, $missed)) {
                    $suppressedCount++;
                }
            }

            $queuedCount = 0;

            if ($selection['active'] !== null) {
                $delivery = $this->queueEmail->forReminder(
                    $locked,
                    $selection['active']['automation_key'],
                    $selection['active']['scheduled_for'],
                );

                if ($delivery instanceof DocumentRecipientRequestDelivery) {
                    if ($delivery->status === DocumentRecipientRequestDeliveryStatus::Suppressed) {
                        $suppressedCount++;
                    } else {
                        $queuedCount++;
                    }
                }
            }

            return [
                'queued' => $queuedCount,
                'suppressed' => $suppressedCount,
                'skipped' => 0,
            ];
        });
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
