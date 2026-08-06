<?php

namespace App\Support\CrewOperations;

use App\Models\CrewRankPolicy;
use App\Models\Rank;
use App\Support\CrewMovements\CrewTourOfDutyResolver;

final class CrewRankPolicyIndexQuery
{
    /**
     * @return list<array{
     *     rank_id: int,
     *     rank_name: string,
     *     is_active: bool,
     *     global_tour_of_duty_days: int|null,
     *     company_tour_of_duty_days: int|null,
     *     policy_id: int|null,
     *     resolved_tour_of_duty_days: int|null,
     *     resolved_tour_of_duty_source: string|null
     * }>
     */
    public static function forCompany(int $companyId): array
    {
        $resolver = new CrewTourOfDutyResolver;

        $policies = CrewRankPolicy::query()
            ->forCompany($companyId)
            ->active()
            ->get()
            ->keyBy('rank_id');

        $policyDaysByRankId = $policies
            ->mapWithKeys(fn (CrewRankPolicy $policy): array => [
                (int) $policy->rank_id => (int) $policy->tour_of_duty_days,
            ])
            ->all();

        return Rank::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'is_active', 'max_tour_of_duty_days'])
            ->map(function (Rank $rank) use ($resolver, $companyId, $policies, $policyDaysByRankId): array {
                $preview = $resolver->previewForRank($companyId, $rank, $policyDaysByRankId);
                /** @var CrewRankPolicy|null $policy */
                $policy = $policies->get($rank->id);

                return [
                    'rank_id' => $rank->id,
                    'rank_name' => $rank->name,
                    'is_active' => (bool) $rank->is_active,
                    'global_tour_of_duty_days' => $preview['global_tour_of_duty_days'],
                    'company_tour_of_duty_days' => $preview['company_tour_of_duty_days'],
                    'policy_id' => $policy?->id,
                    'resolved_tour_of_duty_days' => $preview['resolved_tour_of_duty_days'],
                    'resolved_tour_of_duty_source' => $preview['resolved_tour_of_duty_source'],
                ];
            })
            ->values()
            ->all();
    }
}
