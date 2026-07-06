<?php

namespace App\Support\Contracts;

use App\Enums\PayrollCategory;
use App\Imports\ContractsImport;
use App\Models\Employee;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class ContractImportTemplateExporter
{
    public const DATE_FORMAT = NumberFormat::FORMAT_TEXT;

    public function __construct(
        private readonly ContractsImport $import,
    ) {}

    /**
     * @return array{path: string, filename: string}
     */
    public function export(int $companyId, PayrollCategory $payrollCategory): array
    {
        $employees = Employee::query()
            ->where('employees.company_id', $companyId)
            ->where('employees.status', 'active')
            ->tap(fn ($query) => ContractWorkforceDepartmentScope::apply(
                $query,
                $companyId,
                $payrollCategory->value,
            ))
            ->with([
                'contracts' => fn ($query) => $query
                    ->where('status', 'active')
                    ->where('payroll_category', $payrollCategory->value),
            ])
            ->orderBy('employees.name')
            ->get();

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($this->import->sheetName($payrollCategory));

        foreach ($this->import->headers($payrollCategory) as $columnIndex => $header) {
            $sheet->setCellValueByColumnAndRow($columnIndex + 1, 1, $header);
        }

        $rowNumber = ContractsImport::DATA_START_ROW;

        foreach ($employees as $employee) {
            /** @var Employee $employee */
            $contract = $employee->contracts->first();

            $this->setStringCell($sheet, 1, $rowNumber, (string) ($employee->employee_no ?? ''));
            $sheet->setCellValueByColumnAndRow(2, $rowNumber, $employee->name);
            $this->setStringCell($sheet, 3, $rowNumber, $contract?->start_date?->toDateString());
            $this->setStringCell($sheet, 4, $rowNumber, $contract?->end_date?->toDateString());
            $this->setStringCell($sheet, 5, $rowNumber, $contract?->labor_contract_id);
            $sheet->setCellValueByColumnAndRow(6, $rowNumber, $contract?->status);
            $sheet->setCellValueByColumnAndRow(7, $rowNumber, $contract?->basic_salary);

            if ($payrollCategory === PayrollCategory::Office) {
                $sheet->setCellValueByColumnAndRow(8, $rowNumber, $contract?->housing_allowance);
                $sheet->setCellValueByColumnAndRow(9, $rowNumber, $contract?->transport_allowance);
                $sheet->setCellValueByColumnAndRow(10, $rowNumber, $contract?->other_allowances);
                $sheet->setCellValueByColumnAndRow(11, $rowNumber, $contract?->note);
            } else {
                $sheet->setCellValueByColumnAndRow(8, $rowNumber, $contract?->supplementary_allowance);
                $sheet->setCellValueByColumnAndRow(9, $rowNumber, $contract?->site_allowance);
                $sheet->setCellValueByColumnAndRow(10, $rowNumber, $contract?->note);
            }

            $rowNumber++;
        }

        $lastColumn = $payrollCategory === PayrollCategory::Office ? 'K' : 'J';
        $lastDataRow = max($rowNumber - 1, ContractsImport::DATA_START_ROW);
        $sheet->setAutoFilter("A1:{$lastColumn}{$lastDataRow}");
        $sheet->freezePane('A2');

        foreach (['C', 'D'] as $column) {
            $sheet->getStyle("{$column}2:{$column}{$lastDataRow}")
                ->getNumberFormat()
                ->setFormatCode(self::DATE_FORMAT);
        }

        $filename = $payrollCategory === PayrollCategory::Office
            ? 'office-contracts-template.xlsx'
            : 'crew-contracts-template.xlsx';

        $path = storage_path('app/temp/'.uniqid('contracts-template-', true).'.xlsx');
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
