<?php

namespace App\Http\Controllers\Organization;

use App\Exports\CurrentCrewOnboardVesselsExport;
use App\Http\Controllers\Controller;
use App\Models\CrewAssignment;
use App\Support\CrewMovements\CurrentCrewRequestFilters;
use App\Support\CrewMovements\CurrentCrewVesselQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CurrentCrewOnboardVesselsExportController extends Controller
{
    public function __invoke(Request $request): BinaryFileResponse
    {
        Gate::authorize('viewAny', CrewAssignment::class);

        $companyId = (int) $request->attributes->get('current_company_id');
        $filters = CurrentCrewRequestFilters::fromRequest($request);
        $assignments = CurrentCrewVesselQuery::exportAssignments($companyId, $filters);
        $export = new CurrentCrewOnboardVesselsExport($assignments);
        $filename = 'current-crew-onboard-vessels-'.now()->toDateString();
        $format = strtolower((string) $request->query('format', 'xlsx'));

        if ($format === 'csv') {
            return Excel::download($export, "{$filename}.csv", ExcelWriter::CSV, [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        }

        return Excel::download($export, "{$filename}.xlsx", ExcelWriter::XLSX);
    }
}
