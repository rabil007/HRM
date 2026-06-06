<?php

namespace App\Support\Hikvision;

use Illuminate\Support\Carbon;

class HikvisionPersonWritePayload
{
    private const DEFAULT_END_DATE = '2037-12-31T23:59:59+00:00';

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function forCreate(array $validated): array
    {
        $payload = [
            'firstName' => (string) ($validated['first_name'] ?? ''),
            'lastName' => (string) ($validated['last_name'] ?? ''),
            'gender' => 2,
            'startDate' => now()->format('Y-m-d\TH:i:sP'),
            'endDate' => self::DEFAULT_END_DATE,
        ];

        if (filled($validated['group_id'] ?? null)) {
            $payload['groupId'] = (string) $validated['group_id'];
        }

        if (filled($validated['person_code'] ?? null)) {
            $payload['personCode'] = (string) $validated['person_code'];
        }

        if (filled($validated['email'] ?? null)) {
            $payload['email'] = (string) $validated['email'];
        }

        if (filled($validated['phone'] ?? null)) {
            $payload['phone'] = (string) $validated['phone'];
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $existingDetail
     * @return array<string, mixed>
     */
    public static function forUpdate(array $validated, array $existingDetail): array
    {
        $payload = [
            'personId' => (string) ($existingDetail['personId'] ?? ''),
            'groupId' => filled($validated['group_id'] ?? null)
                ? (string) $validated['group_id']
                : (string) ($existingDetail['groupId'] ?? ''),
            'firstName' => (string) ($validated['first_name'] ?? ''),
            'lastName' => (string) ($validated['last_name'] ?? ''),
            'gender' => (int) ($existingDetail['gender'] ?? 2),
            'personCode' => (string) ($existingDetail['personCode'] ?? ''),
            'startDate' => self::formatDate($existingDetail['startDate'] ?? now()),
            'endDate' => self::formatDate($existingDetail['endDate'] ?? self::DEFAULT_END_DATE),
        ];

        $email = filled($validated['email'] ?? null)
            ? (string) $validated['email']
            : (string) ($existingDetail['email'] ?? '');

        if ($email !== '') {
            $payload['email'] = $email;
        }

        $phone = filled($validated['phone'] ?? null)
            ? (string) $validated['phone']
            : (string) ($existingDetail['phone'] ?? '');

        if ($phone !== '') {
            $payload['phone'] = $phone;
        }

        $description = (string) ($existingDetail['description'] ?? '');

        if ($description !== '') {
            $payload['description'] = $description;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $existingDetail
     * @return array<string, mixed>
     */
    public static function mergeUpdatedDetail(array $validated, array $existingDetail): array
    {
        $detail = $existingDetail;
        $detail['firstName'] = (string) ($validated['first_name'] ?? $existingDetail['firstName'] ?? '');
        $detail['lastName'] = (string) ($validated['last_name'] ?? $existingDetail['lastName'] ?? '');

        if (filled($validated['group_id'] ?? null)) {
            $detail['groupId'] = (string) $validated['group_id'];
        }

        if (filled($validated['email'] ?? null)) {
            $detail['email'] = (string) $validated['email'];
        }

        if (filled($validated['phone'] ?? null)) {
            $detail['phone'] = (string) $validated['phone'];
        }

        return $detail;
    }

    public static function formatDate(mixed $value): string
    {
        if (is_numeric($value)) {
            return Carbon::createFromTimestampMs((int) $value)->format('Y-m-d\TH:i:sP');
        }

        return (string) $value;
    }
}
