<?php

namespace App\Support\Documents\RecipientRequests\Actions;

use App\Enums\DocumentRecipientRequestDeliveryPurpose;
use App\Enums\DocumentRecipientRequestStatus;
use App\Models\DocumentRecipientRequest;
use App\Models\DocumentRecipientRequestDelivery;
use App\Models\User;
use App\Support\Documents\RecipientRequests\Delivery\QueueDocumentRecipientRequestEmail;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ResendDocumentRecipientRequestEmail
{
    public function __construct(
        private QueueDocumentRecipientRequestEmail $queueEmail = new QueueDocumentRecipientRequestEmail,
    ) {}

    public function handle(
        DocumentRecipientRequest $request,
        User $actor,
        int $companyId,
    ): DocumentRecipientRequestDelivery {
        DocumentRecipientRequestAccess::assertInCompany($request, $companyId);
        abort_unless($actor->can('documents.recipient-requests.create'), 403);

        return DB::transaction(function () use ($request, $actor, $companyId): DocumentRecipientRequestDelivery {
            /** @var DocumentRecipientRequest $locked */
            $locked = DocumentRecipientRequest::query()
                ->whereKey($request->id)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== DocumentRecipientRequestStatus::AwaitingAction) {
                throw ValidationException::withMessages([
                    'request' => 'Only awaiting requests can send email.',
                ]);
            }

            if ($locked->isExpired()) {
                throw ValidationException::withMessages([
                    'request' => 'Expired requests cannot send email.',
                ]);
            }

            $delivery = $this->queueEmail->forRequest(
                $locked,
                DocumentRecipientRequestDeliveryPurpose::ManualResend,
                $actor,
            );

            if (! $delivery instanceof DocumentRecipientRequestDelivery) {
                throw ValidationException::withMessages([
                    'request' => 'Email could not be queued for this request.',
                ]);
            }

            return $delivery;
        });
    }
}
