<?php

namespace App\Support\Documents\RecipientRequests\Actions;

use App\Enums\DocumentRecipientRequestEventType;
use App\Enums\DocumentRecipientRequestStatus;
use App\Models\DocumentInstance;
use App\Models\DocumentRecipientRequest;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestEventRecorder;
use Illuminate\Support\Facades\DB;

final class SupersedeStaleDocumentRecipientRequests
{
    public function __construct(
        private DocumentRecipientRequestEventRecorder $eventRecorder,
    ) {}

    public function forInstanceVersionChange(DocumentInstance $instance, int $companyId): void
    {
        DB::transaction(function () use ($instance, $companyId): void {
            $pending = DocumentRecipientRequest::query()
                ->forCompany($companyId)
                ->where('document_instance_id', $instance->id)
                ->where('status', DocumentRecipientRequestStatus::AwaitingAction)
                ->where('source_document_instance_version_id', '!=', $instance->current_version_id)
                ->lockForUpdate()
                ->get();

            foreach ($pending as $request) {
                $request->update([
                    'status' => DocumentRecipientRequestStatus::Superseded,
                ]);

                $this->eventRecorder->record(
                    $request,
                    DocumentRecipientRequestEventType::RequestSuperseded,
                    metadata: [
                        'document_instance_id' => $instance->id,
                        'current_version_id' => $instance->current_version_id,
                    ],
                );

                activity()
                    ->performedOn($request)
                    ->tap(fn ($activity) => $activity->company_id = $companyId)
                    ->withProperties([
                        'action' => 'recipient_request_superseded',
                        'document_recipient_request_id' => $request->id,
                        'status' => DocumentRecipientRequestStatus::Superseded->value,
                    ])
                    ->log('Recipient request superseded');
            }
        });
    }

    public function markSuperseded(DocumentRecipientRequest $request): void
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
        );
    }
}
