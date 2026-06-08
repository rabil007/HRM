<?php

namespace App\Support\Hikvision;

use Illuminate\Support\Facades\Log;

/**
 * Temporary webhook diagnostics. Remove after production verification.
 */
class HikvisionWebhookDebugLog
{
    public static function enabled(): bool
    {
        return (bool) config('hikvision.webhook_debug_log', false);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function info(string $message, array $context = []): void
    {
        if (! self::enabled()) {
            return;
        }

        Log::info("[Hikvision Webhook] {$message}", $context);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function summarizePayload(array $payload): array
    {
        $summary = [
            'top_level_keys' => array_keys($payload),
            'batch_id' => $payload['batchId'] ?? null,
            'list_count' => is_array($payload['list'] ?? null) ? count($payload['list']) : 0,
            'record_list_count' => is_array($payload['recordList'] ?? null) ? count($payload['recordList']) : 0,
        ];

        if (is_array($payload['list'] ?? null) && $payload['list'] !== []) {
            $first = $payload['list'][0];
            $summary['first_list_item_type'] = is_array($first) ? ($first['type'] ?? null) : null;

            if (is_array($first)) {
                $data = is_array($first['data'] ?? null) ? $first['data'] : [];
                $summary['first_item_data_keys'] = array_keys($data);

                $openDoorInfo = is_array($data['openDoorInfo'] ?? null) ? $data['openDoorInfo'] : [];
                $event = is_array($openDoorInfo['event'] ?? null)
                    ? $openDoorInfo['event']
                    : (isset($openDoorInfo['basicInfo']) || isset($openDoorInfo['intelliInfo']) ? $openDoorInfo : []);
                $intelliInfo = is_array($event['intelliInfo'] ?? null) ? $event['intelliInfo'] : null;

                if (is_array($intelliInfo)) {
                    $summary['intelli_info_keys'] = array_keys($intelliInfo);
                    $summary['first_person'] = trim(
                        ((string) ($intelliInfo['firstName'] ?? '')).' '.((string) ($intelliInfo['lastName'] ?? ''))
                    ) ?: ($intelliInfo['personName'] ?? $intelliInfo['name'] ?? null);
                    $summary['first_person_id'] = $intelliInfo['personId'] ?? null;
                    $summary['auth_result'] = $intelliInfo['authResult'] ?? null;
                } else {
                    $summary['intelli_info_keys'] = [];
                }
            }
        }

        if (isset($payload['personInfo']) && is_array($payload['personInfo'])) {
            $summary['certificate_person'] = $payload['personInfo']['personName'] ?? null;
        }

        return $summary;
    }
}
