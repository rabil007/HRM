<?php

namespace App\Support\Payroll\Services;

use App\Enums\PayrollCategory;
use App\Imports\CrewTimesheetsImport;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Support\Payroll\PayrollEmployeeQuery;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
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
            'Standby Days',
            'Onsite From',
            'Onsite To',
            'Onsite Days',
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
        $sheet->setAutoFilter("A1:K{$lastDataRow}");

        $columnWidths = [
            'A' => 14,
            'B' => 30,
            'C' => 18,
            'D' => 18,
            'E' => 22,
            'F' => 14,
            'G' => 14,
            'H' => 14,
            'I' => 14,
            'J' => 14,
            'K' => 14,
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

        $sheet->getStyle('F1:H1')->applyFromArray(array_merge($headerStyle, [
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'B45309'],
            ],
        ]));

        $sheet->getStyle('I1:K1')->applyFromArray(array_merge($headerStyle, [
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '1D4ED8'],
            ],
        ]));

        if ($lastDataRow < CrewTimesheetsImport::DATA_START_ROW) {
            return;
        }

        $dataRange = 'A'.CrewTimesheetsImport::DATA_START_ROW.":K{$lastDataRow}";

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

        $sheet->getStyle('F'.CrewTimesheetsImport::DATA_START_ROW.":K{$lastDataRow}")->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'FFFBEB'],
            ],
        ]);

        foreach (['F', 'G', 'I', 'J'] as $dateColumn) {
            $sheet->getStyle("{$dateColumn}".CrewTimesheetsImport::DATA_START_ROW.":{$dateColumn}{$lastDataRow}")
                ->getNumberFormat()
                ->setFormatCode(NumberFormat::FORMAT_DATE_YYYYMMDD2);
        }

        foreach (['H', 'K'] as $daysColumn) {
            $sheet->getStyle("{$daysColumn}".CrewTimesheetsImport::DATA_START_ROW.":{$daysColumn}{$lastDataRow}")
                ->getNumberFormat()
                ->setFormatCode(NumberFormat::FORMAT_NUMBER_00);
            $sheet->getStyle("{$daysColumn}".CrewTimesheetsImport::DATA_START_ROW.":{$daysColumn}{$lastDataRow}")
                ->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        $sheet->getStyle('A'.CrewTimesheetsImport::DATA_START_ROW.":A{$lastDataRow}")
            ->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_LEFT);
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
            ['3. Only fill the yellow columns (Standby / Onsite dates and days).'],
            ['4. Gray columns are pre-filled — do not change Employee No.'],
            ['5. Dates: use YYYY-MM-DD (e.g. 2026-06-01) or pick from the date picker.'],
            ['6. Leave a row blank if the employee had no standby or onsite days.'],
            ['7. Save and upload this file back to payroll.'],
            [''],
            ['Period: '.($period->name ?? 'Payroll period #'.$period->id)],
        ];

        foreach ($lines as $index => $line) {
            $instructions->setCellValue('A'.($index + 1), $line[0]);
        }

        $instructions->getColumnDimension('A')->setWidth(72);
        $instructions->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $instructions->getStyle('A3:A9')->getFont()->setSize(11);
        $instructions->getStyle('A11')->getFont()->setItalic(true)->setSize(10);
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
