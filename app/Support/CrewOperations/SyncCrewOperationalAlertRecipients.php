<?php

namespace App\Support\CrewOperations;

use App\Enums\CrewOperationalAlertStatus;
use App\Models\CrewOperationalAlert;
use App\Models\CrewOperationalAlertRecipient;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Ensures recipient rows exist for active alerts and currently selected notification users.
 */
final class SyncCrewOperationalAlertRecipients
{
    /**
     * @param  list<int>|null  $alertIds  Null = all active alerts for the company.
     * @return list<int> Newly created recipient IDs
     */
    public function forCompany(int $companyId, ?array $alertIds = null): array
    {
        $recipientUserIds = CrewOperationsSettings::notificationSettings($companyId)['notification_recipient_user_ids'];

        if ($recipientUserIds === [] || ! CrewOperationsSettings::notificationsEnabled($companyId)) {
            return [];
        }

        $alertsQuery = CrewOperationalAlert::query()
            ->where('company_id', $companyId)
            ->where('status', CrewOperationalAlertStatus::Active);

        if ($alertIds !== null) {
            $alertsQuery->whereIn('id', $alertIds === [] ? [0] : $alertIds);
        }

        $alerts = $alertsQuery->get(['id', 'company_id']);
        $createdRecipientIds = [];

        foreach ($alerts as $alert) {
            foreach ($recipientUserIds as $userId) {
                $existing = CrewOperationalAlertRecipient::query()
                    ->where('crew_operational_alert_id', $alert->id)
                    ->where('user_id', $userId)
                    ->first();

                if ($existing !== null) {
                    continue;
                }

                try {
                    $recipient = CrewOperationalAlertRecipient::query()->create([
                        'company_id' => $companyId,
                        'crew_operational_alert_id' => $alert->id,
                        'user_id' => $userId,
                        'read_at' => null,
                    ]);
                    $createdRecipientIds[] = (int) $recipient->id;
                } catch (UniqueConstraintViolationException) {
                    // Concurrent sync created the same recipient.
                }
            }
        }

        return $createdRecipientIds;
    }

    /**
     * Sync recipients after settings change, then return active alert IDs that gained new recipients.
     *
     * @return list<int>
     */
    public function forSettingsChange(int $companyId): array
    {
        return DB::transaction(function () use ($companyId): array {
            $before = CrewOperationalAlertRecipient::query()
                ->where('company_id', $companyId)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $created = $this->forCompany($companyId);

            return array_values(array_diff($created, $before));
        });
    }
}
