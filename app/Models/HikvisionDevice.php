<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class HikvisionDevice extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id',
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
            'company_id' => 'integer',
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

    public static function upsertFromApi(int $companyId, array $apiDevice, ?array $apiDetail = null): self
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
            ['company_id' => $companyId, 'serial_no' => (string) ($apiDevice['serialNo'] ?? '')],
            $attributes,
        );

        if ($device->trashed()) {
            $device->restore();
        }

        return $device;
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }
}
