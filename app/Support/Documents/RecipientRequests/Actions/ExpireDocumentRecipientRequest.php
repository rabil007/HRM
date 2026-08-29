<?php

namespace App\Support\Documents\RecipientRequests\Actions;

use App\Enums\DocumentRecipientRequestDeliveryChannel;
use App\Enums\DocumentRecipientRequestDeliveryStatus;
use App\Enums\DocumentRecipientRequestEventType;
use App\Enums\DocumentRecipientRequestStatus;
use App\Models\DocumentRecipientRequest;
use App\Models\DocumentRecipientRequestDelivery;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestEventRecorder;
use App\Support\Documents\Signing\Actions\BlockDocumentSigningFlow;
use Illuminate\Support\Facades\DB;

final class ExpireDocumentRecipientRequest
{
    public const FLOW_BLOCK_REASON = 'The current signing step expired before it was completed.';

    public function __construct(
        private DocumentRecipientRequestEventRecorder $eventRecorder,
    ) {}

    /**
     * Transition an awaiting request to Expired when expires_at has passed.
     * Flow blocking happens after commit to avoid Request → Flow lock inversion.
     */
    public function handle(DocumentRecipientRequest $request, ?int $expectedCompanyId = null): ?DocumentRecipientRequest
    {
        $flowIdToBlock = null;
        $companyIdForBlock = null;

        $expired = DB::transaction(function () use ($request, $expectedCompanyId, &$flowIdToBlock, &$companyIdForBlock): ?DocumentRecipientRequest {
            /** @var DocumentRecipientRequest|null $locked */
            $locked = DocumentRecipientRequest::query()
                ->whereKey($request->id)
                ->when(
                    $expectedCompanyId !== null,
                    fn ($query) => $query->where('company_id', $expectedCompanyId),
                )
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof DocumentRecipientRequest) {
                return null;
            }

            if ($locked->status !== DocumentRecipientRequestStatus::AwaitingAction) {
                return null;
            }

            if ($locked->expires_at === null || $locked->expires_at->greaterThan(now())) {
                return null;
            }

            $locked->update([
                'status' => DocumentRecipientRequestStatus::Expired,
                'next_reminder_at' => null,
            ]);

            $this->eventRecorder->record(
                $locked,
                DocumentRecipientRequestEventType::RequestExpired,
                metadata: [
                    'document_instance_id' => $locked->document_instance_id,
                    'employee_id' => $locked->employee_id,
                    'document_signing_flow_id' => $locked->document_signing_flow_id,
                    'signing_step_sequence' => $locked->signing_step_sequence,
                    'recipient_role' => $locked->recipient_role?->value,
                    'expires_at' => $locked->expires_at?->toIso8601String(),
                ],
            );

            activity()
                ->performedOn($locked)
                ->tap(fn ($activity) => $activity->company_id = (int) $locked->company_id)
                ->withProperties([
                    'action' => 'recipient_request_expired',
                    'document_recipient_request_id' => $locked->id,
                    'document_instance_id' => $locked->document_instance_id,
                    'employee_id' => $locked->employee_id,
                    'document_signing_flow_id' => $locked->document_signing_flow_id,
                    'signing_step_sequence' => $locked->signing_step_sequence,
                    'recipient_role' => $locked->recipient_role?->value,
                    'expires_at' => $locked->expires_at?->toIso8601String(),
                ])
                ->log('Recipient request expired');

            $this->suppressAndRevokeDeliveries($locked);

            if ($locked->document_signing_flow_id !== null) {
                $flowIdToBlock = (int) $locked->document_signing_flow_id;
                $companyIdForBlock = (int) $locked->company_id;
            }

            return $locked->fresh();
        });

        if ($flowIdToBlock !== null && $companyIdForBlock !== null) {
            DB::afterCommit(function () use ($flowIdToBlock, $companyIdForBlock): void {
                try {
                    app(BlockDocumentSigningFlow::class)->handle(
                        $flowIdToBlock,
                        $companyIdForBlock,
                        self::FLOW_BLOCK_REASON,
                    );
                } catch (\Throwable $exception) {
                    report($exception);
                }
            });

            // When already outside a transaction, afterCommit runs immediately.
            // When nested inside another transaction, it runs when that outer
            // transaction commits. Call block synchronously only if no transaction.
            if (DB::transactionLevel() === 0) {
                // afterCommit already fired above when level is 0 in Laravel —
                // but Laravel queues afterCommit callbacks and runs them when
                // the wrapping transaction ends. Outside any transaction,
                // DB::afterCommit executes immediately. No extra call needed.
            }
        }

