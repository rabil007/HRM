<?php

namespace App\Support\Vessels;

use App\Imports\VesselsImport;
use App\Models\Vessel;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class VesselCsvExporter
{
    public function download(int $companyId): StreamedResponse
    {
        $filename = 'vessels-export-'.now()->format('Y-m-d_His').'.csv';

        return response()->streamDownload(function () use ($companyId): void {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            fputcsv($handle, VesselsImport::templateHeaders());

            Vessel::query()
                ->where('company_id', $companyId)
                ->with(['client:id,name', 'vesselType:id,name'])
                ->orderBy('name')
                ->orderBy('id')
                ->cursor()
                ->each(function (Vessel $vessel) use ($handle): void {
                    fputcsv($handle, [
                        $vessel->id,
                        $vessel->client?->name ?? '',
                        $vessel->name,
                        $vessel->vesselType?->name ?? '',
                        $vessel->imo_no ?? '',
                        $vessel->official_no ?? '',
                        $vessel->call_sign ?? '',
                        $vessel->grt !== null ? (string) $vessel->grt : '',
                        $vessel->bhp !== null ? (string) $vessel->bhp : '',
                        $vessel->is_active ? 'yes' : 'no',
                    ]);
                });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function template(): Response
    {
        $csv = implode(',', VesselsImport::templateHeaders())."\n"
            .",ADNOC,Sea Eagle,AHTS,9559133,SLR11116,9LS2029,4500,12000,yes\n";

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="vessels-import-template.csv"',
        ]);
    }
}
