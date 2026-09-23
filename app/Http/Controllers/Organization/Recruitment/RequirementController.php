<?php

namespace App\Http\Controllers\Organization\Recruitment;

use App\Actions\Recruitment\CreateRequirementAction;
use App\Actions\Recruitment\UpdateRequirementAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Recruitment\StoreRequirementRequest;
use App\Http\Requests\Organization\Recruitment\UpdateRequirementRequest;
use App\Models\Client;
use App\Models\Position;
use App\Models\Project;
use App\Models\RecruitmentRequirement;
use App\Models\User;
use App\Support\Activity\RecentActivityQuery;
use App\Support\Recruitment\DuplicateRequirementDetector;
use App\Support\Recruitment\RequirementBrowseQuery;
use App\Support\Recruitment\RequirementPagePermissions;
use App\Support\Recruitment\RequirementPresenter;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RequirementController extends Controller
{
    public function index(Request $request): Response
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $browse = RequirementBrowseQuery::get($request, $companyId);
        $today = Carbon::today();

        $items = collect($browse['paginator']->items())->map(function (RecruitmentRequirement $requirement) use ($today): array {
            return RequirementPresenter::toIndexRow($requirement, $today);
        })->all();

        $pagination = [
            'current_page' => $browse['paginator']->currentPage(),
            'last_page' => $browse['paginator']->lastPage(),
            'per_page' => $browse['paginator']->perPage(),
            'total' => $browse['paginator']->total(),
            'from' => $browse['paginator']->firstItem(),
            'to' => $browse['paginator']->lastItem(),
        ];

        $clients = Client::query()
            ->orderBy('name')
            ->get(['id', 'name', 'is_active'])
            ->map(fn (Client $c): array => [
                'id' => (int) $c->id,
                'name' => (string) $c->name,
                'is_active' => (bool) $c->is_active,
            ])
            ->all();

        $projects = Project::query()
            ->orderBy('title')
            ->get(['id', 'client_id', 'title', 'is_active'])
            ->map(fn (Project $p): array => [
                'id' => (int) $p->id,
                'client_id' => (int) $p->client_id,
                'title' => (string) $p->title,
                'is_active' => (bool) $p->is_active,
            ])
            ->all();

        $positions = Position::query()
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->orderBy('title')
            ->get(['id', 'title', 'grade', 'status'])
            ->map(fn (Position $p): array => [
                'id' => (int) $p->id,
                'title' => (string) $p->title,
                'grade' => $p->grade,
                'status' => (string) $p->status,
            ])
            ->all();

        $users = User::query()
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $u): array => [
                'id' => (int) $u->id,
                'name' => (string) $u->name,
                'email' => (string) $u->email,
            ])
            ->all();

        $options = [
            'clients' => $clients,
            'projects' => $projects,
            'positions' => $positions,
            'recruiters' => $users,
        ];

        return Inertia::render('organization/recruitment/requirements/index', [
            'requirements' => [
                'data' => $items,
                'links' => $browse['paginator']->linkCollection()->toArray(),
                'current_page' => $browse['paginator']->currentPage(),
                'last_page' => $browse['paginator']->lastPage(),
                'per_page' => $browse['paginator']->perPage(),
                'total' => $browse['paginator']->total(),
                'from' => $browse['paginator']->firstItem(),
                'to' => $browse['paginator']->lastItem(),
            ],
            'summary' => $browse['summary_cards'],
            'tab_counts' => $browse['tab_counts'],
            'filters' => $browse['filters'],
            'options' => $options,
            'can' => RequirementPagePermissions::for($request->user()),
            'summary_cards' => $browse['summary_cards'],
            'pagination' => $pagination,
            'current_tab' => $browse['current_tab'],
            'search' => $browse['search'],
            'clients' => $clients,
            'projects' => $projects,
            'positions' => $positions,
            'users' => $users,
        ]);
    }

    public function show(Request $request, RecruitmentRequirement $requirement): Response
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $requirement->company_id === $companyId, 404);

        $requirement->load([
            'client:id,name',
            'project:id,title',
            'assignedRecruiter:id,name,email',
            'repeatedFrom:id,requirement_number',
            'lines.position.department:id,name',
            'attachments.uploader:id,name',
            'creator:id,name',
            'updater:id,name',
        ]);

        $canViewAudit = (bool) $request->user()?->can('audit.view');
        $recentActivity = RecentActivityQuery::for(
            $request->user(),
            $companyId,
            RecruitmentRequirement::class,
            $requirement->id,
        );

        $clients = Client::query()
            ->orderBy('name')
            ->get(['id', 'name', 'is_active'])
            ->map(fn (Client $c): array => [
                'id' => (int) $c->id,
                'name' => (string) $c->name,
                'is_active' => (bool) $c->is_active,
            ])
            ->all();

        $projects = Project::query()
            ->orderBy('title')
            ->get(['id', 'client_id', 'title', 'is_active'])
            ->map(fn (Project $p): array => [
                'id' => (int) $p->id,
                'client_id' => (int) $p->client_id,
                'title' => (string) $p->title,
                'is_active' => (bool) $p->is_active,
            ])
            ->all();

        $positions = Position::query()
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->orderBy('title')
            ->get(['id', 'title', 'grade', 'status'])
            ->map(fn (Position $p): array => [
                'id' => (int) $p->id,
                'title' => (string) $p->title,
                'grade' => $p->grade,
                'status' => (string) $p->status,
            ])
            ->all();

        $users = User::query()
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $u): array => [
                'id' => (int) $u->id,
                'name' => (string) $u->name,
                'email' => (string) $u->email,
            ])
            ->all();

        $options = [
            'clients' => $clients,
            'projects' => $projects,
            'positions' => $positions,
            'recruiters' => $users,
        ];

        return Inertia::render('organization/recruitment/requirements/show', [
            'requirement' => RequirementPresenter::toShow($requirement),
            'options' => $options,
            'clients' => $clients,
            'projects' => $projects,
            'positions' => $positions,
            'users' => $users,
            'can' => RequirementPagePermissions::for($request->user()),
            'can_view_audit' => $canViewAudit,
            'recent_activity' => $recentActivity,
        ]);
    }

    public function store(
        StoreRequirementRequest $request,
        CreateRequirementAction $action,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        $userId = (int) $request->user()->id;

        $validated = $request->validated();
        $ignoreDuplicateWarning = (bool) ($validated['ignore_duplicate_warning'] ?? false);

        if (! $ignoreDuplicateWarning) {
            $positionIds = array_map(fn (array $line): int => (int) $line['position_id'], $validated['lines']);
            $similar = DuplicateRequirementDetector::findSimilar(
                $companyId,
                (int) $validated['client_id'],
                ! empty($validated['project_id']) ? (int) $validated['project_id'] : null,
                $positionIds,
            );

            if ($similar->isNotEmpty()) {
                return redirect()->back()
                    ->withInput()
                    ->with('similar_requirements', $similar->map(fn (RecruitmentRequirement $r): array => RequirementPresenter::toIndexRow($r))->all());
            }
        }

        $requirement = $action->execute(
            $companyId,
            $userId,
            $validated,
            $request->file('attachment'),
        );

        return redirect()->route('organization.recruitment.requirements.index')
            ->with('success', "Requirement {$requirement->requirement_number} created successfully.");
    }

    public function update(
        UpdateRequirementRequest $request,
        RecruitmentRequirement $requirement,
        UpdateRequirementAction $action,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $requirement->company_id === $companyId, 404);

        $userId = (int) $request->user()->id;

        $action->execute(
            $requirement,
            $userId,
            $request->validated(),
            $request->file('attachment'),
        );

        return redirect()->route('organization.recruitment.requirements.show', $requirement)
            ->with('success', "Requirement {$requirement->requirement_number} updated successfully.");
    }
}
