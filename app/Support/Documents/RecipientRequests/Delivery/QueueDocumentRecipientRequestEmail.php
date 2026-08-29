<?php

namespace App\Support\Documents\RecipientRequests\Delivery;

use App\Enums\DocumentRecipientRequestDeliveryChannel;
use App\Enums\DocumentRecipientRequestDeliveryPurpose;
use App\Enums\DocumentRecipientRequestDeliveryStatus;
use App\Enums\DocumentRecipientRequestStatus;
use App\Enums\DocumentRecipientType;
use App\Models\DocumentRecipientRequest;
use App\Models\DocumentRecipientRequestDelivery;
use App\Models\EmailTemplate;
use App\Models\User;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class QueueDocumentRecipientRequestEmail
{
    public const TEMPLATE_SLUG = 'document_recipient_action_request';

    public function __construct(
        private ResolveDocumentRecipientRequestEmailDestination $resolveDestination = new ResolveDocumentRecipientRequestEmailDestination,
    ) {}

    public function forRequest(
        DocumentRecipientRequest $request,
        DocumentRecipientRequestDeliveryPurpose $purpose = DocumentRecipientRequestDeliveryPurpose::Initial,
        ?User $actor = null,
    ): ?DocumentRecipientRequestDelivery {
        $request->refresh();

        if ($request->status !== DocumentRecipientRequestStatus::AwaitingAction) {
            return null;
        }

        if ($request->isExpired()) {
            return null;
        }

        $companyId = (int) $request->company_id;
        $destination = $this->resolveDestination->forRequest($request);
        $template = $this->resolveTemplate();

        if ($template === null) {
            return $this->createSuppressed(
                $request,
                $purpose,
                $actor,
                destination: $destination['email'],
                failureCategory: 'email_template_missing',
            );
        }

        if (! $template->enabled) {
            return $this->createSuppressed(
                $request,
                $purpose,
                $actor,
                destination: $destination['email'],
                failureCategory: 'email_template_disabled',
                templateSlug: $template->slug,
            );
        }

        if ($destination['email'] === null) {
            return $this->createSuppressed(
                $request,
                $purpose,
                $actor,
                destination: null,
                failureCategory: $destination['failure_category'] ?? 'recipient_email_missing',
                templateSlug: $template->slug,
            );
        }

        $rawAccessToken = null;
        $accessTokenHash = null;

        if ($request->recipient_type === DocumentRecipientType::SubjectEmployee) {
            $rawAccessToken = DocumentRecipientRequestToken::generate();
            $accessTokenHash = DocumentRecipientRequestToken::hash($rawAccessToken);
        }

        $sequence = $this->nextSequence((int) $request->id);

        $delivery = DocumentRecipientRequestDelivery::query()->create([
            'company_id' => $companyId,
            'document_recipient_request_id' => $request->id,
            'channel' => DocumentRecipientRequestDeliveryChannel::Email,
            'purpose' => $purpose,
            'delivery_sequence' => $sequence,
            'destination_snapshot' => $destination['email'],
            'template_slug' => $template->slug,
            'subject_snapshot' => null,
            'access_token_hash' => $accessTokenHash,
            'status' => DocumentRecipientRequestDeliveryStatus::Queued,
            'requested_by' => $actor?->id ?? $request->requested_by,
        ]);

        activity()
            ->causedBy($actor)
            ->performedOn($request)
            ->tap(fn ($activity) => $activity->company_id = $companyId)
            ->withProperties([
                'action' => $purpose === DocumentRecipientRequestDeliveryPurpose::ManualResend
                    ? 'recipient_email_resent'
                    : 'recipient_email_queued',
                'document_recipient_request_id' => $request->id,
                'delivery_id' => $delivery->id,
                'channel' => DocumentRecipientRequestDeliveryChannel::Email->value,
                'purpose' => $purpose->value,
                'status' => DocumentRecipientRequestDeliveryStatus::Queued->value,
                'document_signing_flow_id' => $request->document_signing_flow_id,
                'signing_step_sequence' => $request->signing_step_sequence,
                'recipient_role' => $request->recipient_role?->value,
            ])
            ->log($purpose === DocumentRecipientRequestDeliveryPurpose::ManualResend
                ? 'Recipient request email resent'
                : 'Recipient request email queued');

        $deliveryId = (int) $delivery->id;

        DB::afterCommit(function () use ($deliveryId, $rawAccessToken): void {
            try {
                app(DispatchDocumentRecipientRequestEmails::class)->dispatchDelivery(
                    $deliveryId,
                    $rawAccessToken,
                );
            } catch (Throwable $exception) {
                report($exception);
                Log::warning('Document recipient email queue handoff failed after commit', [
                    'delivery_id' => $deliveryId,
                    'exception_class' => $exception::class,
                ]);
            }
        });

        return $delivery;
    }

    private function nextSequence(int $requestId): int
    {
        $max = DocumentRecipientRequestDelivery::query()
            ->where('document_recipient_request_id', $requestId)
            ->where('channel', DocumentRecipientRequestDeliveryChannel::Email)
            ->lockForUpdate()
            ->max('delivery_sequence');

        return ((int) $max) + 1;
    }

    private function resolveTemplate(): ?EmailTemplate
    {
        return EmailTemplate::query()
            ->where('slug', self::TEMPLATE_SLUG)
            ->first();
    }

    private function createSuppressed(
        DocumentRecipientRequest $request,
        DocumentRecipientRequestDeliveryPurpose $purpose,
        ?User $actor,
        ?string $destination,
        string $failureCategory,
        ?string $templateSlug = null,
    ): DocumentRecipientRequestDelivery {
        $sequence = $this->nextSequence((int) $request->id);

        $delivery = DocumentRecipientRequestDelivery::query()->create([
            'company_id' => $request->company_id,
            'document_recipient_request_id' => $request->id,
            'channel' => DocumentRecipientRequestDeliveryChannel::Email,
            'purpose' => $purpose,
            'delivery_sequence' => $sequence,
            'destination_snapshot' => $destination,
            'template_slug' => $templateSlug ?? self::TEMPLATE_SLUG,
            'status' => DocumentRecipientRequestDeliveryStatus::Suppressed,
            'failed_at' => now(),
            'failure_category' => $failureCategory,
            'requested_by' => $actor?->id ?? $request->requested_by,
        ]);

        activity()
            ->causedBy($actor)
            ->performedOn($request)
            ->tap(fn ($activity) => $activity->company_id = (int) $request->company_id)
            ->withProperties([
                'action' => 'recipient_email_suppressed',
                'document_recipient_request_id' => $request->id,
                'delivery_id' => $delivery->id,
                'channel' => DocumentRecipientRequestDeliveryChannel::Email->value,
                'purpose' => $purpose->value,
                'status' => DocumentRecipientRequestDeliveryStatus::Suppressed->value,
                'failure_category' => $failureCategory,
            ])
            ->log('Recipient request email suppressed');

        return $delivery;
    }
}
