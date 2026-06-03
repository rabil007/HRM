<?php

namespace App\Support\Email;

final class CommaSeparatedEmailList
{
    /**
     * @return list<string>
     */
    public static function parse(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        return collect(preg_split('/\s*,\s*/', $raw) ?: [])
            ->map(fn (string $email) => trim($email))
            ->filter(fn (string $email) => $email !== '')
            ->unique(fn (string $email) => strtolower($email))
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $toPreset
     * @param  list<string>  $ccPreset
     * @return array{recipient: string, cc: list<string>}
     */
    public static function resolveRecipients(array $toPreset, array $ccPreset): array
    {
        if ($toPreset === []) {
            return ['recipient' => '', 'cc' => $ccPreset];
        }

        return [
            'recipient' => $toPreset[0],
            'cc' => array_values(array_unique([...array_slice($toPreset, 1), ...$ccPreset], SORT_REGULAR)),
        ];
    }
}
