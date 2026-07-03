<?php

namespace App\Support\Employees;

use App\Models\EmployeeProfileTemplate;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class EmployeeImportTemplateExporter
{
    public const IMPORT_SHEET_NAME = 'Import';

    public const OPTIONS_SHEET_NAME = 'Options';

    public const INSTRUCTIONS_SHEET_NAME = 'How to fill';

    private const DATA_START_ROW = 2;

    private const DATA_END_ROW = 501;

    /**
     * @var list<string>
     */
    private const LOOKUP_HEADERS = [
        'branch',
        'department',
        'position',
        'project',
        'gender',
        'religion',
        'nationality',
        'marital_status',
        'status',
    ];

    /**
     * @param  list<string>  $headers
     * @return array{path: string, filename: string}
     */
    public function export(int $companyId, array $headers, ?EmployeeProfileTemplate $template = null): array
    {
        $options = EmployeeImportTemplateOptions::forCompany($companyId);
        $spreadsheet = new Spreadsheet;
        $importSheet = $spreadsheet->getActiveSheet();
        $importSheet->setTitle(self::IMPORT_SHEET_NAME);

        foreach ($headers as $columnIndex => $header) {
            $importSheet->setCellValueByColumnAndRow($columnIndex + 1, 1, $header);
        }

        $sampleRow = $this->buildSampleRow($headers);
        foreach ($sampleRow as $columnIndex => $value) {
            if ($value !== '') {
                $importSheet->setCellValueByColumnAndRow($columnIndex + 1, self::DATA_START_ROW, $value);
            }
        }

        $optionsSheet = $spreadsheet->createSheet();
        $optionsSheet->setTitle(self::OPTIONS_SHEET_NAME);

        $optionRanges = $this->writeOptionsSheet($optionsSheet, $headers, $options);
        $this->applyListValidations($importSheet, $headers, $optionRanges);
        $this->applyInvalidValueHighlighting($importSheet, $headers, $optionRanges);
        $this->applyImportSheetFormatting($importSheet, count($headers));

        $emptyOptionNotes = $this->emptyOptionNotes($headers, $options);
        $this->addInstructionsSheet($spreadsheet, $emptyOptionNotes, $template);

        $optionsSheet->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        $tempPath = tempnam(sys_get_temp_dir(), 'employee-import-template-');

        if ($tempPath === false) {
            throw new \RuntimeException('Unable to create temporary employee import template file.');
        }

        $xlsxPath = $tempPath.'.xlsx';
        rename($tempPath, $xlsxPath);

        (new Xlsx($spreadsheet))->save($xlsxPath);

        return [
            'path' => $xlsxPath,
            'filename' => $this->buildFilename($template),
        ];
    }

    /**
     * @param  list<string>  $headers
     * @return list<string>
     */
    private function buildSampleRow(array $headers): array
    {
        $sampleMap = [
            'employee_no' => 'EMP-001',
            'name' => 'John Doe',
            'work_email' => 'john.doe@example.com',
            'phone' => '+971500000000',
            'date_of_birth' => '1990-01-15',
            'hire_date' => now()->format('Y-m-d'),
            'marital_status' => 'single',
            'status' => 'active',
        ];

        return array_map(
            fn (string $header) => $sampleMap[$header] ?? '',
            $headers,
        );
    }

    /**
     * @param  list<string>  $headers
     * @param  array<string, list<string>>  $options
     * @return array<string, string> import field => Excel range on Options sheet
     */
    private function writeOptionsSheet(Worksheet $sheet, array $headers, array $options): array
    {
        $optionColumnIndex = 1;
        $ranges = [];

        foreach ($headers as $header) {
            if (! $this->isLookupHeader($header)) {
                continue;
            }

            $values = $options[$header] ?? [];

            if ($values === []) {
                continue;
            }

            $columnLetter = Coordinate::stringFromColumnIndex($optionColumnIndex);
            $sheet->setCellValue("{$columnLetter}1", $header);

            foreach ($values as $rowIndex => $value) {
                $sheet->setCellValue("{$columnLetter}".($rowIndex + 2), $value);
            }

            $lastRow = count($values) + 1;
            $ranges[$header] = sprintf(
                "'%s'!\$%s\$2:\$%s\$%d",
                self::OPTIONS_SHEET_NAME,
                $columnLetter,
                $columnLetter,
                $lastRow,
            );

            $optionColumnIndex++;
        }

        return $ranges;
    }

    /**
     * @param  list<string>  $headers
     * @param  array<string, string>  $optionRanges
     */
    private function applyListValidations(Worksheet $sheet, array $headers, array $optionRanges): void
    {
        foreach ($headers as $columnIndex => $header) {
            $formula = $this->validationFormula($header, $optionRanges);

            if ($formula === null) {
                continue;
            }

            $columnLetter = Coordinate::stringFromColumnIndex($columnIndex + 1);

            for ($row = self::DATA_START_ROW; $row <= self::DATA_END_ROW; $row++) {
                $validation = new DataValidation;
                $validation->setType(DataValidation::TYPE_LIST);
                $validation->setErrorStyle(DataValidation::STYLE_INFORMATION);
                $validation->setAllowBlank(true);
                $validation->setShowDropDown(true);
                $validation->setShowInputMessage(true);
                $validation->setPromptTitle('Lookup column');
                $validation->setPrompt('Pick from the list or paste values. Invalid values are highlighted in red.');
                $validation->setShowErrorMessage(false);
                $validation->setFormula1($formula);

                $sheet->getCell("{$columnLetter}{$row}")->setDataValidation($validation);
            }
        }
    }

    /**
     * @param  list<string>  $headers
     * @param  array<string, string>  $optionRanges
     */
    private function applyInvalidValueHighlighting(Worksheet $sheet, array $headers, array $optionRanges): void
    {
        foreach ($headers as $columnIndex => $header) {
            $optionRange = $optionRanges[$header] ?? null;

            if ($optionRange === null) {
                continue;
            }

            $columnLetter = Coordinate::stringFromColumnIndex($columnIndex + 1);
            $cellRange = sprintf(
                '%s%d:%s%d',
                $columnLetter,
                self::DATA_START_ROW,
                $columnLetter,
                self::DATA_END_ROW,
            );
            $topLeftCell = "{$columnLetter}".self::DATA_START_ROW;

            $conditional = new Conditional;
            $conditional->setConditionType(Conditional::CONDITION_EXPRESSION);
            $conditional->addCondition(sprintf(
                '=AND(%s<>"",COUNTIF(%s,%s)=0)',
                $topLeftCell,
                $optionRange,
                $topLeftCell,
            ));
            $conditional->getStyle()->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()
                ->setRGB('FFC7CE');
            $conditional->getStyle()->getFont()
                ->getColor()
                ->setRGB('9C0006');

            $sheet->getStyle($cellRange)->setConditionalStyles([$conditional]);
        }
    }

    /**
     * @param  array<string, string>  $optionRanges
     */
    private function validationFormula(string $header, array $optionRanges): ?string
    {
        return $optionRanges[$header] ?? null;
    }

    private function isLookupHeader(string $header): bool
    {
        return in_array($header, self::LOOKUP_HEADERS, true);
    }

    private function applyImportSheetFormatting(Worksheet $sheet, int $columnCount): void
    {
        if ($columnCount === 0) {
            return;
        }

        $lastColumn = Coordinate::stringFromColumnIndex($columnCount);
        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:{$lastColumn}1");
        $sheet->getRowDimension(1)->setRowHeight(24);

        for ($columnIndex = 1; $columnIndex <= $columnCount; $columnIndex++) {
            $columnLetter = Coordinate::stringFromColumnIndex($columnIndex);
            $sheet->getColumnDimension($columnLetter)->setWidth(18);
        }

        $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray([
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
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '475569'],
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'CBD5E1'],
                ],
            ],
        ]);
    }

    /**
     * @param  list<string>  $emptyOptionNotes
     */
    private function addInstructionsSheet(
        Spreadsheet $spreadsheet,
        array $emptyOptionNotes,
        ?EmployeeProfileTemplate $template,
    ): void {
        $instructions = $spreadsheet->createSheet();
        $instructions->setTitle(self::INSTRUCTIONS_SHEET_NAME);

        $lines = [
            ['Employee import — quick guide'],
            [''],
            ['1. Open the "'.self::IMPORT_SHEET_NAME.'" tab and fill employee rows below the sample row.'],
            ['2. Use dropdowns or paste copied data into lookup columns. Red cells contain values not found in your master data — fix them before upload.'],
            ['3. Required columns depend on your onboarding template; employee no and name are always required.'],
            ['4. Dates use YYYY-MM-DD (for example 1990-01-15).'],
            ['5. Save this file as .xlsx and upload it on the import page.'],
        ];

        if ($template !== null) {
            $lines[] = [''];
            $lines[] = ['Onboarding template: '.$template->name];
        }

        if ($emptyOptionNotes !== []) {
            $lines[] = [''];
            $lines[] = ['Some dropdown lists are empty in your company — add master data first:'];
            foreach ($emptyOptionNotes as $note) {
                $lines[] = [$note];
            }
        }

        foreach ($lines as $index => $line) {
            $instructions->setCellValue('A'.($index + 1), $line[0]);
        }

        $instructions->getColumnDimension('A')->setWidth(80);
        $instructions->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $instructions->getStyle('A3:A7')->getFont()->setSize(11);
    }

    /**
     * @param  list<string>  $headers
     * @param  array<string, list<string>>  $options
     * @return list<string>
     */
    private function emptyOptionNotes(array $headers, array $options): array
    {
        $labels = [
            'branch' => 'branches',
            'department' => 'departments',
            'position' => 'positions',
            'project' => 'projects',
            'gender' => 'genders',
            'religion' => 'religions',
            'nationality' => 'countries',
        ];

        $notes = [];

        foreach ($headers as $header) {
            if (! isset($labels[$header])) {
                continue;
            }

            if (($options[$header] ?? []) === []) {
                $notes[] = '- '.$labels[$header];
            }
        }

        return $notes;
    }

    private function buildFilename(?EmployeeProfileTemplate $template): string
    {
        if ($template === null) {
            return 'employees-import-template.xlsx';
        }

        $slug = str($template->name)->slug();

        return "employees-import-{$slug}-{$template->id}.xlsx";
    }
}
