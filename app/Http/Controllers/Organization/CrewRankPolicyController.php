<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\CrewRankPolicy\DestroyCrewRankPolicyRequest;
use App\Http\Requests\Organization\CrewRankPolicy\UpsertCrewRankPolicyRequest;
use App\Models\CrewRankPolicy;
use App\Models\Rank;
use App\Support\CrewOperations\CrewRankPolicyIndexQuery;
use App\Support\CrewOperations\CrewRankPolicyPagePermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CrewRankPolicyController extends Controller
{
    public function index(Request $request): Response
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $can = CrewRankPolicyPagePermissions::for($request->user());

        abort_unless($can['view'], 403);

        return Inertia::render('organization/crew-operations/rank-policies', [
            'policies' => CrewRankPolicyIndexQuery::forCompany($companyId),
            'can' => $can,
        ]);
    }

    public function upsert(UpsertCrewRankPolicyRequest $request): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $validated = $request->validated();
        $rankId = (int) $validated['rank_id'];
        $days = (int) $validated['tour_of_duty_days'];
        $actorId = $request->user()?->id;

        $rank = Rank::query()
            ->whereKey($rankId)
            ->where('is_active', true)
            ->first();

        if ($rank === null) {
            throw ValidationException::withMessages([
                'rank_id' => 'The selected rank is invalid.',
            ]);
        }

        DB::transaction(function () use ($companyId, $rankId, $days, $actorId): void {
            $policy = CrewRankPolicy::withTrashed()
                ->forCompany($companyId)
                ->where('rank_id', $rankId)
                ->lockForUpdate()
                ->first();

            if ($policy !== null) {
                if ($policy->trashed()) {
                    $policy->restore();
                }

                $policy->fill([
                    'tour_of_duty_days' => $days,
                    'is_active' => true,
                    'updated_by' => $actorId,
                ]);
                $policy->save();

                return;
            }

            CrewRankPolicy::query()->create([
                'company_id' => $companyId,
                'rank_id' => $rankId,
                'tour_of_duty_days' => $days,
                'is_active' => true,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);
        });

        return redirect()
            ->route('organization.crew-operations.rank-policies.index')
            ->with('success', sprintf('Company Tour of Duty updated for %s.', $rank->name));
    }

    public function destroy(DestroyCrewRankPolicyRequest $request, CrewRankPolicy $policy): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless((int) $policy->company_id === $companyId, 404);

        DB::transaction(function () use ($request, $policy, $companyId): void {
            $locked = CrewRankPolicy::query()
                ->forCompany($companyId)
                ->whereKey($policy->id)
                ->lockForUpdate()
                ->firstOrFail();

            $locked->fill([
                'is_active' => false,
                'updated_by' => $request->user()?->id,
            ]);
            $locked->save();
            $locked->delete();
        });

        return redirect()
            ->route('organization.crew-operations.rank-policies.index')
            ->with('success', 'Company Tour of Duty override cleared. Global rank default will be used.');
    }
}
