<?php

namespace App\Support\Payroll\Services;

use App\Enums\ContractSalaryStructure;
use App\Enums\PayrollCategory;
use App\Imports\CrewTimesheetsImport;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\SalaryInputType;
use App\Support\Payroll\CrewTimesheetImportSchema;
use App\Support\Payroll\PayrollEmployeeQuery;
use App\Support\Payroll\ResolveCrewContractForPayrollPeriod;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Style\Protection;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class CrewTimesheetTemplateExporter
{
    private const INSTRUCTIONS_SHEET_NAME = 'How to fill';

    private const OPERATIONAL_DATE_COLUMNS = ['F', 'G', 'H', 'I'];

    /** Excel text format — keeps typed DD-MM-YYYY literal (avoids locale date parsing). */
    public const DATE_FORMAT = NumberFormat::FORMAT_TEXT;

    public function __construct(
        private readonly CrewTimesheetImportSchema $schema,
        private readonly ResolveCrewContractForPayrollPeriod $resolveContract,
    ) {}

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

        $headers = $this->schema->headers($companyId);
        $salaryInputTypes = $this->schema->activeSalaryInputTypes($companyId);
        $lastColumn = $this->schema->lastColumnLetter($companyId);
        $rosterColumnCount = count(CrewTimesheetImportSchema::rosterHeaders());
        $typedSalaryStart = $rosterColumnCount + 1;
        $remarksColumnIndex = Coordinate::columnIndexFromString($lastColumn);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(CrewTimesheetsImport::SHEET_NAME);

        foreach ($headers as $columnIndex => $header) {
            $sheet->setCellValueByColumnAndRow($columnIndex + 1, 1, $header);
        }

        $usesCrewOperations = $period->usesCrewOperationsTimesheets();
        $contractsByEmployeeId = $usesCrewOperations
            ? $this->resolveContract->resolveMany(
                $period,
                $employees->map(fn (Employee $employee): int => (int) $employee->id)->all(),
            )
            : collect();

        $rowNumber = CrewTimesheetsImport::DATA_START_ROW;
        $dailyLockedRows = [];

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

            if ($usesCrewOperations) {
                $structure = $contractsByEmployeeId->get((int) $employee->id)?->resolvedSalaryStructure()
                    ?? ContractSalaryStructure::Daily;

                if ($structure === ContractSalaryStructure::Daily) {
                    $dailyLockedRows[] = $rowNumber;
                }
            }

            $rowNumber++;
        }

        $lastDataRow = max($rowNumber - 1, CrewTimesheetsImport::DATA_START_ROW);
        $this->applyWorksheetFormatting(
            $sheet,
            $lastDataRow,
            $lastColumn,
            $rosterColumnCount,
            $typedSalaryStart,
            $remarksColumnIndex,
            $salaryInputTypes,
        );
        $this->applyDateColumnValidation($sheet, $lastDataRow);

        if ($usesCrewOperations && $dailyLockedRows !== []) {
            $this->protectDailyOperationalCells($sheet, $dailyLockedRows, $lastColumn, $lastDataRow);
        }

        $this->addInstructionsSheet($spreadsheet, $period, $salaryInputTypes);

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

    /**
     * @param  Collection<int, SalaryInputType>  $salaryInputTypes
     */
    private function applyWorksheetFormatting(
        Worksheet $sheet,
        int $lastDataRow,
        string $lastColumn,
        int $rosterColumnCount,
        int $typedSalaryStart,
        int $remarksColumnIndex,
        $salaryInputTypes,
    ): void {
        $dateStartColumn = $this->schema->columnLetter(6);
        $sheet->freezePane("{$dateStartColumn}2");
        $sheet->setAutoFilter("A1:{$lastColumn}{$lastDataRow}");

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

        for ($columnIndex = $typedSalaryStart; $columnIndex < $remarksColumnIndex; $columnIndex++) {
            $sheet->getColumnDimension($this->schema->columnLetter($columnIndex))->setWidth(14);
        }

        $remarksColumn = $this->schema->columnLetter($remarksColumnIndex);
        $sheet->getColumnDimension($remarksColumn)->setWidth(24);

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

        $rosterEnd = $this->schema->columnLetter($rosterColumnCount);
        $sheet->getStyle("A1:{$rosterEnd}1")->applyFromArray(array_merge($headerStyle, [
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '475569'],
            ],
        ]));

        $standbyStart = $this->schema->columnLetter(6);
        $standbyEnd = $this->schema->columnLetter(7);
        $sheet->getStyle("{$standbyStart}1:{$standbyEnd}1")->applyFromArray(array_merge($headerStyle, [
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'B45309'],
            ],
        ]));

        $onsiteStart = $this->schema->columnLetter(8);
        $onsiteEnd = $this->schema->columnLetter(9);
        $sheet->getStyle("{$onsiteStart}1:{$onsiteEnd}1")->applyFromArray(array_merge($headerStyle, [
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '1D4ED8'],
            ],
        ]));

        $overtimeColumn = $this->schema->columnLetter(10);
        $sheet->getStyle("{$overtimeColumn}1")->applyFromArray(array_merge($headerStyle, [
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'B45309'],
            ],
        ]));

        $sheet->getStyle("{$remarksColumn}1")->applyFromArray(array_merge($headerStyle, [
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '64748B'],
            ],
        ]));

        $typeIndex = 0;

        foreach ($salaryInputTypes as $type) {
            $column = $this->schema->columnLetter($typedSalaryStart + $typeIndex);
            $sheet->getStyle("{$column}1")->applyFromArray(array_merge($headerStyle, [
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => $type->is_addition ? '15803D' : 'B91C1C'],
                ],
            ]));
            $typeIndex++;
        }

        if ($lastDataRow < CrewTimesheetsImport::DATA_START_ROW) {
            return;
        }

        $dataStart = CrewTimesheetsImport::DATA_START_ROW;
        $dataRange = "A{$dataStart}:{$lastColumn}{$lastDataRow}";

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

        $sheet->getStyle("A{$dataStart}:{$rosterEnd}{$lastDataRow}")->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'F8FAFC'],
            ],
        ]);

        $sheet->getStyle("{$standbyStart}{$dataStart}:{$onsiteEnd}{$lastDataRow}")->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'FFFBEB'],
            ],
        ]);

        $sheet->getStyle("{$overtimeColumn}{$dataStart}:{$overtimeColumn}{$lastDataRow}")->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'FFF7ED'],
            ],
        ]);

        foreach (['F', 'G', 'H', 'I'] as $dateColumn) {
            $sheet->getStyle("{$dateColumn}{$dataStart}:{$dateColumn}{$lastDataRow}")
                ->getNumberFormat()
                ->setFormatCode(self::DATE_FORMAT);
        }

        $numericColumns = array_merge(
            [$overtimeColumn],
            collect(range($typedSalaryStart, $remarksColumnIndex - 1))
                ->map(fn (int $index) => $this->schema->columnLetter($index))
                ->all(),
        );

        foreach ($numericColumns as $numericColumn) {
            $sheet->getStyle("{$numericColumn}{$dataStart}:{$numericColumn}{$lastDataRow}")
                ->getNumberFormat()
                ->setFormatCode(NumberFormat::FORMAT_NUMBER_00);
        }

        $sheet->getStyle("A{$dataStart}:A{$lastDataRow}")
            ->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_LEFT);
    }

    /**
     * Visibly locks Daily crew operational date cells in Crew Operations mode so
     * operators cannot type standby/onsite dates that are owned by the Applied
     * timeline. Backend import validation still rejects operational changes even
     * when the sheet protection is removed.
     *
     * @param  list<int>  $dailyLockedRows
     */
    private function protectDailyOperationalCells(
        Worksheet $sheet,
        array $dailyLockedRows,
        string $lastColumn,
        int $lastDataRow,
    ): void {
        $dataStart = CrewTimesheetsImport::DATA_START_ROW;

        $sheet->getStyle("A{$dataStart}:{$lastColumn}{$lastDataRow}")
            ->getProtection()
            ->setLocked(Protection::PROTECTION_UNPROTECTED);

        foreach ($dailyLockedRows as $row) {
            foreach (self::OPERATIONAL_DATE_COLUMNS as $column) {
                $cell = "{$column}{$row}";
                $sheet->setCellValueExplicit($cell, 'From timeline', DataType::TYPE_STRING);
                $sheet->getStyle($cell)->getProtection()->setLocked(Protection::PROTECTION_PROTECTED);
                $sheet->getStyle($cell)->applyFromArray([
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'E2E8F0'],
                    ],
                    'font' => [
                        'italic' => true,
                        'color' => ['rgb' => '94A3B8'],
                    ],
                ]);
            }
        }

        $protection = $sheet->getProtection();
        $protection->setSheet(true);
        $protection->setFormatCells(false);
        $protection->setSort(false);
        $protection->setAutoFilter(false);
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

    /**
     * @param  Collection<int, SalaryInputType>  $salaryInputTypes
     */
    private function addInstructionsSheet(Spreadsheet $spreadsheet, PayrollPeriod $period, $salaryInputTypes): void
    {
        $instructions = $spreadsheet->createSheet();
        $instructions->setTitle(self::INSTRUCTIONS_SHEET_NAME);

        $lines = [
            ['Crew timesheet import — quick guide'],
            [''],
            ['1. Open the "'.CrewTimesheetsImport::SHEET_NAME.'" tab.'],
            ['2. Use the header filters (▼) to narrow by Division or Department.'],
        ];

        if ($period->usesCrewOperationsTimesheets()) {
            $lines = array_merge($lines, [
                ['3. This period uses Crew Operations Timeline. Leave the yellow Daily operational date columns blank — sign-on standby, onsite, and sign-off standby are filled from the Applied timeline.'],
                ['4. For Daily crew, enter only Overtime Hours, salary input columns, and optional Remarks.'],
                ['5. Monthly crew employees may still use leave/standby and onsite columns in this template.'],
                ['6. Fill the orange Overtime Hours column when the employee worked overtime. Leave blank when there is no OT.'],
                ['7. Fill green salary input columns for additions (e.g. Bonus, Commission) and red columns for deductions (e.g. Loan, Late). Leave blank when not applicable.'],
                ['8. Optional Remarks column at the end for notes.'],
                ['9. Gray columns are pre-filled — do not change Employee No.'],
                ['10. Type dates as DD-MM-YYYY text (e.g. 01-07-2026 = 1 July 2026). Do not use the date picker — Excel may swap day and month.'],
                ['11. Save and upload this file back to payroll.'],
            ]);
        } else {
            $lines = array_merge($lines, [
                ['3. Fill the yellow date columns — days are calculated automatically on import.'],
                ['4. Fill the orange Overtime Hours column when the employee worked overtime. Leave blank when there is no OT.'],
                ['5. Fill green salary input columns for additions (e.g. Bonus, Commission) and red columns for deductions (e.g. Loan, Late). Leave blank when not applicable.'],
                ['6. Optional Remarks column at the end for notes.'],
                ['7. Gray columns are pre-filled — do not change Employee No.'],
                ['8. Type dates as DD-MM-YYYY text (e.g. 01-07-2026 = 1 July 2026). Do not use the date picker — Excel may swap day and month.'],
                ['9. Leave a row blank if the employee had no standby or onsite days.'],
                ['10. Save and upload this file back to payroll.'],
            ]);
        }

        $lines = array_merge($lines, [
            [''],
            ['Salary input columns in this template: '.$salaryInputTypes->pluck('name')->join(', ')],
            [''],
            ['Period: '.($period->name ?? 'Payroll period #'.$period->id)],
        ]);

        foreach ($lines as $index => $line) {
            $instructions->setCellValue('A'.($index + 1), $line[0]);
        }

        $instructions->getColumnDimension('A')->setWidth(72);
        $instructions->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $instructions->getStyle('A3:A13')->getFont()->setSize(11);
        $instructions->getStyle('A15')->getFont()->setItalic(true)->setSize(10);
        $instructions->getStyle('A17')->getFont()->setItalic(true)->setSize(10);
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
