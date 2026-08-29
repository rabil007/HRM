<?php

namespace App\Support\Documents\RecipientRequests\Actions;

use App\Enums\DocumentRecipientRequestEventType;
use App\Enums\DocumentRecipientRequestStatus;
use App\Models\DocumentInstance;
use App\Models\DocumentRecipientRequest;
use App\Models\DocumentSigningFlow;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestEventRecorder;
use App\Support\Documents\Signing\Actions\AdvanceDocumentSigningFlow;
use Illuminate\Support\Facades\DB;

final class SupersedeStaleDocumentRecipientRequests
{
    public function __construct(
        private DocumentRecipientRequestEventRecorder $eventRecorder,
    ) {}

    public function forInstanceVersionChange(
        DocumentInstance $instance,
        int $companyId,
        ?int $excludeRequestId = null,
    ): void {
        DB::transaction(function () use ($instance, $companyId, $excludeRequestId): void {
            $pending = DocumentRecipientRequest::query()
                ->forCompany($companyId)
                ->where('document_instance_id', $instance->id)
                ->where('status', DocumentRecipientRequestStatus::AwaitingAction)
                ->where('source_document_instance_version_id', '!=', $instance->current_version_id)
                ->when($excludeRequestId !== null, fn ($query) => $query->whereKeyNot($excludeRequestId))
                ->lockForUpdate()
                ->get();

            foreach ($pending as $request) {
                $this->markSuperseded($request, [
                    'document_instance_id' => $instance->id,
                    'current_version_id' => $instance->current_version_id,
                ]);
            }
        });
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function markSuperseded(DocumentRecipientRequest $request, ?array $metadata = null): void
    {
        if ($request->status !== DocumentRecipientRequestStatus::AwaitingAction) {
            return;
        }

        $request->update([
            'status' => DocumentRecipientRequestStatus::Superseded,
        ]);

        $this->eventRecorder->record(
            $request,
            DocumentRecipientRequestEventType::RequestSuperseded,
            metadata: $metadata,
        );

        activity()
            ->performedOn($request)
            ->tap(fn ($activity) => $activity->company_id = $request->company_id)
            ->withProperties([
                'action' => 'recipient_request_superseded',
                'document_recipient_request_id' => $request->id,
                'status' => DocumentRecipientRequestStatus::Superseded->value,
            ])
            ->log('Recipient request superseded');

        if ($request->document_signing_flow_id !== null) {
            $flow = DocumentSigningFlow::query()->find($request->document_signing_flow_id);

            if ($flow !== null && $flow->status->isOpen()) {
                app(AdvanceDocumentSigningFlow::class)->markBlocked(
                    $flow,
                    'The document changed while this signing step was pending.',
                );
            }
        }
    }
}
