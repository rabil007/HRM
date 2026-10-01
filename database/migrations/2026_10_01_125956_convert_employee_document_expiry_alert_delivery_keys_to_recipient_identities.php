<?php

use App\Support\EmployeeDocuments\DocumentExpiryNotification\DocumentExpiryRecipientDeliveryKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Convert historical cohort-hash delivery_key rows into per-recipient identity
 * rows using expiry_alert_sent activity-log evidence (notification_rule_id,
 * document_ids, to, cc).
 *
 * Cohort hashes cannot be reversed and current rule recipients must not be
 * assumed to have received historical emails. When evidence is missing, keep
 * the legacy wildcard so prior "already notified" behavior is preserved.
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

        $emailToUserId = $this->emailToUserIdMap($ruleIds);
        $evidencedKeysByGroup = $this->evidencedDeliveryKeysByGroup($alerts, $ruleIds, $emailToUserId);

        $groups = $alerts->groupBy(function (object $alert): string {
            return $this->groupKey(
                $alert->notification_rule_id !== null ? (int) $alert->notification_rule_id : null,
                (int) $alert->employee_document_id,
                (string) $alert->expiry_date_at_alert_time,
            );
        });

        foreach ($groups as $groupKey => $group) {
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

            $evidencedKeys = $ruleId !== null
                ? ($evidencedKeysByGroup[$groupKey] ?? [])
                : [];

            // Already converted to the evidenced recipient identities.
            if ($evidencedKeys !== [] && array_diff($existingKeys, $evidencedKeys) === []) {
                continue;
            }

            if ($evidencedKeys === []) {
                $this->collapseGroupToLegacy($group);

                continue;
            }

            $now = now();
            $alertedAt = $sample->alerted_at ?? $now;
            $createdAt = $sample->created_at ?? $now;
            $updatedAt = $sample->updated_at ?? $now;

            foreach ($evidencedKeys as $deliveryKey) {
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

            // Remove historical cohort-hash (and any other non-evidenced) keys only.
            // Never delete evidenced recipient rows or legacy wildcards.
            $idsToDelete = $group
                ->filter(function (object $alert) use ($evidencedKeys): bool {
                    $key = (string) $alert->delivery_key;

                    return $key !== 'legacy' && ! in_array($key, $evidencedKeys, true);
                })
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

    private function groupKey(?int $ruleId, int $documentId, string $expiryDate): string
    {
        return implode('|', [
            (string) ($ruleId ?? 'null'),
            (string) $documentId,
            $expiryDate,
        ]);
    }

    /**
     * @param  Collection<int, object>  $group
     */
    private function collapseGroupToLegacy(Collection $group): void
    {
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
    }

    /**
     * @param  list<int>  $ruleIds
     * @return array<string, int> lowercase email => user id
     */
    private function emailToUserIdMap(array $ruleIds): array
    {
        if ($ruleIds === [] || ! Schema::hasTable('document_expiry_notification_rule_recipients')) {
            return [];
        }

        $map = [];

        $recipientRows = DB::table('document_expiry_notification_rule_recipients as recipients')
            ->leftJoin('users', 'users.id', '=', 'recipients.user_id')
            ->whereIn('recipients.rule_id', $ruleIds)
            ->where('recipients.recipient_kind', 'user')
            ->whereNotNull('recipients.user_id')
            ->get([
                'recipients.user_id',
                'users.email',
            ]);

        foreach ($recipientRows as $row) {
            $email = strtolower(trim((string) ($row->email ?? '')));
            $userId = (int) $row->user_id;

            if ($email !== '' && $userId > 0 && ! isset($map[$email])) {
                $map[$email] = $userId;
            }
        }

        return $map;
    }

    /**
     * @param  Collection<int, object>  $alerts
     * @param  list<int>  $ruleIds
     * @param  array<string, int>  $emailToUserId
     * @return array<string, list<string>> groupKey => delivery keys
     */
    private function evidencedDeliveryKeysByGroup(Collection $alerts, array $ruleIds, array &$emailToUserId): array
    {
        if ($ruleIds === [] || ! Schema::hasTable('activity_log')) {
            return [];
        }

        $activities = DB::table('activity_log')
            ->where('event', 'expiry_alert_sent')
            ->where('log_name', 'documents')
            ->orderBy('id')
            ->get(['id', 'properties', 'created_at']);

        if ($activities->isEmpty()) {
            return [];
        }

        $ruleIdSet = array_fill_keys($ruleIds, true);
        $alertsByRuleDoc = $alerts
            ->filter(fn (object $alert): bool => $alert->notification_rule_id !== null)
            ->groupBy(fn (object $alert): string => (int) $alert->notification_rule_id.'|'.(int) $alert->employee_document_id);

        $keysByGroup = [];
        $unresolvedEmails = [];

        foreach ($activities as $activity) {
            $properties = $this->decodeProperties($activity->properties);

            if ($properties === null) {
                continue;
            }

            $ruleId = isset($properties['notification_rule_id'])
                ? (int) $properties['notification_rule_id']
                : 0;

            if ($ruleId <= 0 || ! isset($ruleIdSet[$ruleId])) {
                continue;
            }

            $documentIds = $properties['document_ids'] ?? null;

            if (! is_array($documentIds) || $documentIds === []) {
                continue;
            }

            $emails = $this->normalizedEmailsFromProperties($properties);

            if ($emails === []) {
                continue;
            }

            $activityDay = $this->dateString($activity->created_at);

            foreach ($documentIds as $documentId) {
                $docId = (int) $documentId;

                if ($docId <= 0) {
                    continue;
                }

                $candidateAlerts = $alertsByRuleDoc->get($ruleId.'|'.$docId, collect());

                if ($candidateAlerts->isEmpty()) {
                    continue;
                }

                $matched = $candidateAlerts->filter(function (object $alert) use ($activityDay): bool {
                    $alertDay = $this->dateString($alert->alerted_at ?? $alert->created_at);

                    return $activityDay !== null && $alertDay !== null && $activityDay === $alertDay;
                });

                // Fall back to the only ledger group for this rule+document when
                // timestamps cannot be aligned (clock skew / missing created_at).
                if ($matched->isEmpty()) {
                    $distinctExpiries = $candidateAlerts
                        ->pluck('expiry_date_at_alert_time')
                        ->map(fn ($value): string => (string) $value)
                        ->unique()
                        ->values();

                    if ($distinctExpiries->count() === 1) {
                        $matched = $candidateAlerts;
                    }
                }

                if ($matched->isEmpty()) {
                    continue;
                }

                foreach ($matched as $alert) {
                    $groupKey = $this->groupKey(
                        $ruleId,
                        $docId,
                        (string) $alert->expiry_date_at_alert_time,
                    );

                    foreach ($emails as $email) {
                        if (isset($emailToUserId[$email])) {
                            $deliveryKey = DocumentExpiryRecipientDeliveryKey::forUser($emailToUserId[$email]);
                        } else {
                            $unresolvedEmails[$email] = true;
                            $deliveryKey = DocumentExpiryRecipientDeliveryKey::forEmail($email);
                        }

                        $keysByGroup[$groupKey][$deliveryKey] = $email;
                    }
                }
            }
        }

        if ($unresolvedEmails !== [] && Schema::hasTable('users')) {
            $users = DB::table('users')
                ->where(function ($query) use ($unresolvedEmails): void {
                    foreach (array_keys($unresolvedEmails) as $email) {
                        $query->orWhereRaw('lower(email) = ?', [$email]);
                    }
                })
                ->get(['id', 'email']);

            foreach ($users as $user) {
                $email = strtolower(trim((string) $user->email));

                if ($email === '' || isset($emailToUserId[$email])) {
                    continue;
                }

                $userId = (int) $user->id;
                $emailToUserId[$email] = $userId;
                $emailKey = DocumentExpiryRecipientDeliveryKey::forEmail($email);
                $userKey = DocumentExpiryRecipientDeliveryKey::forUser($userId);

                foreach ($keysByGroup as $groupKey => $keys) {
                    if (! isset($keys[$emailKey])) {
                        continue;
                    }

                    unset($keysByGroup[$groupKey][$emailKey]);
                    $keysByGroup[$groupKey][$userKey] = $email;
                }
            }
        }

        return collect($keysByGroup)
            ->map(fn (array $keys): array => array_keys($keys))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return list<string>
     */
    private function normalizedEmailsFromProperties(array $properties): array
    {
        $emails = [];

        foreach (['to', 'cc'] as $field) {
            $values = $properties[$field] ?? [];

            if (! is_array($values)) {
                continue;
            }

            foreach ($values as $email) {
                $normalized = strtolower(trim((string) $email));

                if ($normalized !== '') {
                    $emails[$normalized] = true;
                }
            }
        }

        return array_keys($emails);
    }

    private function dateString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeProperties(mixed $properties): ?array
    {
        if (is_array($properties)) {
            return $properties;
        }

        if (! is_string($properties) || $properties === '') {
            return null;
        }

        $decoded = json_decode($properties, true);

        return is_array($decoded) ? $decoded : null;
    }
};
