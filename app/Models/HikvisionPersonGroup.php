<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class HikvisionPersonGroup extends Model
{
    use SoftDeletes;

    protected $table = 'hikvision_person_groups';

    public const UNASSIGNED_GROUP_VALUE = '__unassigned__';

    protected $fillable = [
        'group_id',
        'name',
        'parent_id',
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
     * @param  array<string, mixed>  $apiGroup
     */
    public static function upsertFromApi(array $apiGroup): self
    {
        $group = self::withTrashed()->updateOrCreate(
            ['group_id' => (string) ($apiGroup['groupId'] ?? '')],
            [
                'name' => (string) ($apiGroup['groupName'] ?? ''),
                'parent_id' => filled($apiGroup['parentId'] ?? null) ? (string) $apiGroup['parentId'] : null,
                'raw_payload' => $apiGroup,
                'synced_at' => now(),
            ],
        );

        if ($group->trashed()) {
            $group->restore();
        }

        return $group;
    }

    /**
     * @return HasMany<HikvisionPerson, $this>
     */
    public function persons(): HasMany
    {
        return $this->hasMany(HikvisionPerson::class, 'group_id', 'group_id');
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function filterOptions(): array
    {
        $groups = self::query()
            ->orderBy('name')
            ->get(['group_id', 'name']);

        $options = $groups
            ->map(fn (self $group): array => [
                'value' => $group->group_id,
                'label' => $group->name,
            ])
            ->values()
            ->all();

        if (HikvisionPerson::query()->whereNull('group_id')->exists()) {
            $options[] = [
                'value' => self::UNASSIGNED_GROUP_VALUE,
                'label' => 'Unassigned',
            ];
        }

        return $options;
    }
}
