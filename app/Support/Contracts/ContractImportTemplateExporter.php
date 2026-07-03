<?php

namespace App\Support\Contracts;

use App\Enums\PayrollCategory;
use App\Imports\ContractsImport;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
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
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->where(function (Builder $query) use ($payrollCategory) {
                $query->whereDoesntHave('contracts', fn (Builder $contractQuery) => $contractQuery->where('status', 'active'))
                    ->orWhereHas('currentContract', fn (Builder $contractQuery) => $contractQuery->where('payroll_category', $payrollCategory->value));
            })
            ->with([
                'contracts' => fn ($query) => $query
                    ->where('status', 'active')
                    ->where('payroll_category', $payrollCategory->value),
                'currentContract',
            ])
            ->orderBy('name')
            ->get();

        // #region agent log
        $withMatchingContract = $employees->filter(fn (Employee $employee) => $employee->contracts->isNotEmpty())->count();
        $withCrewOnlyActive = $employees->filter(
            fn (Employee $employee) => $employee->contracts->isEmpty()
                && $employee->currentContract?->payroll_category?->value === PayrollCategory::Crew->value,
        )->count();
        $withOfficeOnlyActive = $employees->filter(
            fn (Employee $employee) => $employee->contracts->isEmpty()
                && $employee->currentContract?->payroll_category?->value === PayrollCategory::Office->value,
        )->count();
        $withNoActiveContract = $employees->filter(fn (Employee $employee) => $employee->currentContract === null)->count();
        @file_put_contents(
            '/Users/mohammedrabil/Herd/OMS-HRM/.cursor/debug-c45cae.log',
            json_encode([
                'sessionId' => 'c45cae',
                'hypothesisId' => 'H1-H2',
                'location' => 'ContractImportTemplateExporter.php:export',
                'message' => 'template employee breakdown',
                'data' => [
                    'payrollCategory' => $payrollCategory->value,
                    'companyId' => $companyId,
                    'totalActiveEmployees' => $employees->count(),
                    'withMatchingCategoryContract' => $withMatchingContract,
                    'withOppositeCategoryOnly' => $payrollCategory === PayrollCategory::Office ? $withCrewOnlyActive : $withOfficeOnlyActive,
                    'withNoActiveContract' => $withNoActiveContract,
                ],
                'runId' => 'post-fix',
                'timestamp' => (int) round(microtime(true) * 1000),
            ]).PHP_EOL,
            FILE_APPEND,
        );
        // #endregion

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

            $this->setStringCell($sheet, 1, $rowNumber, $contract?->id !== null ? (string) $contract->id : null);
            $this->setStringCell($sheet, 2, $rowNumber, (string) ($employee->employee_no ?? ''));
            $sheet->setCellValueByColumnAndRow(3, $rowNumber, $employee->name);
            $sheet->setCellValueByColumnAndRow(4, $rowNumber, $contract?->contract_type);
            $this->setStringCell($sheet, 5, $rowNumber, $contract?->start_date?->toDateString());
            $this->setStringCell($sheet, 6, $rowNumber, $contract?->end_date?->toDateString());
            $this->setStringCell($sheet, 7, $rowNumber, $contract?->labor_contract_id);
            $sheet->setCellValueByColumnAndRow(8, $rowNumber, $contract?->status);
            $sheet->setCellValueByColumnAndRow(9, $rowNumber, $contract?->basic_salary);

            if ($payrollCategory === PayrollCategory::Office) {
                $sheet->setCellValueByColumnAndRow(10, $rowNumber, $contract?->housing_allowance);
                $sheet->setCellValueByColumnAndRow(11, $rowNumber, $contract?->transport_allowance);
                $sheet->setCellValueByColumnAndRow(12, $rowNumber, $contract?->other_allowances);
                $sheet->setCellValueByColumnAndRow(13, $rowNumber, $contract?->note);
            } else {
                $sheet->setCellValueByColumnAndRow(10, $rowNumber, $contract?->supplementary_allowance);
                $sheet->setCellValueByColumnAndRow(11, $rowNumber, $contract?->site_allowance);
                $sheet->setCellValueByColumnAndRow(12, $rowNumber, $contract?->note);
            }

            $rowNumber++;
        }

        $lastColumn = $payrollCategory === PayrollCategory::Office ? 'M' : 'L';
        $lastDataRow = max($rowNumber - 1, ContractsImport::DATA_START_ROW);
        $sheet->setAutoFilter("A1:{$lastColumn}{$lastDataRow}");
        $sheet->freezePane('A2');

        foreach (['E', 'F'] as $column) {
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
