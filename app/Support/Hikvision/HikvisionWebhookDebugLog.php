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
                $intelliInfo = $first['data']['openDoorInfo']['event']['intelliInfo'] ?? null;
                if (is_array($intelliInfo)) {
                    $summary['first_person'] = trim(
                        ((string) ($intelliInfo['firstName'] ?? '')).' '.((string) ($intelliInfo['lastName'] ?? ''))
                    ) ?: null;
                    $summary['first_person_id'] = $intelliInfo['personId'] ?? null;
                }
            }
        }

        if (isset($payload['personInfo']) && is_array($payload['personInfo'])) {
            $summary['certificate_person'] = $payload['personInfo']['personName'] ?? null;
        }

        return $summary;
    }
}
