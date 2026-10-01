<?php

use App\Enums\DocumentExpiryNotificationRecipientKind;
use App\Support\EmployeeDocuments\DocumentExpiryNotification\DocumentExpiryRecipientDeliveryKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Expand historical cohort-hash delivery_key rows into per-recipient identity
 * rows using each rule's currently configured recipients.
 *
 * Cohort hashes cannot be reversed. Expanding to the rule's current recipients
 * preserves "already notified" for people still on the rule without marking
 * future additions as delivered. Legacy wildcard rows are left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_document_expiry_alerts')
            || ! Schema::hasColumn('employee_document_expiry_alerts', 'delivery_key')
        ) {
            return;
        }

        $alerts = DB::table('employee_document_expiry_alerts')
            ->where('delivery_key', '!=', 'legacy')
            ->where('delivery_key', '!=', '')
            ->orderBy('id')
            ->get([
                'id',
                'company_id',
                'notification_rule_id',
                'employee_document_id',
                'expiry_date_at_alert_time',
                'delivery_key',
                'alerted_at',
                'created_at',
                'updated_at',
            ]);

        if ($alerts->isEmpty()) {
            return;
        }

        $ruleIds = $alerts
            ->pluck('notification_rule_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $recipientKeysByRule = $this->recipientDeliveryKeysByRule($ruleIds);

        $groups = $alerts->groupBy(function (object $alert): string {
            return implode('|', [
                (string) ($alert->notification_rule_id ?? 'null'),
                (string) $alert->employee_document_id,
                (string) $alert->expiry_date_at_alert_time,
            ]);
        });

        foreach ($groups as $group) {
            /** @var Collection<int, object> $group */
            $sample = $group->first();
            $ruleId = $sample->notification_rule_id !== null
                ? (int) $sample->notification_rule_id
                : null;

            $existingKeys = $group
                ->pluck('delivery_key')
                ->map(fn ($key): string => (string) $key)
                ->unique()
                ->values()
                ->all();

            $recipientKeys = $ruleId !== null
                ? ($recipientKeysByRule[$ruleId] ?? [])
                : [];

            // Already converted (or only recipient-identity rows remain).
            if ($recipientKeys !== [] && array_diff($existingKeys, $recipientKeys) === []) {
                continue;
            }

            if ($recipientKeys === []) {
                // No recipients to expand into — collapse to legacy wildcard.
                foreach ($group as $alert) {
                    if ((string) $alert->delivery_key === 'legacy') {
                        continue;
                    }

                    $legacyExists = DB::table('employee_document_expiry_alerts')
                        ->where('notification_rule_id', $alert->notification_rule_id)
                        ->where('employee_document_id', $alert->employee_document_id)
                        ->where('expiry_date_at_alert_time', $alert->expiry_date_at_alert_time)
                        ->where('delivery_key', 'legacy')
                        ->exists();

                    if ($legacyExists) {
                        DB::table('employee_document_expiry_alerts')->where('id', $alert->id)->delete();
                    } else {
                        DB::table('employee_document_expiry_alerts')
                            ->where('id', $alert->id)
                            ->update(['delivery_key' => 'legacy']);
                    }
                }

                continue;
            }

            $now = now();
            $alertedAt = $sample->alerted_at ?? $now;
            $createdAt = $sample->created_at ?? $now;
            $updatedAt = $sample->updated_at ?? $now;

            foreach ($recipientKeys as $deliveryKey) {
                $exists = DB::table('employee_document_expiry_alerts')
                    ->where('notification_rule_id', $ruleId)
                    ->where('employee_document_id', $sample->employee_document_id)
                    ->where('expiry_date_at_alert_time', $sample->expiry_date_at_alert_time)
                    ->where('delivery_key', $deliveryKey)
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('employee_document_expiry_alerts')->insert([
                    'company_id' => $sample->company_id,
                    'notification_rule_id' => $ruleId,
                    'employee_document_id' => $sample->employee_document_id,
                    'expiry_date_at_alert_time' => $sample->expiry_date_at_alert_time,
                    'delivery_key' => $deliveryKey,
                    'alerted_at' => $alertedAt,
                    'created_at' => $createdAt,
                    'updated_at' => $updatedAt,
                ]);
            }

            // Remove historical cohort-hash rows (and any other non-recipient keys).
            $idsToDelete = $group
                ->filter(fn (object $alert): bool => ! in_array((string) $alert->delivery_key, $recipientKeys, true))
                ->pluck('id')
                ->all();

            if ($idsToDelete !== []) {
                DB::table('employee_document_expiry_alerts')->whereIn('id', $idsToDelete)->delete();
            }
        }
    }

    public function down(): void
    {
        // Irreversible data conversion — cohort hashes cannot be restored.
    }

    /**
     * @param  list<int>  $ruleIds
     * @return array<int, list<string>>
     */
    private function recipientDeliveryKeysByRule(array $ruleIds): array
    {
        if ($ruleIds === []) {
            return [];
        }

        $rows = DB::table('document_expiry_notification_rule_recipients')
            ->whereIn('rule_id', $ruleIds)
            ->orderBy('id')
            ->get([
                'rule_id',
                'recipient_kind',
                'user_id',
                'email',
            ]);

        $keysByRule = [];

        foreach ($rows as $row) {
            $ruleId = (int) $row->rule_id;
            $kind = (string) $row->recipient_kind;

            if ($kind === DocumentExpiryNotificationRecipientKind::User->value && $row->user_id !== null) {
                $keysByRule[$ruleId][] = DocumentExpiryRecipientDeliveryKey::forUser((int) $row->user_id);

                continue;
            }

            if ($kind === DocumentExpiryNotificationRecipientKind::Email->value) {
                $email = strtolower(trim((string) $row->email));

                if ($email !== '') {
                    $keysByRule[$ruleId][] = DocumentExpiryRecipientDeliveryKey::forEmail($email);
                }
            }
        }

        return collect($keysByRule)
            ->map(fn (array $keys): array => array_values(array_unique($keys)))
            ->all();
    }
};
