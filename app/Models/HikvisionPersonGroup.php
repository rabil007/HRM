<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class HikvisionPersonGroup extends Model
{
    use SoftDeletes;

    protected $table = 'hikvision_person_groups';

    public const UNASSIGNED_GROUP_VALUE = '__unassigned__';

    protected $fillable = [
        'company_id',
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
            'company_id' => 'integer',
            'raw_payload' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    /**
     * @param  array<string, mixed>  $apiGroup
     */
    public static function upsertFromApi(int $companyId, array $apiGroup): self
    {
        if ($companyId <= 0) {
            throw new \InvalidArgumentException('Hikvision person groups require a company_id.');
        }

        $group = self::withTrashed()->updateOrCreate(
            ['company_id' => $companyId, 'group_id' => (string) ($apiGroup['groupId'] ?? '')],
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
        return $this->hasMany(HikvisionPerson::class, 'group_id', 'group_id')
            ->where('company_id', $this->company_id);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function filterOptions(int $companyId): array
    {
        $groups = self::query()
            ->forCompany($companyId)
            ->orderBy('name')
            ->get(['group_id', 'name']);

        $options = $groups
            ->map(fn (self $group): array => [
                'value' => $group->group_id,
                'label' => $group->name,
            ])
            ->values()
            ->all();

        if (HikvisionPerson::query()->forCompany($companyId)->whereNull('group_id')->exists()) {
            $options[] = [
                'value' => self::UNASSIGNED_GROUP_VALUE,
                'label' => 'Unassigned',
            ];
        }

        return $options;
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
