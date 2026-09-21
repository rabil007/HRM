<?php

namespace App\Support\CrewMovements\Historical;

use App\Models\HistoricalCrewImportBatch;
use App\Models\HistoricalCrewImportRow;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class HistoricalCrewImportResultExporter
{
    /**
     * @return array{path: string, filename: string}
     */
    public function export(HistoricalCrewImportBatch $batch): array
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Import Result');

        $headers = [
            'Row',
            'Employee No',
            'Employee',
            'Vessel',
            'Rank',
            'Last Movement',
            'Inferred State',
            'Import Status',
            'Warnings',
            'Errors',
            'Assignment No',
        ];

        foreach ($headers as $index => $header) {
            $sheet->setCellValueByColumnAndRow($index + 1, 1, $header);
            $sheet->getStyleByColumnAndRow($index + 1, 1)->getFont()->setBold(true);
        }

        $batch->loadMissing(['rows.assignment.currentPhase', 'rows.assignment.phases']);

        $rowNumber = 2;

        foreach ($batch->rows->sortBy('row_number') as $row) {
            /** @var HistoricalCrewImportRow $row */
            [$lastMovement, $inferredState] = $this->movementLabels($row);

            $values = [
                (string) $row->row_number,
                (string) ($row->employee_no ?? ''),
                (string) ($row->employee_name ?? ''),
                (string) ($row->vessel_name ?? ''),
                (string) ($row->rank_name ?? ''),
                $lastMovement,
                $inferredState,
                $row->status->label(),
                implode('; ', $row->warnings ?? []),
                implode('; ', $row->errors ?? []),
                (string) ($row->assignment_no ?? ''),
            ];

            foreach ($values as $columnIndex => $value) {
                $sheet->setCellValueExplicitByColumnAndRow(
                    $columnIndex + 1,
                    $rowNumber,
                    HistoricalSpreadsheetSafeString::forExport($value),
                    DataType::TYPE_STRING,
                );
            }

            $rowNumber++;
        }

        foreach (range(1, count($headers)) as $column) {
            $sheet->getColumnDimensionByColumn($column)->setWidth(22);
        }

        $sheet->freezePane('A2');

        $path = sys_get_temp_dir().'/'.uniqid('historical-crew-import-result-', true).'.xlsx';

        (new Xlsx($spreadsheet))->save($path);

        return [
            'path' => $path,
            'filename' => "Historical_Import_{$batch->batch_no}_Result.xlsx",
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function movementLabels(HistoricalCrewImportRow $row): array
    {
        $assignment = $row->assignment;

        if ($assignment === null) {
            return ['', ''];
        }

        $resolved = HistoricalPhaseBuilder::lastMovementFromPhases($assignment->phases);

        if ($resolved === null) {
            $inferred = $assignment->currentPhase?->phase_code?->label() ?? '';

            return ['', $inferred];
        }

        $lastMovement = $resolved['event_at'] !== null
            ? $resolved['event_label'].' — '.$resolved['event_at']->format('d M Y')
            : $resolved['event_label'];

        return [$lastMovement, $resolved['inferred_label']];
    }
}
