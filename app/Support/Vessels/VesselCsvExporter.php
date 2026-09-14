<?php

namespace App\Support\Vessels;

use App\Imports\VesselsImport;
use App\Models\Vessel;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class VesselCsvExporter
{
    public function download(int $companyId): StreamedResponse
    {
        return $this->stream($companyId, 'vessels-export-'.now()->format('Y-m-d_His').'.csv');
    }

    public function template(int $companyId): StreamedResponse
    {
        return $this->stream($companyId, 'vessels.csv');
    }

    private function stream(int $companyId, string $filename): StreamedResponse
    {
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
                    fputcsv($handle, $this->row($vessel));
                });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return list<string|int>
     */
    private function row(Vessel $vessel): array
    {
        return [
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
        ];
    }
}
