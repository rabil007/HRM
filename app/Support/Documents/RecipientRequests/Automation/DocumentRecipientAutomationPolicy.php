<?php

namespace App\Support\Documents\RecipientRequests\Automation;

use App\Models\DocumentRecipientAutomationSetting;
use App\Models\DocumentRecipientRequest;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

final class DocumentRecipientAutomationPolicy
{
    public const SCHEMA_VERSION = 1;

    public const MAX_REMINDERS = 5;

    public const MIN_DAYS_BEFORE_EXPIRY = 1;

    public const MAX_DAYS_BEFORE_EXPIRY = 13;

    /**
     * @return array{reminders_enabled: bool, reminder_days_before_expiry: list<int>}
     */
    public function resolveForCompany(int $companyId): array
    {
        $settings = DocumentRecipientAutomationSetting::findForCompany($companyId);

        $days = $this->normalizeDays($settings->reminder_days_before_expiry ?? []);

        if ($days === []) {
            $days = DocumentRecipientAutomationSetting::defaultAttributes()['reminder_days_before_expiry'];
        }

        return [
            'reminders_enabled' => (bool) $settings->reminders_enabled,
            'reminder_days_before_expiry' => $days,
        ];
    }

    /**
     * Snapshot for a newly created recipient request. Null when reminders are disabled.
     *
     * @return array{schema_version: int, enabled: bool, days_before_expiry: list<int>}|null
     */
    public function snapshotForCompany(int $companyId): ?array
    {
        $resolved = $this->resolveForCompany($companyId);

        if (! $resolved['reminders_enabled']) {
            return null;
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'enabled' => true,
            'days_before_expiry' => $resolved['reminder_days_before_expiry'],
        ];
    }

    /**
     * @param  array{schema_version?: int, enabled?: bool, days_before_expiry?: mixed}|null  $snapshot
     * @return array{reminder_policy_snapshot: array{schema_version: int, enabled: bool, days_before_expiry: list<int>}|null, next_reminder_at: CarbonInterface|null}
     */
    public function createSchedulingAttributes(int $companyId, CarbonInterface $expiresAt): array
    {
        $snapshot = $this->snapshotForCompany($companyId);

        return [
            'reminder_policy_snapshot' => $snapshot,
            'next_reminder_at' => $this->firstReminderAt($snapshot, $expiresAt),
        ];
    }

    /**
     * @param  array{schema_version?: int, enabled?: bool, days_before_expiry?: mixed}|null  $snapshot
     */
    public function snapshotEnablesReminders(?array $snapshot): bool
    {
        return is_array($snapshot) && ($snapshot['enabled'] ?? false) === true;
    }

    /**
     * First configured reminder timestamp for a new request (earliest chronologically).
     *
     * @param  array{schema_version?: int, enabled?: bool, days_before_expiry?: mixed}|null  $snapshot
     */
    public function firstReminderAt(?array $snapshot, CarbonInterface $expiresAt): ?CarbonInterface
    {
        if (! $this->snapshotEnablesReminders($snapshot)) {
            return null;
        }

        $days = $this->normalizeDays($snapshot['days_before_expiry'] ?? []);

        if ($days === []) {
            return null;
        }

        return $expiresAt->copy()->subDays($days[0]);
    }

    /**
     * @return list<int>
     */
    public function normalizeDays(mixed $days): array
    {
        if (! is_array($days)) {
            return [];
        }

        $normalized = [];

        foreach ($days as $day) {
            if (! is_numeric($day)) {
                continue;
            }

            $intDay = (int) $day;

            if ($intDay !== (int) (float) $day) {
                continue;
            }

            $normalized[] = $intDay;
        }

        $normalized = array_values(array_unique($normalized));
        rsort($normalized);

        return $normalized;
    }

