<?php

namespace App\Support\SeaServices;

use App\Imports\SeaServicesImport;
use App\Models\Employee;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class SeaServiceImportTemplateExporter
{
    public const TEXT_FORMAT = NumberFormat::FORMAT_TEXT;

    public function __construct(
        private readonly SeaServicesImport $import,
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

        $rowNumber = SeaServicesImport::DATA_START_ROW;

        foreach ($employees as $employee) {
            $this->setStringCell($sheet, 1, $rowNumber, (string) ($employee->employee_no ?? ''));
            $sheet->setCellValueByColumnAndRow(2, $rowNumber, $employee->name);
            $rowNumber++;
        }

        $lastColumn = 'H';
        $lastDataRow = max($rowNumber - 1, SeaServicesImport::DATA_START_ROW);
        $sheet->setAutoFilter("A1:{$lastColumn}{$lastDataRow}");
        $sheet->freezePane('A2');

        $sheet->getStyle("A2:A{$lastDataRow}")
            ->getNumberFormat()
            ->setFormatCode(self::TEXT_FORMAT);

        foreach (['F', 'G'] as $column) {
            $sheet->getStyle("{$column}2:{$column}{$lastDataRow}")
                ->getNumberFormat()
                ->setFormatCode('yyyy-mm-dd');
        }

        $filename = 'sea-services-template.xlsx';

        $path = storage_path('app/temp/'.uniqid('sea-services-template-', true).'.xlsx');
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
