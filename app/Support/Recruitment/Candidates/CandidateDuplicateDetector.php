<?php

namespace App\Support\Recruitment\Candidates;

use App\Models\RecruitmentCandidate;
use Illuminate\Support\Collection;

final class CandidateDuplicateDetector
{
    /**
     * @return list<array{id: int, name: string, email: string|null, phone: string|null, stage: string, requirement_number: string, match_on: list<string>}>
     */
    public static function find(
        int $companyId,
        ?string $email,
        ?string $phone,
        ?int $ignoreCandidateId = null,
    ): array {
        $emailNorm = CandidateContactNormalizer::email($email);
        $phoneNorm = CandidateContactNormalizer::phone($phone);

        if ($emailNorm === null && $phoneNorm === null) {
            return [];
        }

        $query = RecruitmentCandidate::query()
            ->forCompany($companyId)
            ->when($ignoreCandidateId !== null, fn ($q) => $q->whereKeyNot($ignoreCandidateId))
            ->where(function ($q) use ($emailNorm, $phoneNorm): void {
                if ($emailNorm !== null) {
                    $q->orWhere('email_normalized', $emailNorm);
                }
                if ($phoneNorm !== null) {
                    $q->orWhere('phone_normalized', $phoneNorm);
                }
            })
            ->orderByDesc('id')
            ->limit(10);

        /** @var Collection<int, RecruitmentCandidate> $matches */
        $matches = $query->get([
            'id',
            'name',
            'email',
            'phone',
            'email_normalized',
            'phone_normalized',
            'stage',
            'requirement_number_snapshot',
        ]);

        return $matches->map(function (RecruitmentCandidate $candidate) use ($emailNorm, $phoneNorm): array {
            $matchOn = [];

            if ($emailNorm !== null && $candidate->email_normalized === $emailNorm) {
                $matchOn[] = 'email';
            }

            if ($phoneNorm !== null && $candidate->phone_normalized === $phoneNorm) {
                $matchOn[] = 'phone';
            }

            return [
                'id' => (int) $candidate->id,
                'name' => (string) $candidate->name,
                'email' => $candidate->email,
                'phone' => $candidate->phone,
                'stage' => $candidate->stage->value,
                'requirement_number' => (string) $candidate->requirement_number_snapshot,
                'match_on' => $matchOn,
            ];
        })->values()->all();
    }
}