    /**
     * @return list<int>
     */
    public function validateAndNormalizeDays(mixed $days): array
    {
        if (! is_array($days)) {
            throw ValidationException::withMessages([
                'reminder_days_before_expiry' => 'Reminder days must be a list of integers.',
            ]);
        }

        $integers = [];

        foreach ($days as $day) {
            if (! is_int($day) && ! (is_string($day) && ctype_digit($day))) {
                throw ValidationException::withMessages([
                    'reminder_days_before_expiry' => 'Reminder days must be integers only.',
                ]);
            }

            $integers[] = (int) $day;
        }

        if (count($integers) !== count(array_unique($integers))) {
            throw ValidationException::withMessages([
                'reminder_days_before_expiry' => 'Reminder days must be unique.',
            ]);
        }

        if (count($integers) > self::MAX_REMINDERS) {
            throw ValidationException::withMessages([
                'reminder_days_before_expiry' => 'At most '.self::MAX_REMINDERS.' reminder offsets are allowed.',
            ]);
        }

        foreach ($integers as $day) {
            if ($day < self::MIN_DAYS_BEFORE_EXPIRY || $day > self::MAX_DAYS_BEFORE_EXPIRY) {
                throw ValidationException::withMessages([
                    'reminder_days_before_expiry' => sprintf(
                        'Reminder days must be between %d and %d inclusive.',
                        self::MIN_DAYS_BEFORE_EXPIRY,
                        self::MAX_DAYS_BEFORE_EXPIRY,
                    ),
                ]);
            }
        }

        return $this->normalizeDays($integers);
    }

    public function automationKeyForDays(int $daysBeforeExpiry): string
    {
        return 'reminder:'.$daysBeforeExpiry.'d';
    }

    public function daysFromAutomationKey(string $automationKey): ?int
    {
        if (preg_match('/^reminder:(\d+)d$/', $automationKey, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * @return list<array{days: int, automation_key: string, scheduled_for: CarbonInterface}>
     */
    public function reminderSlotsForRequest(DocumentRecipientRequest $request, ?CarbonInterface $now = null): array
    {
        $snapshot = $request->reminder_policy_snapshot;

        if (! $this->snapshotEnablesReminders(is_array($snapshot) ? $snapshot : null)) {
            return [];
        }

        if ($request->expires_at === null) {
            return [];
        }

        $days = $this->normalizeDays($snapshot['days_before_expiry'] ?? []);
        $slots = [];

        foreach ($days as $day) {
            if ($day < self::MIN_DAYS_BEFORE_EXPIRY || $day > self::MAX_DAYS_BEFORE_EXPIRY) {
                continue;
            }

            $slots[] = [
                'days' => $day,
                'automation_key' => $this->automationKeyForDays($day),
                'scheduled_for' => $request->expires_at->copy()->subDays($day),
            ];
        }

        return $slots;
    }

    /**
     * Among due, unconsumed slots, pick the closest-to-expiry as active; older due slots are missed.
     *
     * @param  list<array{days: int, automation_key: string, scheduled_for: CarbonInterface}>  $slots
     * @param  list<string>  $consumedAutomationKeys
     * @return array{
     *     active: array{days: int, automation_key: string, scheduled_for: CarbonInterface}|null,
     *     missed: list<array{days: int, automation_key: string, scheduled_for: CarbonInterface}>
     * }
     */
    public function selectDueReminderSlots(array $slots, array $consumedAutomationKeys, ?CarbonInterface $now = null): array
    {
        $now ??= Carbon::now();
        $consumed = array_fill_keys($consumedAutomationKeys, true);

        $dueUnconsumed = [];

        foreach ($slots as $slot) {
            if (isset($consumed[$slot['automation_key']])) {
                continue;
            }

            if ($slot['scheduled_for']->greaterThan($now)) {
                continue;
            }

            $dueUnconsumed[] = $slot;
        }

        if ($dueUnconsumed === []) {
            return [
                'active' => null,
                'missed' => [],
            ];
        }

        usort(
            $dueUnconsumed,
            fn (array $a, array $b): int => $a['days'] <=> $b['days'],
        );

        $active = array_shift($dueUnconsumed);

        return [
            'active' => $active,
            'missed' => $dueUnconsumed,
        ];
    }

    /**
     * Next FUTURE unconsumed reminder timestamp after consumed slots.
     *
     * @param  list<string>  $consumedAutomationKeys
     */
    public function nextReminderAt(DocumentRecipientRequest $request, array $consumedAutomationKeys = [], ?CarbonInterface $now = null): ?CarbonInterface
    {
        $now ??= Carbon::now();
        $slots = $this->reminderSlotsForRequest($request, $now);
        $consumed = array_fill_keys($consumedAutomationKeys, true);

        $upcoming = null;

        foreach ($slots as $slot) {
            if (isset($consumed[$slot['automation_key']])) {
                continue;
            }

            if ($slot['scheduled_for']->lessThanOrEqualTo($now)) {
                continue;
            }

            if ($upcoming === null || $slot['scheduled_for']->lessThan($upcoming)) {
                $upcoming = $slot['scheduled_for'];
            }
        }

        return $upcoming;
    }
}
