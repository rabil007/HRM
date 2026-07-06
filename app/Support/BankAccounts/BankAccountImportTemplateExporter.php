<?php

namespace App\Support\BankAccounts;

use App\Imports\BankAccountsImport;
use App\Models\Employee;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class BankAccountImportTemplateExporter
{
    public const TEXT_FORMAT = NumberFormat::FORMAT_TEXT;

    public function __construct(
        private readonly BankAccountsImport $import,
    ) {}

    /**
     * @return array{path: string, filename: string}
     */
    public function export(int $companyId): array
    {
        $employees = Employee::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->with(['bankAccounts.bank'])
            ->orderBy('name')
            ->get();

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($this->import->sheetName());

        foreach ($this->import->headers() as $columnIndex => $header) {
            $sheet->setCellValueByColumnAndRow($columnIndex + 1, 1, $header);
        }

        $rowNumber = BankAccountsImport::DATA_START_ROW;

        foreach ($employees as $employee) {
            /** @var Employee $employee */
            $account = $employee->bankAccounts->firstWhere('is_primary', true) ?? $employee->bankAccounts->first();

            $this->setStringCell($sheet, 1, $rowNumber, (string) ($employee->employee_no ?? ''));
            $sheet->setCellValueByColumnAndRow(2, $rowNumber, $employee->name);
            $sheet->setCellValueByColumnAndRow(3, $rowNumber, $account?->bank?->name);
            $this->setStringCell($sheet, 4, $rowNumber, $account?->iban);
            $sheet->setCellValueByColumnAndRow(5, $rowNumber, $account?->account_name);
            $sheet->setCellValueByColumnAndRow(6, $rowNumber, $account ? ($account->is_primary ? 'yes' : 'no') : '');

            $rowNumber++;
        }

        $lastColumn = 'F';
        $lastDataRow = max($rowNumber - 1, BankAccountsImport::DATA_START_ROW);
        $sheet->setAutoFilter("A1:{$lastColumn}{$lastDataRow}");
        $sheet->freezePane('A2');

        foreach (['A', 'D'] as $column) {
            $sheet->getStyle("{$column}2:{$column}{$lastDataRow}")
                ->getNumberFormat()
                ->setFormatCode(self::TEXT_FORMAT);
        }

        $filename = 'bank-accounts-template.xlsx';

        $path = storage_path('app/temp/'.uniqid('bank-accounts-template-', true).'.xlsx');
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
