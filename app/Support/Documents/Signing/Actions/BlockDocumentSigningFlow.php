<?php

namespace App\Support\Documents\Signing\Actions;

use App\Enums\DocumentSigningFlowStatus;
use App\Models\DocumentSigningFlow;
use App\Models\User;
use App\Support\Documents\Signing\DocumentSigningFlowActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Concurrency-safe Active/Blocked → Blocked transition.
 *
 * Never resurrects Completed or Cancelled flows.
 */
final class BlockDocumentSigningFlow
{
    public function __construct(
        private DocumentSigningFlowActivityLogger $activityLogger = new DocumentSigningFlowActivityLogger,
    ) {}

    public function handle(
        DocumentSigningFlow|int $flow,
        int $companyId,
        string $reason,
        ?User $actor = null,
    ): DocumentSigningFlow {
        $flowId = $flow instanceof DocumentSigningFlow ? (int) $flow->id : $flow;

        return DB::transaction(function () use ($flowId, $companyId, $reason, $actor): DocumentSigningFlow {
            /** @var DocumentSigningFlow $locked */
            $locked = DocumentSigningFlow::query()
                ->whereKey($flowId)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === DocumentSigningFlowStatus::Completed
                || $locked->status === DocumentSigningFlowStatus::Cancelled) {
                return $locked;
            }

            if ($locked->status !== DocumentSigningFlowStatus::Active
                && $locked->status !== DocumentSigningFlowStatus::Blocked) {
                return $locked;
            }

            if ($locked->status === DocumentSigningFlowStatus::Blocked
                && $locked->blocked_reason === $reason) {
                return $locked;
            }

            $locked->update([
                'status' => DocumentSigningFlowStatus::Blocked,
                'blocked_at' => now(),
                'blocked_reason' => $reason,
            ]);

            $this->activityLogger->log(
                description: 'Document signing flow blocked',
                event: 'signing_flow_blocked',
                flow: $locked->fresh(),
                actor: $actor,
                metadata: [
                    'blocked_reason' => $reason,
                ],
            );

            return $locked->fresh();
        });
    }
}
