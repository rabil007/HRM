<?php

namespace App\Support\Payroll\Services;

use App\Enums\PayrollCategory;
use App\Imports\CrewTimesheetsImport;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Support\Payroll\PayrollEmployeeQuery;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class CrewTimesheetTemplateExporter
{
    private const INSTRUCTIONS_SHEET_NAME = 'How to fill';

    /** Excel text format — keeps typed DD-MM-YYYY literal (avoids locale date parsing). */
    public const DATE_FORMAT = NumberFormat::FORMAT_TEXT;

    /**
     * @return array{path: string, filename: string}
     */
    public function export(int $companyId, PayrollPeriod $period): array
    {
        $employees = PayrollEmployeeQuery::activeQuery($companyId, PayrollCategory::Crew)
            ->with(['department.parent:id,name', 'position:id,title'])
            ->get()
            ->sortBy([
                fn (Employee $employee) => mb_strtolower($this->divisionName($employee)),
                fn (Employee $employee) => mb_strtolower($this->departmentName($employee)),
                fn (Employee $employee) => mb_strtolower($employee->name),
            ])
            ->values();

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(CrewTimesheetsImport::SHEET_NAME);

        $headers = [
            'Employee No',
            'Employee Name',
            'Division',
            'Department',
            'Position',
            'Standby From',
            'Standby To',
            'Onsite From',
            'Onsite To',
            'Overtime Hours',
        ];

        foreach ($headers as $columnIndex => $header) {
            $sheet->setCellValueByColumnAndRow($columnIndex + 1, 1, $header);
        }

        $rowNumber = CrewTimesheetsImport::DATA_START_ROW;

        foreach ($employees as $employee) {
            /** @var Employee $employee */
            $sheet->setCellValueExplicitByColumnAndRow(
                1,
                $rowNumber,
                (string) ($employee->employee_no ?? ''),
                DataType::TYPE_STRING,
            );
            $sheet->setCellValueByColumnAndRow(2, $rowNumber, $employee->name);
            $sheet->setCellValueByColumnAndRow(3, $rowNumber, $this->divisionName($employee));
            $sheet->setCellValueByColumnAndRow(4, $rowNumber, $this->departmentName($employee));
            $sheet->setCellValueByColumnAndRow(5, $rowNumber, (string) ($employee->position?->title ?? '—'));
            $rowNumber++;
        }

        $lastDataRow = max($rowNumber - 1, CrewTimesheetsImport::DATA_START_ROW);
        $this->applyWorksheetFormatting($sheet, $lastDataRow);
        $this->applyDateColumnValidation($sheet, $lastDataRow);
        $this->addInstructionsSheet($spreadsheet, $period);

        $tempPath = tempnam(sys_get_temp_dir(), 'crew-timesheet-template-');

        if ($tempPath === false) {
            throw new \RuntimeException('Unable to create temporary crew timesheet template file.');
        }

        $xlsxPath = $tempPath.'.xlsx';
        rename($tempPath, $xlsxPath);

        (new Xlsx($spreadsheet))->save($xlsxPath);

        $slug = str($period->name ?? 'period-'.$period->id)->slug();

        return [
            'path' => $xlsxPath,
            'filename' => "crew-timesheet-{$slug}.xlsx",
        ];
    }

    private function applyWorksheetFormatting(Worksheet $sheet, int $lastDataRow): void
    {
        $sheet->freezePane('F2');
        $sheet->setAutoFilter("A1:J{$lastDataRow}");

        $columnWidths = [
            'A' => 14,
            'B' => 30,
            'C' => 18,
            'D' => 18,
            'E' => 22,
            'F' => 16,
            'G' => 16,
            'H' => 16,
            'I' => 16,
            'J' => 16,
        ];

        foreach ($columnWidths as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        $sheet->getRowDimension(1)->setRowHeight(28);

        $headerStyle = [
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
                'size' => 11,
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'CBD5E1'],
                ],
            ],
        ];

        $sheet->getStyle('A1:E1')->applyFromArray(array_merge($headerStyle, [
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '475569'],
            ],
        ]));

        $sheet->getStyle('F1:G1')->applyFromArray(array_merge($headerStyle, [
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'B45309'],
            ],
        ]));

        $sheet->getStyle('H1:I1')->applyFromArray(array_merge($headerStyle, [
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '1D4ED8'],
            ],
        ]));

        $sheet->getStyle('J1')->applyFromArray(array_merge($headerStyle, [
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'B45309'],
            ],
        ]));

        if ($lastDataRow < CrewTimesheetsImport::DATA_START_ROW) {
            return;
        }

        $dataRange = 'A'.CrewTimesheetsImport::DATA_START_ROW.":J{$lastDataRow}";

        $sheet->getStyle($dataRange)->applyFromArray([
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'E2E8F0'],
                ],
            ],
        ]);

        $sheet->getStyle('A'.CrewTimesheetsImport::DATA_START_ROW.":E{$lastDataRow}")->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'F8FAFC'],
            ],
        ]);

        $sheet->getStyle('F'.CrewTimesheetsImport::DATA_START_ROW.":I{$lastDataRow}")->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'FFFBEB'],
            ],
        ]);

        $sheet->getStyle('J'.CrewTimesheetsImport::DATA_START_ROW.":J{$lastDataRow}")->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'FFF7ED'],
            ],
        ]);

        foreach (['F', 'G', 'H', 'I'] as $dateColumn) {
            $sheet->getStyle("{$dateColumn}".CrewTimesheetsImport::DATA_START_ROW.":{$dateColumn}{$lastDataRow}")
                ->getNumberFormat()
                ->setFormatCode(self::DATE_FORMAT);
        }

        $sheet->getStyle('J'.CrewTimesheetsImport::DATA_START_ROW.":J{$lastDataRow}")
            ->getNumberFormat()
            ->setFormatCode(NumberFormat::FORMAT_NUMBER_00);

        $sheet->getStyle('A'.CrewTimesheetsImport::DATA_START_ROW.":A{$lastDataRow}")
            ->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_LEFT);
    }

    private function applyDateColumnValidation(Worksheet $sheet, int $lastDataRow): void
    {
        if ($lastDataRow < CrewTimesheetsImport::DATA_START_ROW) {
            return;
        }

        for ($row = CrewTimesheetsImport::DATA_START_ROW; $row <= $lastDataRow; $row++) {
            foreach (['F', 'G', 'H', 'I'] as $column) {
                $validation = new DataValidation;
                $validation->setType(DataValidation::TYPE_CUSTOM);
                $validation->setAllowBlank(true);
                $validation->setShowInputMessage(true);
                $validation->setPromptTitle('DD-MM-YYYY');
                $validation->setPrompt('Type the date as text: 01-07-2026 (= 1 July 2026). Day first, then month. Do not use the date picker.');
                $validation->setFormula1('TRUE');

                $sheet->getCell("{$column}{$row}")->setDataValidation($validation);
            }
        }
    }

    private function addInstructionsSheet(Spreadsheet $spreadsheet, PayrollPeriod $period): void
    {
        $instructions = $spreadsheet->createSheet();
        $instructions->setTitle(self::INSTRUCTIONS_SHEET_NAME);

        $lines = [
            ['Crew timesheet import — quick guide'],
            [''],
            ['1. Open the "'.CrewTimesheetsImport::SHEET_NAME.'" tab.'],
            ['2. Use the header filters (▼) to narrow by Division or Department.'],
            ['3. Fill the yellow date columns — days are calculated automatically on import.'],
            ['4. Fill the orange Overtime Hours column when the employee worked overtime. Leave blank when there is no OT.'],
            ['5. Gray columns are pre-filled — do not change Employee No.'],
            ['6. Type dates as DD-MM-YYYY text (e.g. 01-07-2026 = 1 July 2026). Do not use the date picker — Excel may swap day and month.'],
            ['7. Leave a row blank if the employee had no standby or onsite days.'],
            ['8. Save and upload this file back to payroll.'],
            [''],
            ['Period: '.($period->name ?? 'Payroll period #'.$period->id)],
        ];

        foreach ($lines as $index => $line) {
            $instructions->setCellValue('A'.($index + 1), $line[0]);
        }

        $instructions->getColumnDimension('A')->setWidth(72);
        $instructions->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $instructions->getStyle('A3:A10')->getFont()->setSize(11);
        $instructions->getStyle('A12')->getFont()->setItalic(true)->setSize(10);
    }

    private function divisionName(Employee $employee): string
    {
        $department = $employee->department;
        $parentName = $department?->parent?->name;

        if (filled($parentName)) {
            return (string) $parentName;
        }

        if (filled($department?->name)) {
            return (string) $department->name;
        }

        return '—';
    }

    private function departmentName(Employee $employee): string
    {
        $department = $employee->department;

        if (filled($department?->parent?->name) && filled($department?->name)) {
            return (string) $department->name;
        }

        return '—';
    }
}
