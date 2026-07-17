<?php

namespace App\Http\Controllers\Organization;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Exports\CrewMovementHistoryExport;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Company;
use App\Models\CompanyVisaType;
use App\Models\CrewAssignment;
use App\Models\Rank;
use App\Models\Vessel;
use App\Support\Pagination\ResolvesPerPage;
use App\Support\Reports\CrewMovementHistoryFilters;
use App\Support\Reports\CrewMovementHistoryPagePermissions;
use App\Support\Reports\CrewMovementHistoryQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;

class CrewMovementHistoryController extends Controller
{
    use ResolvesPerPage;

    public function index(Request $request)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $filters = CrewMovementHistoryFilters::fromRequest($request);
        $timezone = $this->companyTimezone($companyId);
        $query = new CrewMovementHistoryQuery($companyId, $filters, $timezone);
        $paginator = $query->paginate($this->resolvePerPage($request, default: 25, allowed: [25, 50, 100]));

        return Inertia::render('organization/reports/crew-movement-history/index', [
            'assignments' => $paginator->items(),
            'pagination' => $this->paginationMeta($paginator),
            'summary' => $query->summary(),
            'filters' => $filters->toArray(),
            'filter_options' => [
                'statuses' => collect(CrewAssignmentStatus::cases())
                    ->map(fn (CrewAssignmentStatus $status) => ['value' => $status->value, 'label' => $status->label()])
                    ->all(),
                'phases' => collect(CrewPhaseCode::cases())
                    ->map(fn (CrewPhaseCode $phase) => ['value' => $phase->value, 'label' => $phase->label()])
                    ->all(),
                'vessels' => $this->activeOptions(Vessel::query()),
                'ranks' => $this->activeOptions(Rank::query()),
                'clients' => $this->activeOptions(Client::query()),
                'visa_types' => $this->activeOptions(CompanyVisaType::query()),
                'sources' => CrewAssignment::query()
                    ->where('company_id', $companyId)
                    ->whereNotNull('source')
                    ->distinct()
                    ->orderBy('source')
                    ->pluck('source')
                    ->map(fn (string $source) => [
                        'value' => $source,
                        'label' => str($source)->replace('_', ' ')->title()->toString(),
                    ])
                    ->values()
                    ->all(),
            ],
            'can' => CrewMovementHistoryPagePermissions::for($request->user()),
        ]);
    }

    public function export(Request $request)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $filters = CrewMovementHistoryFilters::fromRequest($request);
        $query = new CrewMovementHistoryQuery($companyId, $filters, $this->companyTimezone($companyId));
        $export = new CrewMovementHistoryExport($query->exportQuery());
        $filename = 'crew-movement-history-'.now()->toDateString();
        $format = strtolower((string) $request->query('format', 'xlsx'));

        if ($format === 'csv') {
            return Excel::download($export, "{$filename}.csv", ExcelWriter::CSV, [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        }

        return Excel::download($export, "{$filename}.xlsx", ExcelWriter::XLSX);
    }

    /**
     * @param  Builder<Model>  $query
     * @return list<array{id: int, name: string}>
     */
    private function activeOptions(Builder $query): array
    {
        return $query
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($option) => ['id' => (int) $option->id, 'name' => (string) $option->name])
            ->values()
            ->all();
    }

    private function companyTimezone(int $companyId): string
    {
        return (string) (Company::query()->whereKey($companyId)->value('timezone') ?? config('app.timezone', 'UTC'));
    }
}
