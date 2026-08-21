<?php

namespace App\Support\CrewOperations;

use App\Enums\CrewOperationalAlertEmailDeliveryStatus;
use App\Enums\CrewOperationalAlertStatus;
use App\Models\CrewOperationalAlert;
use App\Models\CrewOperationalAlertEmailDelivery;
use App\Models\CrewOperationalAlertRecipient;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Queues privacy-safe emails for meaningful Crew alert notification versions.
 * Creates durable ledger rows only; authoritative dispatch is performed by DispatchCrewOperationalAlertEmailDigests.
 */
final class QueueCrewOperationalAlertEmails
{
    /**
     * @param  list<int>  $alertIds
     * @return list<int> Queued delivery IDs
     */
    public function forAlerts(int $companyId, array $alertIds): array
    {
        if ($alertIds === [] || ! CrewOperationsSettings::notificationsEnabled($companyId)) {
            return [];
        }

        $selectedUserIds = CrewOperationsSettings::notificationSettings($companyId)['notification_recipient_user_ids'];

        if ($selectedUserIds === []) {
            return [];
        }

        $activeSelectedUserIds = CrewOperationsSettings::activeCompanyUsers($companyId)
            ->whereIn('id', $selectedUserIds)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($activeSelectedUserIds === []) {
            return [];
        }

        $queuedIds = [];

        $alerts = CrewOperationalAlert::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $alertIds)
            ->where('status', CrewOperationalAlertStatus::Active)
            ->get();

        foreach ($alerts as $alert) {
            $recipients = CrewOperationalAlertRecipient::query()
                ->where('company_id', $companyId)
                ->where('crew_operational_alert_id', $alert->id)
                ->whereIn('user_id', $activeSelectedUserIds)
                ->get();

            foreach ($recipients as $recipient) {
                $user = User::query()
                    ->whereKey($recipient->user_id)
                    ->where(function ($query): void {
                        $query->whereNull('status')
                            ->orWhere('status', 'active');
                    })
                    ->first();

                if ($user === null || ! self::hasUsableEmail($user)) {
                    continue;
                }

                $delivery = $this->queueDelivery(
                    $companyId,
                    (int) $alert->id,
                    (int) $recipient->user_id,
                    (int) $alert->notification_version,
                );

                if ($delivery !== null) {
                    $queuedIds[] = (int) $delivery->id;
                }
            }
        }

        return $queuedIds;
    }

    public static function hasUsableEmail(User $user): bool
    {
        $email = trim((string) $user->email);

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function queueDelivery(
        int $companyId,
        int $alertId,
        int $userId,
        int $notificationVersion,
    ): ?CrewOperationalAlertEmailDelivery {
        $existing = CrewOperationalAlertEmailDelivery::query()
            ->where('crew_operational_alert_id', $alertId)
            ->where('user_id', $userId)
            ->where('notification_version', $notificationVersion)
            ->first();

        if ($existing !== null) {
            return null;
        }

        try {
            return CrewOperationalAlertEmailDelivery::query()->create([
                'company_id' => $companyId,
                'crew_operational_alert_id' => $alertId,
                'user_id' => $userId,
                'notification_version' => $notificationVersion,
                'status' => CrewOperationalAlertEmailDeliveryStatus::Queued,
                'queued_at' => now(),
                'dispatched_at' => null,
                'dispatch_claimed_at' => null,
                'attempt_count' => 0,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }
}
