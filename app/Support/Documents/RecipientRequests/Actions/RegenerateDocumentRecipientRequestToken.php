<?php

namespace App\Support\Documents\RecipientRequests\Actions;

use App\Enums\DocumentRecipientRequestEventType;
use App\Enums\DocumentRecipientRequestStatus;
use App\Models\DocumentRecipientRequest;
use App\Models\User;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestAccess;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestEventRecorder;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RegenerateDocumentRecipientRequestToken
{
    public function __construct(
        private DocumentRecipientRequestEventRecorder $eventRecorder,
    ) {}

    /**
     * @return array{request: DocumentRecipientRequest, raw_token: string}
     */
    public function handle(DocumentRecipientRequest $request, User $actor, int $companyId): array
    {
        DocumentRecipientRequestAccess::assertInCompany($request, $companyId);
        abort_unless($actor->can('documents.recipient-requests.create'), 403);

        return DB::transaction(function () use ($request, $actor, $companyId): array {
            /** @var DocumentRecipientRequest $locked */
            $locked = DocumentRecipientRequest::query()
                ->whereKey($request->id)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isPublicTokenRecipient()) {
                throw ValidationException::withMessages([
                    'request' => 'Only subject employee requests support secure link regeneration.',
                ]);
            }

            if ($locked->status !== DocumentRecipientRequestStatus::AwaitingAction) {
                throw ValidationException::withMessages([
                    'request' => 'Only awaiting requests can regenerate their secure link.',
                ]);
            }

            if ($locked->isExpired()) {
                throw ValidationException::withMessages([
                    'request' => 'Expired requests cannot regenerate links. Create a new request instead.',
                ]);
            }

            $rawToken = DocumentRecipientRequestToken::generate();

            $locked->update([
                'token_hash' => DocumentRecipientRequestToken::hash($rawToken),
            ]);

            $this->eventRecorder->record(
                $locked,
                DocumentRecipientRequestEventType::TokenRotated,
                $actor,
            );

            activity()
                ->causedBy($actor)
                ->performedOn($locked)
                ->tap(fn ($activity) => $activity->company_id = $companyId)
                ->withProperties([
                    'action' => 'recipient_token_rotated',
                    'document_recipient_request_id' => $locked->id,
                ])
                ->log('Recipient request link regenerated');

            return [
                'request' => $locked->fresh(),
                'raw_token' => $rawToken,
            ];
        });
    }
}
