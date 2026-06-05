<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HikvisionDevice extends Model
{
    protected $fillable = [
        'hikvision_id',
        'serial_no',
        'name',
        'category',
        'type',
        'online_status',
        'raw_list_payload',
        'raw_detail_payload',
        'synced_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'online_status' => 'integer',
            'raw_list_payload' => 'array',
            'raw_detail_payload' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    /**
     * @param  array<string, mixed>  $apiDevice
     * @param  array<string, mixed>|null  $apiDetail
     */
    public static function upsertFromApi(array $apiDevice, ?array $apiDetail = null): self
    {
        $attributes = [
            'hikvision_id' => (string) ($apiDevice['id'] ?? ''),
            'name' => (string) ($apiDevice['name'] ?? ''),
            'category' => (string) ($apiDevice['category'] ?? ''),
            'type' => (string) ($apiDevice['type'] ?? ''),
            'online_status' => isset($apiDevice['onlineStatus']) ? (int) $apiDevice['onlineStatus'] : null,
            'raw_list_payload' => $apiDevice,
            'synced_at' => now(),
        ];

        if ($apiDetail !== null) {
            $attributes['raw_detail_payload'] = $apiDetail;
        }

        return self::query()->updateOrCreate(
            ['serial_no' => (string) ($apiDevice['serialNo'] ?? '')],
            $attributes,
        );
    }
}
