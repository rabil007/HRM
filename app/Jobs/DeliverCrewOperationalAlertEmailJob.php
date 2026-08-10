<?php

namespace App\Jobs;

use App\Enums\CrewOperationalAlertEmailDeliveryStatus;
use App\Enums\CrewOperationalAlertStatus;
use App\Mail\CrewOperationalAlertEmailMail;
use App\Models\Company;
use App\Models\CrewOperationalAlertEmailDelivery;
use App\Models\CrewOperationalAlertRecipient;
use App\Models\EmailTemplate;
use App\Models\User;
use App\Services\Settings\MailSettingsService;
use App\Support\CrewOperations\CrewOperationalAlertDigestPresenter;
use App\Support\CrewOperations\CrewOperationsSettings;
use App\Support\CrewOperations\QueueCrewOperationalAlertEmails;
use App\Support\CrewOperations\ResolveCrewOperationalAlertUrl;
use App\Support\Settings\CompanyTimezone;
use Database\Seeders\EmailTemplatesSeeder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class DeliverCrewOperationalAlertEmailJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 300;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 60, 120];

    /**
     * @var list<int>
     */
    public array $deliveryIds;

    /**
     * @param  int|list<int>  $deliveryId
     */
    public function __construct(
        int|array $deliveryId,
        public ?int $companyId = null,
        public ?int $userId = null,
    ) {
        if (is_int($deliveryId)) {
            $this->deliveryIds = [$deliveryId];
        } else {
            $this->deliveryIds = array_values(array_map('intval', $deliveryId));
        }
    }

    public function uniqueId(): string
    {
        $sorted = $this->deliveryIds;
        sort($sorted);

        return 'crew-operational-alert-email-'.implode('-', $sorted);
    }

    public function handle(
        MailSettingsService $mailSettings,
        ResolveCrewOperationalAlertUrl $resolveUrl,
    ): void {
        if ($this->deliveryIds === []) {
            return;
        }

        $deliveries = CrewOperationalAlertEmailDelivery::query()
            ->with(['alert', 'user'])
            ->whereIn('id', $this->deliveryIds)
            ->get();

        $queuedDeliveries = $deliveries->filter(
            fn (CrewOperationalAlertEmailDelivery $d): bool => $d->status === CrewOperationalAlertEmailDeliveryStatus::Queued
        );

        if ($queuedDeliveries->isEmpty()) {
            return;
        }

        $first = $queuedDeliveries->first();
        $companyId = (int) $first->company_id;
        $userId = (int) $first->user_id;

        $company = Company::query()
            ->whereKey($companyId)
            ->where('status', 'active')
            ->first();

        if ($company === null) {
            $this->markDeliveriesFailed($queuedDeliveries, 'company_unavailable');

            return;
        }

        if (! CrewOperationsSettings::notificationsEnabled($companyId)) {
            $this->markDeliveriesFailed($queuedDeliveries, 'notifications_disabled');

            return;
        }

        $selected = CrewOperationsSettings::notificationSettings($companyId)['notification_recipient_user_ids'];

        if (! in_array($userId, $selected, true)) {
            $this->markDeliveriesFailed($queuedDeliveries, 'recipient_not_selected');

            return;
        }

        $user = User::query()
            ->whereKey($userId)
            ->where(function ($query): void {
                $query->whereNull('status')
                    ->orWhere('status', 'active');
            })
            ->first();

        if ($user === null) {
            $this->markDeliveriesFailed($queuedDeliveries, 'user_unavailable');

            return;
        }

        $hasActiveMembership = $user->companies()
            ->whereKey($companyId)
            ->wherePivot('status', 'active')
            ->exists();

        if (! $hasActiveMembership) {
            $this->markDeliveriesFailed($queuedDeliveries, 'membership_unavailable');

            return;
        }

        if (! QueueCrewOperationalAlertEmails::hasUsableEmail($user)) {
            $this->markDeliveriesFailed($queuedDeliveries, 'email_unavailable');

            return;
        }

        $template = EmailTemplate::query()
            ->where('slug', 'crew_operational_alert_digest')
            ->first()
            ?? EmailTemplatesSeeder::seedCrewOperationalAlertDigestTemplate();

        if (! $template->enabled) {
            $this->markDeliveriesFailed($queuedDeliveries, 'notifications_disabled');

            return;
        }

        $validDeliveries = collect();
        foreach ($queuedDeliveries as $delivery) {
            $alert = $delivery->alert;
            if ($alert === null
                || (int) $alert->company_id !== (int) $companyId
                || $alert->status !== CrewOperationalAlertStatus::Active
                || (int) $alert->notification_version !== (int) $delivery->notification_version
            ) {
                $this->markFailed($delivery, 'alert_unavailable');

                continue;
            }

            $recipientId = CrewOperationalAlertRecipient::query()
                ->where('company_id', $companyId)
                ->where('crew_operational_alert_id', $alert->id)
                ->where('user_id', $userId)
                ->value('id');

            if ($recipientId === null) {
                $this->markFailed($delivery, 'recipient_unavailable');

                continue;
            }

            $validDeliveries->push($delivery);
        }

        if ($validDeliveries->isEmpty()) {
            return;
        }

        if (! $mailSettings->isConfigured()) {
            $this->markDeliveriesFailed($validDeliveries, 'mail_unavailable');

            return;
        }

        foreach ($validDeliveries as $delivery) {
            $delivery->update([
                'attempt_count' => ((int) $delivery->attempt_count) + 1,
                'last_attempt_at' => now(),
            ]);
        }

        $digest = app(CrewOperationalAlertDigestPresenter::class)->forUser(
            $user,
            $company,
            $validDeliveries,
        );

        $timezone = CompanyTimezone::forCompany($company);
        $generatedAt = now($timezone)->format('d M Y H:i');
        $primaryUrl = $resolveUrl->forUser($user, $validDeliveries->first()->alert);

        $placeholders = [
            '{{company_name}}' => e($company->name),
            '{{alert_count}}' => (string) $digest['alert_count'],
            '{{generated_at}}' => $generatedAt,
            '{{highest_severity}}' => strtoupper($digest['highest_severity']),
            '{{alerts_table}}' => $digest['alerts_table'],
            '{{crew_operations_url}}' => $primaryUrl ?? '',
        ];

        $subjectLine = strtr($template->subject, $placeholders);
        $bodyHtml = strtr($template->body_html, $placeholders);

        try {
            $mailSettings->applyToRuntimeConfig();

            Mail::to($user->email)->send(new CrewOperationalAlertEmailMail(
                organizationName: (string) $company->name,
                severityLabel: $digest['highest_severity'],
                ctaUrl: $primaryUrl,
                includeCompanyFooter: $template->include_company_footer,
                subjectLine: $subjectLine,
                bodyHtml: $bodyHtml,
            ));

            foreach ($validDeliveries as $delivery) {
                $delivery->update([
                    'status' => CrewOperationalAlertEmailDeliveryStatus::Sent,
                    'sent_at' => now(),
                    'failed_at' => null,
                    'failure_category' => null,
                ]);
            }
        } catch (Throwable $exception) {
            Log::warning('Crew operational alert email delivery failed', [
                'company_id' => $companyId,
                'user_id' => $userId,
                'delivery_ids' => $validDeliveries->pluck('id')->all(),
                'attempt' => $this->attempts(),
                'exception_class' => $exception::class,
                'failure_category' => 'email_transport',
            ]);

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        $deliveries = CrewOperationalAlertEmailDelivery::query()
            ->whereIn('id', $this->deliveryIds)
            ->where('status', CrewOperationalAlertEmailDeliveryStatus::Queued)
            ->get();

        foreach ($deliveries as $delivery) {
            $this->markFailed($delivery, 'email_transport_exhausted');
        }

        Log::warning('Crew operational alert email delivery exhausted retries', [
            'delivery_ids' => $this->deliveryIds,
            'attempt' => $this->attempts(),
            'exception_class' => $exception::class,
            'failure_category' => 'email_transport_exhausted',
        ]);
    }

    /**
     * @param  Collection<int, CrewOperationalAlertEmailDelivery>  $deliveries
     */
    private function markDeliveriesFailed(Collection $deliveries, string $category): void
    {
        foreach ($deliveries as $delivery) {
            $this->markFailed($delivery, $category);
        }
    }

    private function markFailed(CrewOperationalAlertEmailDelivery $delivery, string $category): void
    {
        $delivery->update([
            'status' => CrewOperationalAlertEmailDeliveryStatus::Failed,
            'failed_at' => now(),
            'failure_category' => $category,
        ]);
    }
}
