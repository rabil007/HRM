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

    private const EMPLOYEE_BLANK_ROWS = 15;

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

        $spreadsheet = $this->newSpreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $rowNumber = SeaServicesImport::DATA_START_ROW;

        foreach ($employees as $employee) {
            $this->writeEmployeeIdentity($sheet, $rowNumber, $employee);
            $rowNumber++;
        }

        return $this->saveSpreadsheet(
            $spreadsheet,
            $rowNumber,
            'sea-services-template.xlsx',
            'sea-services-template-',
        );
    }

    /**
     * @return array{path: string, filename: string}
     */
    public function exportForEmployee(int $companyId, Employee $employee): array
    {
        abort_unless((int) $employee->company_id === $companyId, 404);

        if (blank($employee->employee_no)) {
            throw new \InvalidArgumentException(
                'This employee needs an employee number before sea services can be imported.',
            );
        }

        $spreadsheet = $this->newSpreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $rowNumber = SeaServicesImport::DATA_START_ROW;

        for ($i = 0; $i < self::EMPLOYEE_BLANK_ROWS; $i++) {
            $this->writeEmployeeIdentity($sheet, $rowNumber, $employee);
            $rowNumber++;
        }

        $safeNo = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $employee->employee_no) ?: 'employee';

        return $this->saveSpreadsheet(
            $spreadsheet,
            $rowNumber,
            "sea-services-{$safeNo}-template.xlsx",
            'sea-services-employee-template-',
        );
    }

    private function newSpreadsheet(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($this->import->sheetName());

        foreach ($this->import->headers() as $columnIndex => $header) {
            $sheet->setCellValueByColumnAndRow($columnIndex + 1, 1, $header);
        }

        return $spreadsheet;
    }

    private function writeEmployeeIdentity(Worksheet $sheet, int $rowNumber, Employee $employee): void
    {
        $this->setStringCell($sheet, 1, $rowNumber, (string) ($employee->employee_no ?? ''));
        $sheet->setCellValueByColumnAndRow(2, $rowNumber, $employee->name);
    }

    /**
     * @return array{path: string, filename: string}
     */
    private function saveSpreadsheet(
        Spreadsheet $spreadsheet,
        int $nextRowNumber,
        string $filename,
        string $tempPrefix,
    ): array {
        $sheet = $spreadsheet->getActiveSheet();
        $lastColumn = 'H';
        $lastDataRow = max($nextRowNumber - 1, SeaServicesImport::DATA_START_ROW);
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

        $path = storage_path('app/temp/'.uniqid($tempPrefix, true).'.xlsx');
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
