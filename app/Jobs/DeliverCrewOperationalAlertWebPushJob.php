<?php

namespace App\Jobs;

use App\Enums\CrewOperationalAlertPushDeliveryStatus;
use App\Enums\CrewOperationalAlertStatus;
use App\Models\Company;
use App\Models\CrewOperationalAlertPushDelivery;
use App\Models\CrewOperationalAlertRecipient;
use App\Models\User;
use App\Notifications\CrewOperationalAlertWebPushNotification;
use App\Support\CrewOperations\CrewOperationalAlertDeliveryHandoff;
use App\Support\CrewOperations\CrewOperationsSettings;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

class DeliverCrewOperationalAlertWebPushJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 900;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 60, 120];

    public function __construct(public int $deliveryId) {}

    public function uniqueId(): string
    {
        return 'crew-operational-alert-web-push-'.$this->deliveryId;
    }

    public function handle(): void
    {
        $delivery = DB::transaction(function (): ?CrewOperationalAlertPushDelivery {
            return CrewOperationalAlertPushDelivery::query()
                ->with(['alert', 'user'])
                ->lockForUpdate()
                ->find($this->deliveryId);
        });

        if ($delivery === null) {
            return;
        }

        $handoffKey = CrewOperationalAlertDeliveryHandoff::webPushKey((int) $delivery->id);

        if ($delivery->status === CrewOperationalAlertPushDeliveryStatus::Sent) {
            return;
        }

        if (CrewOperationalAlertDeliveryHandoff::wasHandedOff($handoffKey)) {
            CrewOperationalAlertDeliveryHandoff::persistLedger(
                fn () => $this->persistSent($delivery),
                [
                    'company_id' => $delivery->company_id,
                    'user_id' => $delivery->user_id,
                    'delivery_id' => $delivery->id,
                    'failure_category' => 'web_push_ledger_persist',
                ],
            );

            return;
        }

        if ($delivery->status !== CrewOperationalAlertPushDeliveryStatus::Queued) {
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

        if ($user->pushSubscriptions()->doesntExist()) {
            $this->markFailed($delivery, 'subscriptions_unavailable');

            return;
        }

        try {
            $recipientId = CrewOperationalAlertRecipient::query()
                ->where('company_id', $company->id)
                ->where('crew_operational_alert_id', $alert->id)
                ->where('user_id', $user->id)
                ->value('id');

            if ($recipientId === null) {
                $this->markFailed($delivery, 'recipient_unavailable');

                return;
            }

            Notification::send($user, new CrewOperationalAlertWebPushNotification(
                (int) $company->id,
                (int) $recipientId,
                (int) $delivery->id,
            ));
        } catch (Throwable $exception) {
            Log::warning('Crew operational alert web push delivery failed', [
                'company_id' => $delivery->company_id,
                'user_id' => $delivery->user_id,
                'delivery_id' => $delivery->id,
                'attempt' => $this->attempts(),
                'exception_class' => $exception::class,
                'failure_category' => 'web_push_transport',
            ]);

            throw $exception;
        }

        CrewOperationalAlertDeliveryHandoff::remember($handoffKey);
        CrewOperationalAlertDeliveryHandoff::persistLedger(
            fn () => $this->persistSent($delivery),
            [
                'company_id' => $delivery->company_id,
                'user_id' => $delivery->user_id,
                'delivery_id' => $delivery->id,
                'failure_category' => 'web_push_ledger_persist',
            ],
        );
    }

    public function failed(Throwable $exception): void
    {
        $delivery = CrewOperationalAlertPushDelivery::query()->find($this->deliveryId);

        if ($delivery !== null
            && $delivery->status === CrewOperationalAlertPushDeliveryStatus::Queued
            && ! CrewOperationalAlertDeliveryHandoff::wasHandedOff(
                CrewOperationalAlertDeliveryHandoff::webPushKey((int) $delivery->id),
            )
        ) {
            $this->markFailed($delivery, 'web_push_exhausted');
        }

        Log::warning('Crew operational alert web push delivery exhausted retries', [
            'delivery_id' => $this->deliveryId,
            'attempt' => $this->attempts(),
            'exception_class' => $exception::class,
            'failure_category' => 'web_push_exhausted',
        ]);
    }

    private function persistSent(CrewOperationalAlertPushDelivery $delivery): void
    {
        $delivery->refresh();

        if ($delivery->status === CrewOperationalAlertPushDeliveryStatus::Sent) {
            return;
        }

        $delivery->update([
            'status' => CrewOperationalAlertPushDeliveryStatus::Sent,
            'sent_at' => now(),
            'failed_at' => null,
            'failure_category' => null,
        ]);
    }

    private function markFailed(CrewOperationalAlertPushDelivery $delivery, string $category): void
    {
        if (CrewOperationalAlertDeliveryHandoff::wasHandedOff(
            CrewOperationalAlertDeliveryHandoff::webPushKey((int) $delivery->id),
        )) {
            return;
        }

        $delivery->update([
            'status' => CrewOperationalAlertPushDeliveryStatus::Failed,
            'failed_at' => now(),
            'failure_category' => $category,
        ]);
    }
}
