<?php

namespace App\Support\Vessels;

use App\Models\Vessel;
use Illuminate\Database\Eloquent\Builder;

final class ResolvesCompanyVessels
{
    /**
     * @return list<array{id: int, name: string, client_id: int|null}>
     */
    public static function activeOptions(int $companyId): array
    {
        return self::queryForCompany($companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'client_id'])
            ->map(fn (Vessel $vessel) => [
                'id' => (int) $vessel->id,
                'name' => (string) $vessel->name,
                'client_id' => $vessel->client_id !== null ? (int) $vessel->client_id : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @return Builder<Vessel>
     */
    public static function queryForCompany(int $companyId): Builder
    {
        return Vessel::query()->forCompany($companyId);
    }

    public static function findOrFailForCompany(int $companyId, int $vesselId): Vessel
    {
        return self::queryForCompany($companyId)->whereKey($vesselId)->firstOrFail();
    }

    public static function assertBelongsToCompany(int $companyId, int $vesselId): void
    {
        abort_unless(
            self::queryForCompany($companyId)->whereKey($vesselId)->exists(),
            404,
        );
    }
}
