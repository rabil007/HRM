<?php

namespace App\Support\Vessels;

use App\Models\Vessel;
use App\Models\VesselManning;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

final class VesselIndexQuery
{
    /**
     * @return LengthAwarePaginator<int, Vessel>
     */
    public static function paginate(
        int $companyId,
        string $search = '',
        ?int $vesselTypeId = null,
        int $perPage = 20,
    ): LengthAwarePaginator {
        return Vessel::query()
            ->where('company_id', $companyId)
            ->with([
                'vesselType:id,name',
                'manning' => fn ($query) => $query
                    ->where('company_id', $companyId)
                    ->with('rank:id,name')
                    ->orderBy('rank_id'),
            ])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $inner) use ($search): void {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('official_no', 'like', "%{$search}%")
                        ->orWhere('call_sign', 'like', "%{$search}%")
                        ->orWhere('imo_no', 'like', "%{$search}%")
                        ->orWhereHas('vesselType', fn (Builder $type) => $type->where('name', 'like', "%{$search}%"));
                });
            })
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
     *     vessel_type: array{id: int, name: string}|null,
     *     vessel_type_name: string|null,
     *     grt: string|null,
     *     bhp: int|null,
     *     official_no: string|null,
     *     call_sign: string|null,
     *     imo_no: string|null,
     *     certificate_original_filename: string|null,
     *     certificate_url: string|null,
     *     is_active: bool,
     *     manning: list<array{id: int, rank_id: int, rank_name: string, required_count: int}>,
     *     total_required: int,
     *     ranks_configured: int
     * }
     */
    public static function toArray(Vessel $vessel, bool $includeDetails = false): array
    {
        /** @var Collection<int, VesselManning> $manning */
        $manning = $vessel->relationLoaded('manning')
            ? $vessel->manning
            : collect();

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
            'vessel_type' => $vessel->vesselType
                ? [
                    'id' => $vessel->vesselType->id,
                    'name' => $vessel->vesselType->name,
                ]
                : null,
            'vessel_type_name' => $vessel->vesselType?->name,
            'grt' => $vessel->grt !== null ? (string) $vessel->grt : null,
            'bhp' => $vessel->bhp,
            'official_no' => $vessel->official_no,
            'call_sign' => $vessel->call_sign,
            'imo_no' => $vessel->imo_no,
            'certificate_original_filename' => $vessel->certificate_original_filename,
            'certificate_url' => self::certificateUrl($vessel->certificate_path),
            'is_active' => (bool) $vessel->is_active,
            'manning' => $lines,
            'total_required' => (int) $manning->sum('required_count'),
            'ranks_configured' => $manning->count(),
        ];

        if ($includeDetails) {
            $payload['created_at'] = $vessel->created_at?->toIso8601String();
            $payload['updated_at'] = $vessel->updated_at?->toIso8601String();
        }

        return $payload;
    }

    public static function findForCompany(int $companyId, Vessel $vessel): ?Vessel
    {
        return Vessel::query()
            ->where('company_id', $companyId)
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

    private static function certificateUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        return Storage::disk('public')->url($path);
    }
}
