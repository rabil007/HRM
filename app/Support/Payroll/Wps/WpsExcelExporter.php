<?php

namespace App\Support\Payroll\Wps;

use App\Models\Company;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class WpsExcelExporter
{
    /**
     * @param  Collection<int, PayrollRecord>  $records
     */
    public function export(
        Company $company,
        PayrollPeriod $period,
        Collection $records,
        string $reference,
    ): string {
        $rows = new WpsExportRows($company, $period, $records, $reference);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('WPS');

        $rowIndex = 1;

        foreach ($rows->edrRowsForExcel() as $edrRow) {
            $colIndex = 1;
            foreach ($edrRow as $cellValue) {
                $sheet->setCellValueExplicitByColumnAndRow(
                    $colIndex,
                    $rowIndex,
                    (string) $cellValue,
                    DataType::TYPE_STRING,
                );
                $colIndex++;
            }
            $rowIndex++;
        }

        $colIndex = 1;
        foreach ($rows->scrRowForExcel() as $cellValue) {
            $sheet->setCellValueExplicitByColumnAndRow(
                $colIndex,
                $rowIndex,
                (string) $cellValue,
                DataType::TYPE_STRING,
            );
            $colIndex++;
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'wps-export-');

        if ($tempPath === false) {
            throw new \RuntimeException('Unable to create temporary WPS export file.');
        }

        try {
            (new Xlsx($spreadsheet))->save($tempPath);

            $content = file_get_contents($tempPath);

            if ($content === false) {
                throw new \RuntimeException('Unable to read temporary WPS export file.');
            }

            return $content;
        } finally {
            @unlink($tempPath);
            $spreadsheet->disconnectWorksheets();
        }
    }
}
