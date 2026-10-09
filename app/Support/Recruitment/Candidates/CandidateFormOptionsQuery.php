<?php

namespace App\Support\Recruitment\Candidates;

use App\Enums\Recruitment\CandidateInterviewMode;
use App\Enums\Recruitment\CandidateSource;
use App\Enums\Recruitment\RequirementLineStatus;
use App\Enums\Recruitment\RequirementStatus;
use App\Models\Country;
use App\Models\Currency;
use App\Models\RecruitmentRequirement;
use App\Models\User;
use App\Support\Recruitment\CompanyUserOptionsQuery;
use App\Support\Settings\CompanyCurrency;

final class CandidateFormOptionsQuery
{
    /**
     * @return array<string, mixed>
     */
    public static function forCompany(int $companyId, ?User $user = null): array
    {
        $requirementsQuery = RecruitmentRequirement::query()
            ->forCompany($companyId)
            ->where('status', RequirementStatus::Open)
            ->with([
                'lines' => fn ($q) => $q
                    ->where('status', RequirementLineStatus::Open)
                    ->with('position:id,title')
                    ->orderBy('id'),
                'assignedRecruiter:id,name',
                'client:id,name',
            ])
            ->orderByDesc('id');

        if ($user !== null
            && ! $user->can('recruitment.candidates.manage')
            && $user->can('recruitment.candidates.create')
        ) {
            $requirementsQuery->where('assigned_to', $user->id);
        }

        $requirements = $requirementsQuery->get()->map(function (RecruitmentRequirement $requirement): array {
            return [
                'id' => (int) $requirement->id,
                'requirement_number' => (string) $requirement->requirement_number,
                'client_name' => $requirement->client?->name,
                'assigned_to' => $requirement->assigned_to,
                'assigned_to_name' => $requirement->assignedRecruiter?->name,
                'lines' => $requirement->lines->map(fn ($line): array => [
                    'id' => (int) $line->id,
                    'position_id' => (int) $line->position_id,
                    'position_title' => (string) ($line->position?->title ?? 'Position'),
                    'salary_min' => $line->salary_min !== null ? (string) $line->salary_min : null,
                    'salary_max' => $line->salary_max !== null ? (string) $line->salary_max : null,
                    'salary_currency_code' => $line->salary_currency_code,
                ])->values()->all(),
            ];
        })->values()->all();

        $nationalities = Country::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Country $c): array => [
                'id' => (int) $c->id,
                'name' => (string) $c->name,
            ])
            ->all();

        $currencies = Currency::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['code', 'name', 'symbol'])
            ->map(fn (Currency $currency): array => [
                'code' => (string) $currency->code,
                'name' => (string) $currency->name,
                'symbol' => (string) ($currency->symbol ?? ''),
            ])
            ->all();

        return [
            'requirements' => $requirements,
            'nationalities' => $nationalities,
            'currencies' => $currencies,
            'default_currency_code' => CompanyCurrency::codeForCompany($companyId),
            'sources' => array_map(
                fn (CandidateSource $s): array => ['value' => $s->value, 'label' => $s->label()],
                CandidateSource::cases(),
            ),
            'interview_modes' => array_map(
                fn (CandidateInterviewMode $m): array => ['value' => $m->value, 'label' => $m->label()],
                CandidateInterviewMode::cases(),
            ),
            'interviewers' => CompanyUserOptionsQuery::forCompany($companyId),
        ];
    }
}
