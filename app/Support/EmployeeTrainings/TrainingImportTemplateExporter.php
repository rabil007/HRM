<?php

namespace App\Support\EmployeeTrainings;

use App\Imports\TrainingsImport;
use App\Models\Employee;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class TrainingImportTemplateExporter
{
    public const TEXT_FORMAT = NumberFormat::FORMAT_TEXT;

    public function __construct(
        private readonly TrainingsImport $import,
    ) {}

    /**
     * @return array{path: string, filename: string}
     */
    public function export(int $companyId): array
    {
        $employees = Employee::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'employee_no', 'name']);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($this->import->sheetName());

        foreach ($this->import->headers() as $columnIndex => $header) {
            $sheet->setCellValueByColumnAndRow($columnIndex + 1, 1, $header);
        }

        $rowNumber = TrainingsImport::DATA_START_ROW;

        foreach ($employees as $employee) {
            $this->setStringCell($sheet, 1, $rowNumber, (string) ($employee->employee_no ?? ''));
            $sheet->setCellValueByColumnAndRow(2, $rowNumber, $employee->name);
            $rowNumber++;
        }

        $lastColumn = 'G';
        $lastDataRow = max($rowNumber - 1, TrainingsImport::DATA_START_ROW);
        $sheet->setAutoFilter("A1:{$lastColumn}{$lastDataRow}");
        $sheet->freezePane('A2');

        $sheet->getStyle("A2:A{$lastDataRow}")
            ->getNumberFormat()
            ->setFormatCode(self::TEXT_FORMAT);

        foreach (['D', 'E'] as $column) {
            $sheet->getStyle("{$column}2:{$column}{$lastDataRow}")
                ->getNumberFormat()
                ->setFormatCode('yyyy-mm-dd');
        }

        $filename = 'training-template.xlsx';

        $path = storage_path('app/temp/'.uniqid('training-template-', true).'.xlsx');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        (new Xlsx($spreadsheet))->save($path);

        return [
            'path' => $path,
            'filename' => $filename,
        ];
    }

    private function setStringCell(Worksheet $sheet, int $column, int $row, ?string $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $sheet->setCellValueExplicitByColumnAndRow(
            $column,
            $row,
            $value,
            DataType::TYPE_STRING,
        );
    }
}
