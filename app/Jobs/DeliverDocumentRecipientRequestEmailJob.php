<?php

namespace App\Jobs;

use App\Enums\DocumentRecipientRequestDeliveryStatus;
use App\Enums\DocumentRecipientRequestStatus;
use App\Enums\DocumentRecipientType;
use App\Mail\DocumentRecipientRequestActionMail;
use App\Models\Company;
use App\Models\DocumentRecipientRequest;
use App\Models\DocumentRecipientRequestDelivery;
use App\Models\EmailTemplate;
use App\Services\Settings\MailSettingsService;
use App\Support\Documents\RecipientRequests\Delivery\DocumentRecipientRequestDeliveryHandoff;
use App\Support\Documents\RecipientRequests\Delivery\QueueDocumentRecipientRequestEmail;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestLinkService;
use App\Support\Documents\Signing\DocumentSigningInternalSignerEligibility;
use App\Support\Email\EmailTemplateBodyRenderer;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class DeliverDocumentRecipientRequestEmailJob implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 900;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 60, 120];

    public function __construct(
        public int $deliveryId,
        public int $companyId,
        public ?string $rawAccessToken = null,
    ) {}

    public function uniqueId(): string
    {
        return 'document-recipient-email:'.$this->deliveryId;
    }

    public function handle(
        MailSettingsService $mailSettings,
        DocumentRecipientRequestLinkService $linkService,
        DocumentSigningInternalSignerEligibility $signerEligibility,
    ): void {
        $delivery = DocumentRecipientRequestDelivery::query()
            ->whereKey($this->deliveryId)
            ->where('company_id', $this->companyId)
            ->first();

        if (! $delivery instanceof DocumentRecipientRequestDelivery) {
            return;
        }

        if ($delivery->status !== DocumentRecipientRequestDeliveryStatus::Queued) {
            return;
        }

        if ($delivery->isRevoked()) {
            return;
        }

        $handoffKey = DocumentRecipientRequestDeliveryHandoff::emailKey($this->deliveryId);

        if (DocumentRecipientRequestDeliveryHandoff::wasHandedOff($handoffKey)) {
            DocumentRecipientRequestDeliveryHandoff::persistLedger(
                fn () => $this->persistSent($delivery),
                [
                    'company_id' => $this->companyId,
                    'delivery_id' => $this->deliveryId,
                    'failure_category' => 'email_ledger_persist',
                ],
            );

            return;
        }

        $request = DocumentRecipientRequest::query()
            ->whereKey($delivery->document_recipient_request_id)
            ->where('company_id', $this->companyId)
            ->with(['employee', 'recipientUser', 'documentInstance.employeeDocument', 'company'])
            ->first();

        if (! $request instanceof DocumentRecipientRequest) {
            $this->suppress($delivery, 'request_no_longer_awaiting');

            return;
        }

        if ($request->status !== DocumentRecipientRequestStatus::AwaitingAction || $request->isExpired()) {
            $this->suppress($delivery, 'request_no_longer_awaiting');

            return;
        }

        if ($request->recipient_type === DocumentRecipientType::CompanyUser) {
            $user = $request->recipientUser;

            if ($user === null || ! $signerEligibility->isActionable($user, $this->companyId)) {
                $this->suppress($delivery, 'recipient_no_longer_actionable');

                return;
            }
        }

        if (! $mailSettings->isConfigured()) {
            $this->markFailed($delivery, 'smtp_not_configured');

            return;
        }

        $template = EmailTemplate::query()
            ->where('slug', $delivery->template_slug ?? QueueDocumentRecipientRequestEmail::TEMPLATE_SLUG)
            ->first();

        if ($template === null || ! $template->enabled) {
            $this->suppress($delivery, 'email_template_disabled');

            return;
        }

        $actionUrl = $this->resolveActionUrl($request, $linkService);

        if ($actionUrl === null) {
            $this->suppress($delivery, 'request_no_longer_awaiting');

            return;
        }

        $placeholders = $this->placeholders($request, $actionUrl);
        $subject = strtr($template->subject, $placeholders);
        $bodyHtml = EmailTemplateBodyRenderer::toHtml(strtr($template->body_html, $placeholders));
        $company = $request->company ?? Company::query()->find($this->companyId);

        $mailSettings->applyToRuntimeConfig();

        $delivery->forceFill([
            'attempt_count' => ((int) $delivery->attempt_count) + 1,
            'last_attempt_at' => now(),
            'subject_snapshot' => $subject,
        ])->save();

        try {
            Mail::to((string) $delivery->destination_snapshot)->send(
                new DocumentRecipientRequestActionMail(
                    organizationName: (string) ($company?->name ?? ''),
                    subjectLine: $subject,
                    bodyHtml: $bodyHtml,
                    includeCompanyFooter: (bool) ($template->include_company_footer ?? true),
                ),
            );
        } catch (Throwable $exception) {
            Log::warning('Document recipient email transport failed', [
                'company_id' => $this->companyId,
                'document_recipient_request_id' => $request->id,
                'delivery_id' => $this->deliveryId,
                'exception_class' => $exception::class,
            ]);

            throw $exception;
        }

        DocumentRecipientRequestDeliveryHandoff::remember($handoffKey);
        DocumentRecipientRequestDeliveryHandoff::persistLedger(
            function () use ($delivery, $subject): void {
                $delivery->refresh();
                $this->persistSent($delivery, $subject);
            },
            [
                'company_id' => $this->companyId,
                'delivery_id' => $this->deliveryId,
                'failure_category' => 'email_ledger_persist',
            ],
        );

        activity()
            ->performedOn($request)
            ->tap(fn ($activity) => $activity->company_id = $this->companyId)
            ->withProperties([
                'action' => 'recipient_email_sent',
                'document_recipient_request_id' => $request->id,
                'delivery_id' => $delivery->id,
                'channel' => $delivery->channel->value,
                'purpose' => $delivery->purpose->value,
                'status' => DocumentRecipientRequestDeliveryStatus::Sent->value,
            ])
            ->log('Recipient request email sent');
    }

    public function failed(?Throwable $exception): void
    {
        $handoffKey = DocumentRecipientRequestDeliveryHandoff::emailKey($this->deliveryId);

        if (DocumentRecipientRequestDeliveryHandoff::wasHandedOff($handoffKey)) {
            return;
        }

        $delivery = DocumentRecipientRequestDelivery::query()
            ->whereKey($this->deliveryId)
            ->where('company_id', $this->companyId)
            ->first();

        if (! $delivery instanceof DocumentRecipientRequestDelivery) {
            return;
        }

        if ($delivery->status !== DocumentRecipientRequestDeliveryStatus::Queued) {
            return;
        }

        $this->markFailed($delivery, 'email_transport_exhausted');

        Log::warning('Document recipient email exhausted retries', [
            'company_id' => $this->companyId,
            'delivery_id' => $this->deliveryId,
            'document_recipient_request_id' => $delivery->document_recipient_request_id,
            'exception_class' => $exception instanceof Throwable ? $exception::class : null,
        ]);
    }

    private function resolveActionUrl(
        DocumentRecipientRequest $request,
        DocumentRecipientRequestLinkService $linkService,
    ): ?string {
        if ($request->recipient_type === DocumentRecipientType::SubjectEmployee) {
            if ($this->rawAccessToken === null || $this->rawAccessToken === '') {
                return null;
            }

            return $linkService->publicUrl($this->rawAccessToken);
        }

        return route('organization.documents.recipient-requests.respond', [
            'recipientRequest' => $request->id,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function placeholders(DocumentRecipientRequest $request, string $actionUrl): array
    {
        $request->loadMissing([
            'employee',
            'company',
            'documentInstance.employeeDocument',
        ]);

        $document = $request->documentInstance?->employeeDocument;
        $stepLabel = filled($request->signing_step_label_snapshot)
            ? (string) $request->signing_step_label_snapshot
            : ($request->recipient_role?->label() ?? '');

        return [
            '{{company_name}}' => (string) ($request->company?->name ?? ''),
            '{{recipient_name}}' => (string) ($request->recipient_name_snapshot ?? ''),
            '{{employee_name}}' => (string) ($request->employee?->name ?? ''),
            '{{employee_no}}' => (string) ($request->employee?->employee_no ?? ''),
            '{{document_title}}' => (string) ($document?->title ?? $request->documentInstance?->title_snapshot ?? ''),
            '{{document_type}}' => (string) ($document?->document_type ?? $document?->type ?? ''),
            '{{action_label}}' => (string) ($request->action?->label() ?? 'Review document'),
            '{{action_url}}' => $actionUrl,
            '{{expires_at}}' => $request->expires_at?->timezone(config('app.timezone'))->format('d M Y, H:i') ?? '',
            '{{step_label}}' => $stepLabel,
        ];
    }

    private function persistSent(DocumentRecipientRequestDelivery $delivery, ?string $subject = null): void
    {
        $delivery->update([
            'status' => DocumentRecipientRequestDeliveryStatus::Sent,
            'sent_at' => now(),
            'failed_at' => null,
            'failure_category' => null,
            'subject_snapshot' => $subject ?? $delivery->subject_snapshot,
        ]);
    }

    private function suppress(DocumentRecipientRequestDelivery $delivery, string $category): void
    {
        $delivery->update([
            'status' => DocumentRecipientRequestDeliveryStatus::Suppressed,
            'failed_at' => now(),
            'failure_category' => $category,
        ]);

        activity()
            ->performedOn($delivery->recipientRequest)
            ->tap(fn ($activity) => $activity->company_id = (int) $delivery->company_id)
            ->withProperties([
                'action' => 'recipient_email_suppressed',
                'document_recipient_request_id' => $delivery->document_recipient_request_id,
                'delivery_id' => $delivery->id,
                'failure_category' => $category,
                'status' => DocumentRecipientRequestDeliveryStatus::Suppressed->value,
            ])
            ->log('Recipient request email suppressed');
    }

    private function markFailed(DocumentRecipientRequestDelivery $delivery, string $category): void
    {
        $delivery->update([
            'status' => DocumentRecipientRequestDeliveryStatus::Failed,
            'failed_at' => now(),
            'failure_category' => $category,
        ]);

        activity()
            ->performedOn($delivery->recipientRequest)
            ->tap(fn ($activity) => $activity->company_id = (int) $delivery->company_id)
            ->withProperties([
                'action' => 'recipient_email_failed',
                'document_recipient_request_id' => $delivery->document_recipient_request_id,
                'delivery_id' => $delivery->id,
                'failure_category' => $category,
                'status' => DocumentRecipientRequestDeliveryStatus::Failed->value,
            ])
            ->log('Recipient request email failed');
    }
}
