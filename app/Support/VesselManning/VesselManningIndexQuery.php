<?php

namespace App\Support\VesselManning;

use App\Models\Vessel;
use App\Models\VesselManning;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

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
     *     grt: string|null,
     *     bhp: int|null,
     *     is_active: bool,
     *     manning: list<array{
     *         id: int,
     *         rank_id: int,
     *         rank_name: string,
     *         required_count: int
     *     }>,
     *     total_required: int,
     *     ranks_configured: int
     * }
     */
    public static function toArray(Vessel $vessel, bool $includeDetails = false): array
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

        $payload = [
            'id' => $vessel->id,
            'name' => $vessel->name,
            'vessel_type_id' => $vessel->vessel_type_id,
            'vessel_type_name' => $vessel->vesselType?->name,
            'is_active' => (bool) $vessel->is_active,
            'manning' => $lines,
            'total_required' => (int) $manning->sum('required_count'),
            'ranks_configured' => $manning->count(),
        ];

        if ($includeDetails) {
            $payload['grt'] = $vessel->grt !== null ? (string) $vessel->grt : null;
            $payload['bhp'] = $vessel->bhp;
        }

        return $payload;
    }

    public static function findForCompany(int $companyId, Vessel $vessel): ?Vessel
    {
        return Vessel::query()
            ->with([
                'vesselType:id,name',
                'manning' => fn ($query) => $query
                    ->where('company_id', $companyId)
                    ->with('rank:id,name')
                    ->orderBy('rank_id'),
            ])
            ->whereKey($vessel->id)
            ->first();
    }

    /**
     * @return array<string, string>
     */
    public static function listBackQueryFromRequest(Request $request): array
    {
        $query = [];

        foreach (['search', 'vessel_type_id', 'page', 'per_page'] as $key) {
            $value = $request->query($key);

            if ($value !== null && $value !== '') {
                $query[$key] = (string) $value;
            }
        }

        return $query;
    }
}
