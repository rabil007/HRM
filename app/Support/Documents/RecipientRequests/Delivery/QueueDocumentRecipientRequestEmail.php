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
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class QueueDocumentRecipientRequestEmail
{
    public const TEMPLATE_SLUG = 'document_recipient_action_request';

    public const REMINDER_TEMPLATE_SLUG = 'document_recipient_action_reminder';

    public function __construct(
        private ResolveDocumentRecipientRequestEmailDestination $resolveDestination = new ResolveDocumentRecipientRequestEmailDestination,
    ) {}

    public function forRequest(
        DocumentRecipientRequest $request,
        DocumentRecipientRequestDeliveryPurpose $purpose = DocumentRecipientRequestDeliveryPurpose::Initial,
        ?User $actor = null,
    ): ?DocumentRecipientRequestDelivery {
        return $this->queue(
            $request,
            $purpose,
            $actor,
            automationKey: null,
            scheduledFor: null,
        );
    }

    public function forReminder(
        DocumentRecipientRequest $request,
        string $automationKey,
        CarbonInterface $scheduledFor,
        ?User $actor = null,
    ): ?DocumentRecipientRequestDelivery {
        return $this->queue(
            $request,
            DocumentRecipientRequestDeliveryPurpose::Reminder,
            $actor,
            automationKey: $automationKey,
            scheduledFor: $scheduledFor,
        );
    }

    private function queue(
        DocumentRecipientRequest $request,
        DocumentRecipientRequestDeliveryPurpose $purpose,
        ?User $actor,
        ?string $automationKey,
        ?CarbonInterface $scheduledFor,
    ): ?DocumentRecipientRequestDelivery {
        $request->refresh();

        if ($request->status !== DocumentRecipientRequestStatus::AwaitingAction) {
            return null;
        }

        if ($request->isExpired()) {
            return null;
        }

        if ($purpose === DocumentRecipientRequestDeliveryPurpose::Reminder) {
            if ($automationKey === null || $automationKey === '') {
                return null;
            }

            $exists = DocumentRecipientRequestDelivery::query()
                ->where('document_recipient_request_id', $request->id)
                ->where('channel', DocumentRecipientRequestDeliveryChannel::Email)
                ->where('automation_key', $automationKey)
                ->exists();

            if ($exists) {
                return null;
            }
        }

        $companyId = (int) $request->company_id;
        $destination = $this->resolveDestination->forRequest($request);
        $templateSlug = $this->templateSlugForPurpose($purpose);
        $template = $this->resolveTemplate($templateSlug);

        if ($template === null) {
            return $this->createSuppressed(
                $request,
                $purpose,
                $actor,
                destination: $destination['email'],
                failureCategory: 'email_template_missing',
                templateSlug: $templateSlug,
                automationKey: $automationKey,
                scheduledFor: $scheduledFor,
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
                automationKey: $automationKey,
                scheduledFor: $scheduledFor,
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
                automationKey: $automationKey,
                scheduledFor: $scheduledFor,
            );
        }

        $rawAccessToken = null;
        $accessTokenHash = null;

        if ($request->recipient_type === DocumentRecipientType::SubjectEmployee) {
            $rawAccessToken = DocumentRecipientRequestToken::generate();
            $accessTokenHash = DocumentRecipientRequestToken::hash($rawAccessToken);
        }

        try {
            $sequence = $this->nextSequence((int) $request->id);

            $delivery = DocumentRecipientRequestDelivery::query()->create([
                'company_id' => $companyId,
                'document_recipient_request_id' => $request->id,
                'channel' => DocumentRecipientRequestDeliveryChannel::Email,
                'purpose' => $purpose,
                'automation_key' => $automationKey,
                'scheduled_for' => $scheduledFor,
                'delivery_sequence' => $sequence,
                'destination_snapshot' => $destination['email'],
                'template_slug' => $template->slug,
                'subject_snapshot' => null,
                'access_token_hash' => $accessTokenHash,
                'status' => DocumentRecipientRequestDeliveryStatus::Queued,
                'requested_by' => $actor?->id ?? $request->requested_by,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        activity()
            ->causedBy($actor)
            ->performedOn($request)
            ->tap(fn ($activity) => $activity->company_id = $companyId)
            ->withProperties([
                'action' => match ($purpose) {
                    DocumentRecipientRequestDeliveryPurpose::ManualResend => 'recipient_email_resent',
                    default => 'recipient_email_queued',
                },
                'document_recipient_request_id' => $request->id,
                'delivery_id' => $delivery->id,
                'channel' => DocumentRecipientRequestDeliveryChannel::Email->value,
                'purpose' => $purpose->value,
                'status' => DocumentRecipientRequestDeliveryStatus::Queued->value,
                'document_signing_flow_id' => $request->document_signing_flow_id,
                'signing_step_sequence' => $request->signing_step_sequence,
                'recipient_role' => $request->recipient_role?->value,
                'automation_key' => $automationKey,
            ])
            ->log(match ($purpose) {
                DocumentRecipientRequestDeliveryPurpose::ManualResend => 'Recipient request email resent',
                DocumentRecipientRequestDeliveryPurpose::Reminder => 'Recipient request reminder email queued',
                default => 'Recipient request email queued',
            });

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

    private function templateSlugForPurpose(DocumentRecipientRequestDeliveryPurpose $purpose): string
    {
        return match ($purpose) {
            DocumentRecipientRequestDeliveryPurpose::Reminder => self::REMINDER_TEMPLATE_SLUG,
            default => self::TEMPLATE_SLUG,
        };
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

    private function resolveTemplate(string $slug): ?EmailTemplate
    {
        return EmailTemplate::query()
            ->where('slug', $slug)
            ->first();
    }

    private function createSuppressed(
        DocumentRecipientRequest $request,
        DocumentRecipientRequestDeliveryPurpose $purpose,
        ?User $actor,
        ?string $destination,
        string $failureCategory,
        ?string $templateSlug = null,
        ?string $automationKey = null,
        ?CarbonInterface $scheduledFor = null,
    ): ?DocumentRecipientRequestDelivery {
        try {
            $sequence = $this->nextSequence((int) $request->id);

            $delivery = DocumentRecipientRequestDelivery::query()->create([
                'company_id' => $request->company_id,
                'document_recipient_request_id' => $request->id,
                'channel' => DocumentRecipientRequestDeliveryChannel::Email,
                'purpose' => $purpose,
                'automation_key' => $automationKey,
                'scheduled_for' => $scheduledFor,
                'delivery_sequence' => $sequence,
                'destination_snapshot' => $destination,
                'template_slug' => $templateSlug ?? $this->templateSlugForPurpose($purpose),
                'status' => DocumentRecipientRequestDeliveryStatus::Suppressed,
                'failed_at' => now(),
                'failure_category' => $failureCategory,
                'requested_by' => $actor?->id ?? $request->requested_by,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }

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
                'automation_key' => $automationKey,
            ])
            ->log('Recipient request email suppressed');

        return $delivery;
    }
}
