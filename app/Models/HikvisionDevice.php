<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class HikvisionDevice extends Model
{
    use SoftDeletes;

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
    /**
     * @return array{
     *     id: int,
     *     hikvision_id: string,
     *     serial_no: string,
     *     name: string|null,
     *     category: string|null,
     *     type: string|null,
     *     online_status: int|null,
     *     synced_at: string|null,
     *     detail: array<string, mixed>|null
     * }
     */
    public function toPageArray(): array
    {
        return [
            'id' => $this->id,
            'hikvision_id' => $this->hikvision_id,
            'serial_no' => $this->serial_no,
            'name' => $this->name,
            'category' => $this->category,
            'type' => $this->type,
            'online_status' => $this->online_status,
            'synced_at' => $this->synced_at?->toIso8601String(),
            'detail' => $this->raw_detail_payload,
        ];
    }

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

        $device = self::withTrashed()->updateOrCreate(
            ['serial_no' => (string) ($apiDevice['serialNo'] ?? '')],
            $attributes,
        );

        if ($device->trashed()) {
            $device->restore();
        }

        return $device;
    }
}
