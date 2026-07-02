<?php

namespace App\Support\Payroll\Services;

use App\Enums\PayrollCategory;
use App\Imports\CrewTimesheetsImport;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Support\Payroll\PayrollEmployeeQuery;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class CrewTimesheetTemplateExporter
{
    /**
     * @return array{path: string, filename: string}
     */
    public function export(int $companyId, PayrollPeriod $period): array
    {
        $employees = PayrollEmployeeQuery::activeQuery($companyId, PayrollCategory::Crew)
            ->with(['department.parent:id,name', 'position:id,title'])
            ->orderBy('employees.name')
            ->get();

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(CrewTimesheetsImport::SHEET_NAME);

        $headers = [
            'Employee No',
            'Employee Name',
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
            $sheet->setCellValueByColumnAndRow(3, $rowNumber, $this->formatDepartment($employee));
            $sheet->setCellValueByColumnAndRow(4, $rowNumber, (string) ($employee->position?->title ?? '—'));
            $rowNumber++;
        }

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

    private function formatDepartment(Employee $employee): string
    {
        $department = $employee->department;
        $parentName = $department?->parent?->name;
        $name = $department?->name;

        if (filled($parentName) && filled($name)) {
            return "{$parentName} / {$name}";
        }

        if (filled($name)) {
            return (string) $name;
        }

        return '—';
    }
}
