<?php

namespace App\Support\VesselManning;

use App\Models\Vessel;
use App\Models\VesselManning;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class VesselManningIndexQuery
{
    /**
     * @return LengthAwarePaginator<int, Vessel>
     */
    public static function paginate(int $companyId, string $search = '', ?int $vesselTypeId = null, int $perPage = 20): LengthAwarePaginator
    {
        return Vessel::query()
            ->with([
                'vesselType:id,name',
                'manning' => fn ($query) => $query
                    ->where('company_id', $companyId)
                    ->with('rank:id,name')
                    ->orderBy('rank_id'),
            ])
            ->when($search !== '', fn (Builder $query) => $query->where('name', 'like', "%{$search}%"))
            ->when($vesselTypeId !== null, fn (Builder $query) => $query->where('vessel_type_id', $vesselTypeId))
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @return array{
     *     id: int,
     *     name: string,
     *     vessel_type_id: int,
     *     vessel_type_name: string|null,
     *     is_active: bool,
     *     manning: list<array{
     *         id: int,
     *         rank_id: int,
     *         rank_name: string,
     *         required_count: int
     *     }>,
     *     total_required: int
     * }
     */
    public static function toArray(Vessel $vessel): array
    {
        /** @var Collection<int, VesselManning> $manning */
        $manning = $vessel->manning;

        $lines = $manning
            ->map(fn (VesselManning $line) => [
                'id' => $line->id,
                'rank_id' => $line->rank_id,
                'rank_name' => $line->rank?->name ?? '',
                'required_count' => $line->required_count,
            ])
            ->values()
            ->all();

        return [
            'id' => $vessel->id,
            'name' => $vessel->name,
            'vessel_type_id' => $vessel->vessel_type_id,
            'vessel_type_name' => $vessel->vesselType?->name,
            'is_active' => (bool) $vessel->is_active,
            'manning' => $lines,
            'total_required' => (int) $manning->sum('required_count'),
        ];
    }
}