        return $expired;
    }

    /**
     * Expire under an already-held request row lock (caller owns the transaction).
     * Does not block the signing flow — caller must schedule that after commit.
     *
     * @return array{request: DocumentRecipientRequest, flow_id: int|null, company_id: int}|null
     */
    public function transitionLocked(DocumentRecipientRequest $locked): ?array
    {
        if ($locked->status !== DocumentRecipientRequestStatus::AwaitingAction) {
            return null;
        }

        if ($locked->expires_at === null || $locked->expires_at->greaterThan(now())) {
            return null;
        }

        $locked->update([
            'status' => DocumentRecipientRequestStatus::Expired,
            'next_reminder_at' => null,
        ]);

        $this->eventRecorder->record(
            $locked,
            DocumentRecipientRequestEventType::RequestExpired,
            metadata: [
                'document_instance_id' => $locked->document_instance_id,
                'employee_id' => $locked->employee_id,
                'document_signing_flow_id' => $locked->document_signing_flow_id,
                'signing_step_sequence' => $locked->signing_step_sequence,
                'recipient_role' => $locked->recipient_role?->value,
                'expires_at' => $locked->expires_at?->toIso8601String(),
            ],
        );

        activity()
            ->performedOn($locked)
            ->tap(fn ($activity) => $activity->company_id = (int) $locked->company_id)
            ->withProperties([
                'action' => 'recipient_request_expired',
                'document_recipient_request_id' => $locked->id,
                'document_instance_id' => $locked->document_instance_id,
                'employee_id' => $locked->employee_id,
                'document_signing_flow_id' => $locked->document_signing_flow_id,
                'signing_step_sequence' => $locked->signing_step_sequence,
                'recipient_role' => $locked->recipient_role?->value,
                'expires_at' => $locked->expires_at?->toIso8601String(),
            ])
            ->log('Recipient request expired');

        $this->suppressAndRevokeDeliveries($locked);

        return [
            'request' => $locked->fresh() ?? $locked,
            'flow_id' => $locked->document_signing_flow_id !== null
                ? (int) $locked->document_signing_flow_id
                : null,
            'company_id' => (int) $locked->company_id,
        ];
    }

    public function blockFlowAfterCommit(?int $flowId, int $companyId): void
    {
        if ($flowId === null) {
            return;
        }

        DB::afterCommit(function () use ($flowId, $companyId): void {
            try {
                app(BlockDocumentSigningFlow::class)->handle(
                    $flowId,
                    $companyId,
                    self::FLOW_BLOCK_REASON,
                );
            } catch (\Throwable $exception) {
                report($exception);
            }
        });
    }

    private function suppressAndRevokeDeliveries(DocumentRecipientRequest $request): void
    {
        $deliveries = DocumentRecipientRequestDelivery::query()
            ->where('company_id', $request->company_id)
            ->where('document_recipient_request_id', $request->id)
            ->where('channel', DocumentRecipientRequestDeliveryChannel::Email)
            ->lockForUpdate()
            ->get();

        foreach ($deliveries as $delivery) {
            $attributes = [];

            if ($delivery->access_token_hash !== null && $delivery->revoked_at === null) {
                $attributes['revoked_at'] = now();
            }

            if ($delivery->status === DocumentRecipientRequestDeliveryStatus::Queued) {
                $attributes['status'] = DocumentRecipientRequestDeliveryStatus::Suppressed;
                $attributes['failed_at'] = now();
                $attributes['failure_category'] = 'request_expired';

                if ($delivery->access_token_hash !== null && $delivery->revoked_at === null) {
                    $attributes['revoked_at'] = now();
                }
            } elseif (
                $delivery->status === DocumentRecipientRequestDeliveryStatus::Sent
                && $delivery->access_token_hash !== null
                && $delivery->revoked_at === null
            ) {
                $attributes['revoked_at'] = now();
            }

            if ($attributes !== []) {
                $delivery->update($attributes);
            }
        }
    }
}
