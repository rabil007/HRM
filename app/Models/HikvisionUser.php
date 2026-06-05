<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HikvisionUser extends Model
{
    protected $fillable = [
        'hikvision_id',
        'name',
        'raw_payload',
        'synced_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    /**
     * @param  array{id: string, name: string}  $apiUser
     */
    public static function upsertFromApi(array $apiUser): self
    {
        return self::query()->updateOrCreate(
            ['hikvision_id' => (string) $apiUser['id']],
            [
                'name' => (string) $apiUser['name'],
                'raw_payload' => $apiUser,
                'synced_at' => now(),
            ],
        );
    }
}
