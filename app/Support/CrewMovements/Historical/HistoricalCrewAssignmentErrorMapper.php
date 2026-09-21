<?php

namespace App\Support\CrewMovements\Historical;

/**
 * Maps backend historical validation keys to Manual Entry / Excel UI field aliases
 * and extracts domain-level messages for visible alerts.
 */
final class HistoricalCrewAssignmentErrorMapper
{
    /**
     * Backend chronological keys → frontend form field names.
     *
     * @var array<string, string>
     */
    public const FORM_ALIASES = [
        'training_start_at' => 'training_started_at',
        'training_end_at' => 'training_ended_at',
        'demob_standby_at' => 'post_signoff_standby_at',
        'mobilisation_start_at' => 'mobilisation_at',
    ];

    /**
     * Domain-level keys that should appear in the top-level validation alert.
     *
     * @var list<string>
     */
    public const ALERT_KEYS = [
        'overlap',
        'sea_service',
        'assignment',
        'dates',
        'workbook',
        'file',
    ];

    /**
     * @param  array<string, string|list<string>>  $errors
     * @return array<string, string>
     */
    public static function withFormAliases(array $errors): array
    {
        $mapped = [];

        foreach ($errors as $key => $message) {
            $text = is_array($message) ? (string) ($message[0] ?? '') : (string) $message;

            if ($text === '') {
                continue;
            }

            $mapped[$key] = $text;

            $alias = self::FORM_ALIASES[$key] ?? null;

            if ($alias !== null && ! isset($mapped[$alias])) {
                $mapped[$alias] = $text;
            }
        }

        return $mapped;
    }

    /**
     * Prefer domain alert messages; fall back to the first useful field message.
     *
     * @param  array<string, string|list<string>>  $errors
     */
    public static function alertMessage(array $errors, ?string $fallback = null): ?string
    {
        $normalized = self::withFormAliases($errors);
        $messages = [];

        foreach (self::ALERT_KEYS as $key) {
            if (isset($normalized[$key]) && $normalized[$key] !== '') {
                $messages[] = $normalized[$key];
            }
        }

        if ($messages !== []) {
            return implode(' ', array_unique($messages));
        }

        return $fallback;
    }
}
