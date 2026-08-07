<?php

namespace App\Jobs;

use App\Enums\CrewOperationalAlertEmailDeliveryStatus;
use App\Enums\CrewOperationalAlertStatus;
use App\Mail\CrewOperationalAlertEmailMail;
use App\Models\Company;
use App\Models\CrewOperationalAlertEmailDelivery;
use App\Models\CrewOperationalAlertRecipient;
use App\Models\User;
use App\Services\Settings\MailSettingsService;
use App\Support\CrewOperations\CrewOperationsSettings;
use App\Support\CrewOperations\QueueCrewOperationalAlertEmails;
use App\Support\CrewOperations\ResolveCrewOperationalAlertUrl;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
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

    public function __construct(public int $deliveryId) {}

    public function uniqueId(): string
    {
        return 'crew-operational-alert-email-'.$this->deliveryId;
    }

    public function handle(
        MailSettingsService $mailSettings,
        ResolveCrewOperationalAlertUrl $resolveUrl,
    ): void {
        $delivery = CrewOperationalAlertEmailDelivery::query()
            ->with(['alert', 'user'])
            ->find($this->deliveryId);

        if ($delivery === null || $delivery->status !== CrewOperationalAlertEmailDeliveryStatus::Queued) {
            return;
        }

        $company = Company::query()
            ->whereKey($delivery->company_id)
            ->where('status', 'active')
            ->first();

        if ($company === null) {
            $this->markFailed($delivery, 'company_unavailable');

            return;
        }

        if (! CrewOperationsSettings::notificationsEnabled((int) $company->id)) {
            $this->markFailed($delivery, 'notifications_disabled');

            return;
        }

        $selected = CrewOperationsSettings::notificationSettings((int) $company->id)['notification_recipient_user_ids'];

        if (! in_array((int) $delivery->user_id, $selected, true)) {
            $this->markFailed($delivery, 'recipient_not_selected');

            return;
        }

        $user = User::query()
            ->whereKey($delivery->user_id)
            ->where(function ($query): void {
                $query->whereNull('status')
                    ->orWhere('status', 'active');
            })
            ->first();

        if ($user === null) {
            $this->markFailed($delivery, 'user_unavailable');

            return;
        }

        $hasActiveMembership = $user->companies()
            ->whereKey($company->id)
            ->wherePivot('status', 'active')
            ->exists();

        if (! $hasActiveMembership) {
            $this->markFailed($delivery, 'membership_unavailable');

            return;
        }

        $alert = $delivery->alert;

        if ($alert === null
            || (int) $alert->company_id !== (int) $company->id
            || $alert->status !== CrewOperationalAlertStatus::Active
            || (int) $alert->notification_version !== (int) $delivery->notification_version
        ) {
            $this->markFailed($delivery, 'alert_unavailable');

            return;
        }

        $recipientId = CrewOperationalAlertRecipient::query()
            ->where('company_id', $company->id)
            ->where('crew_operational_alert_id', $alert->id)
            ->where('user_id', $user->id)
            ->value('id');

        if ($recipientId === null) {
            $this->markFailed($delivery, 'recipient_unavailable');

            return;
        }

        if (! QueueCrewOperationalAlertEmails::hasUsableEmail($user)) {
            $this->markFailed($delivery, 'email_unavailable');

            return;
        }

        if (! $mailSettings->isConfigured()) {
            $this->markFailed($delivery, 'mail_unavailable');

            return;
        }

        $ctaUrl = $resolveUrl->forUser($user, $alert);

        $delivery->update([
            'attempt_count' => ((int) $delivery->attempt_count) + 1,
            'last_attempt_at' => now(),
        ]);

        try {
            $mailSettings->applyToRuntimeConfig();

            Mail::to($user->email)->send(new CrewOperationalAlertEmailMail(
                organizationName: (string) $company->name,
                severityLabel: $alert->severity->value,
                ctaUrl: $ctaUrl,
            ));

            $delivery->update([
                'status' => CrewOperationalAlertEmailDeliveryStatus::Sent,
                'sent_at' => now(),
                'failed_at' => null,
                'failure_category' => null,
            ]);
        } catch (Throwable $exception) {
            Log::warning('Crew operational alert email delivery failed', [
                'company_id' => $delivery->company_id,
                'user_id' => $delivery->user_id,
                'delivery_id' => $delivery->id,
                'attempt' => $this->attempts(),
                'exception_class' => $exception::class,
                'failure_category' => 'email_transport',
            ]);

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        $delivery = CrewOperationalAlertEmailDelivery::query()->find($this->deliveryId);

        if ($delivery !== null && $delivery->status === CrewOperationalAlertEmailDeliveryStatus::Queued) {
            $this->markFailed($delivery, 'email_transport_exhausted');
        }

        Log::warning('Crew operational alert email delivery exhausted retries', [
            'delivery_id' => $this->deliveryId,
            'attempt' => $this->attempts(),
            'exception_class' => $exception::class,
            'failure_category' => 'email_transport_exhausted',
        ]);
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
