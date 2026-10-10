<?php

namespace App\Http\Controllers\Organization\Recruitment;

use App\Enums\Recruitment\CandidateStage;
use App\Exports\RecruitmentReportExport;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Company;
use App\Models\Position;
use App\Models\Project;
use App\Models\RecruitmentRequirement;
use App\Models\User;
use App\Support\Pagination\ResolvesPerPage;
use App\Support\Reports\Recruitment\RecruitmentReportFilters;
use App\Support\Reports\Recruitment\RecruitmentReportPresenter;
use App\Support\Reports\Recruitment\RecruitmentReportQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class RecruitmentReportController extends Controller
{
    use ResolvesPerPage;

    public function index(Request $request): InertiaResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->can('reports.recruitment.view'), 403);

        $filters = RecruitmentReportFilters::fromRequest($request);
        $timezone = $this->companyTimezone($companyId);

        $reportQuery = new RecruitmentReportQuery($companyId, $filters, $timezone, $user);
        $perPage = $this->resolvePerPage($request, default: 25, allowed: [25, 50, 100]);
        $paginator = $reportQuery->paginate($perPage);

        $items = $paginator->through(
            fn ($candidate) => RecruitmentReportPresenter::toArray($candidate, $timezone, $user),
        );

        $summary = $reportQuery->summary();

        return Inertia::render('organization/recruitment/reports/index', [
            'candidates' => $items->items(),
            'pagination' => $this->paginationMeta($paginator),
            'summary' => $summary,
            'filters' => $filters->toArray(),
            'filter_options' => [
                'requirements' => fn () => RecruitmentRequirement::query()
                    ->where('company_id', $companyId)
                    ->orderByDesc('id')
                    ->get(['id', 'requirement_number'])
                    ->map(fn (RecruitmentRequirement $r) => [
                        'value' => (string) $r->id,
                        'label' => $r->requirement_number,
                    ])
                    ->all(),
                'clients' => fn () => Client::query()
                    ->where('company_id', $companyId)
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (Client $c) => [
                        'value' => (string) $c->id,
                        'label' => $c->name,
                    ])
                    ->all(),
                'projects' => fn () => Project::query()
                    ->where('is_active', true)
                    ->orderBy('title')
                    ->get(['id', 'title'])
                    ->map(fn (Project $p) => [
                        'value' => (string) $p->id,
                        'label' => $p->title,
                    ])
                    ->all(),
                'positions' => fn () => Position::query()
                    ->where('company_id', $companyId)
                    ->orderBy('title')
                    ->get(['id', 'title'])
                    ->map(fn (Position $p) => [
                        'value' => (string) $p->id,
                        'label' => $p->title,
                    ])
                    ->all(),
                'recruiters' => fn () => User::query()
                    ->where('company_id', $companyId)
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (User $u) => [
                        'value' => (string) $u->id,
                        'label' => $u->name,
                    ])
                    ->all(),
                'stages' => collect(CandidateStage::cases())->map(fn (CandidateStage $stage) => [
                    'value' => $stage->value,
                    'label' => $stage->label(),
                ])->all(),
                'conversion_statuses' => [
                    ['value' => 'all', 'label' => 'All Conversion Statuses'],
                    ['value' => 'converted', 'label' => 'Converted to Employee'],
                    ['value' => 'pending', 'label' => 'Pending Conversion (Joined)'],
                    ['value' => 'unconverted', 'label' => 'Unconverted'],
                ],
            ],
            'can' => [
                'view' => $user->can('reports.recruitment.view'),
                'export' => $user->can('reports.recruitment.export'),
                'view_candidates' => $user->can('recruitment.candidates.view'),
                'view_employees' => $user->can('employees.view'),
            ],
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->can('reports.recruitment.export'), 403);

        $filters = RecruitmentReportFilters::fromRequest($request);
        $timezone = $this->companyTimezone($companyId);
        $reportQuery = new RecruitmentReportQuery($companyId, $filters, $timezone, $user);

        $export = RecruitmentReportExport::forQuery($reportQuery->exportQuery(), $timezone, $user);
        $filename = 'recruitment-report-'.now()->toDateString();
        $format = strtolower((string) $request->query('format', 'xlsx'));

        if ($format === 'csv') {
            return Excel::download($export, "{$filename}.csv", ExcelWriter::CSV, [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        }

        return Excel::download($export, "{$filename}.xlsx", ExcelWriter::XLSX);
    }

    private function companyTimezone(int $companyId): string
    {
        return (string) (Company::query()->whereKey($companyId)->value('timezone') ?? config('app.timezone', 'UTC'));
    }
}
