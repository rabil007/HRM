<?php

namespace App\Http\Controllers\Organization\Recruitment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Recruitment\StoreCandidateRequest;
use App\Http\Requests\Organization\Recruitment\UpdateCandidateInterviewRequest;
use App\Http\Requests\Organization\Recruitment\UpdateCandidateRequest;
use App\Models\RecruitmentCandidate;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementLine;
use App\Support\Activity\RecentActivityQuery;
use App\Support\Recruitment\Candidates\Actions\CreateCandidate;
use App\Support\Recruitment\Candidates\Actions\UpdateCandidateInterview;
use App\Support\Recruitment\Candidates\Actions\UpdateCandidateProfile;
use App\Support\Recruitment\Candidates\CandidateBrowseOptionsQuery;
use App\Support\Recruitment\Candidates\CandidateBrowseQuery;
use App\Support\Recruitment\Candidates\CandidateDuplicateDetector;
use App\Support\Recruitment\Candidates\CandidateFormOptionsQuery;
use App\Support\Recruitment\Candidates\CandidatePagePermissions;
use App\Support\Recruitment\Candidates\CandidatePresenter;
use App\Support\Settings\CompanyTimezone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CandidateController extends Controller
{
    public function index(Request $request): Response
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $user = $request->user();
        abort_unless($user !== null, 403);

        $browse = CandidateBrowseQuery::get($request, $companyId);
        $timezone = CompanyTimezone::forCompany($companyId);

        $mapRows = function ($items) use ($user, $timezone): array {
            return collect($items)
                ->map(fn (RecruitmentCandidate $candidate): array => CandidatePresenter::toIndexRow($candidate, $user, $timezone))
                ->all();
        };

        $candidatesPayload = null;
        $kanbanPayload = null;

        if ($browse['mode'] === 'kanban' && $browse['kanban'] !== null) {
            $kanbanPayload = [];
            foreach ($browse['kanban'] as $stage => $column) {
                $kanbanPayload[$stage] = [
                    'total' => $column['total'],
                    'data' => $mapRows($column['paginator']->items()),
                    'current_page' => $column['paginator']->currentPage(),
                    'last_page' => $column['paginator']->lastPage(),
                    'per_page' => $column['paginator']->perPage(),
                    'from' => $column['from'] ?? $column['paginator']->firstItem(),
                    'to' => $column['to'] ?? $column['paginator']->lastItem(),
                ];
            }
        } elseif ($browse['paginator'] !== null) {
            $paginator = $browse['paginator'];
            $candidatesPayload = [
                'data' => $mapRows($paginator->items()),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ];
        }

        return Inertia::render('organization/recruitment/candidates/index', [
            'candidates' => $candidatesPayload,
            'kanban' => $kanbanPayload,
            'stage_totals' => $browse['stage_totals'],
            'filters' => $browse['filters'],
            'search' => $browse['search'],
            'options' => CandidateFormOptionsQuery::forCompany($companyId, $user),
            'browse_options' => CandidateBrowseOptionsQuery::forCompany($companyId),
            'can' => CandidatePagePermissions::for($user),
            'timezone' => $timezone,
        ]);
    }

    public function show(Request $request, RecruitmentCandidate $candidate): Response
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $user = $request->user();
        abort_unless($user !== null, 403);
        abort_unless((int) $candidate->company_id === $companyId, 404);

        $canViewAudit = (bool) $user->can('audit.view');

        $with = [
            'nationality:id,name',
            'requirement.client:id,name',
            'requirement.project:id,title',
            'requirement.assignedRecruiter:id,name',
            'line.position:id,title',
            'interviewerUser:id,name,email',
        ];

        if ($canViewAudit) {
            $with[] = 'stageTransitions.performer:id,name';
        }

        $candidate->load($with);

        $timezone = CompanyTimezone::forCompany($companyId);

        return Inertia::render('organization/recruitment/candidates/show', [
            'candidate' => CandidatePresenter::toShowArray($candidate, $user, $timezone, $canViewAudit),
            'options' => CandidateFormOptionsQuery::forCompany($companyId, $user),
            'can' => CandidatePagePermissions::for($user),
            'recent_activity' => $canViewAudit
                ? RecentActivityQuery::for($user, $companyId, RecruitmentCandidate::class, $candidate->id)
                : [],
            'can_view_audit' => $canViewAudit,
            'timezone' => $timezone,
        ]);
    }

    public function store(StoreCandidateRequest $request, CreateCandidate $action): RedirectResponse|JsonResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $user = $request->user();
        abort_unless($user !== null, 403);

        $requirement = RecruitmentRequirement::query()
            ->forCompany($companyId)
            ->whereKey((int) $request->integer('recruitment_requirement_id'))
            ->firstOrFail();

        $line = RecruitmentRequirementLine::query()
            ->where('company_id', $companyId)
            ->whereKey((int) $request->integer('recruitment_requirement_line_id'))
            ->firstOrFail();

        if (! $request->boolean('ignore_duplicate_warning')) {
            $duplicates = CandidateDuplicateDetector::find(
                $companyId,
                $request->input('email'),
                $request->input('phone'),
            );

            if ($duplicates !== []) {
                if ($request->expectsJson()) {
                    return response()->json([
                        'duplicate_warning' => true,
                        'duplicates' => $duplicates,
                    ], 422);
                }

                return back()
                    ->withInput()
                    ->withErrors(['duplicate_warning' => 'Possible duplicate candidates were found for this contact information.'])
                    ->with('duplicate_candidates', $duplicates);
            }
        }

        $candidate = $action->handle($user, $companyId, $requirement, $line, [
            'name' => $request->string('name')->toString(),
            'email' => $request->input('email'),
            'phone' => $request->input('phone'),
            'nationality_id' => $request->input('nationality_id'),
            'source' => $request->input('source'),
            'notes' => $request->input('notes'),
            'cv' => $request->file('cv'),
        ]);

        return redirect()
            ->route('organization.recruitment.candidates.show', $candidate)
            ->with('success', 'Candidate created successfully.');
    }

    public function update(
        UpdateCandidateRequest $request,
        RecruitmentCandidate $candidate,
        UpdateCandidateProfile $action,
    ): RedirectResponse|JsonResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        $user = $request->user();
        abort_unless($user !== null, 403);
        abort_unless((int) $candidate->company_id === $companyId, 404);

        $candidate->loadMissing('requirement');

        if (! $request->boolean('ignore_duplicate_warning')) {
            $duplicates = CandidateDuplicateDetector::find(
                $companyId,
                $request->input('email'),
                $request->input('phone'),
                (int) $candidate->id,
            );

            if ($duplicates !== []) {
                if ($request->expectsJson()) {
                    return response()->json([
                        'duplicate_warning' => true,
                        'duplicates' => $duplicates,
                    ], 422);
                }

                return back()
                    ->withInput()
                    ->withErrors(['duplicate_warning' => 'Possible duplicate candidates were found for this contact information.'])
                    ->with('duplicate_candidates', $duplicates);
            }
        }

        $action->handle($user, $candidate, [
            'name' => $request->string('name')->toString(),
            'email' => $request->input('email'),
            'phone' => $request->input('phone'),
            'nationality_id' => $request->input('nationality_id'),
            'source' => $request->input('source'),
            'notes' => $request->input('notes'),
            'cv' => $request->file('cv'),
            'remove_cv' => $request->boolean('remove_cv'),
            'lock_version' => $request->input('lock_version'),
        ]);

        return back()->with('success', 'Candidate updated successfully.');
    }

    public function updateInterview(
        UpdateCandidateInterviewRequest $request,
        RecruitmentCandidate $candidate,
        UpdateCandidateInterview $action,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        $user = $request->user();
        abort_unless($user !== null, 403);
        abort_unless((int) $candidate->company_id === $companyId, 404);

        $candidate->loadMissing('requirement', 'line');

        $action->handle($user, $candidate, $request->validated());

        return back()->with('success', 'Interview information updated.');
    }
}
