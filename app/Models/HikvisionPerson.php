<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HikvisionPerson extends Model
{
    protected $table = 'hikvision_persons';

    protected $fillable = [
        'person_id',
        'first_name',
        'last_name',
        'phone',
        'email',
        'is_expired',
        'photo_url',
        'raw_payload',
        'synced_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_expired' => 'boolean',
            'raw_payload' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    /**
     * @param  array<string, mixed>  $apiPerson
     */
    public static function upsertFromApi(array $apiPerson): self
    {
        return self::query()->updateOrCreate(
            ['person_id' => (string) ($apiPerson['personId'] ?? '')],
            [
                'first_name' => (string) ($apiPerson['firstName'] ?? ''),
                'last_name' => (string) ($apiPerson['lastName'] ?? ''),
                'phone' => (string) ($apiPerson['phone'] ?? ''),
                'email' => (string) ($apiPerson['email'] ?? ''),
                'is_expired' => (bool) ($apiPerson['isExpired'] ?? false),
                'photo_url' => (string) ($apiPerson['photoUrl'] ?? $apiPerson['headPicUrl'] ?? ''),
                'raw_payload' => $apiPerson,
                'synced_at' => now(),
            ],
        );
    }

    public function displayName(): string
    {
        $name = trim("{$this->first_name} {$this->last_name}");

        return $name !== '' ? $name : $this->person_id;
    }
}
