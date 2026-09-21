<?php

namespace App\Support\CrewMovements\Historical;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Rank;
use App\Models\User;
use App\Models\Vessel;
use App\Support\Employees\EmployeeVisibilityScope;
use App\Support\Vessels\ResolvesCompanyVessels;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class HistoricalCrewImportTemplate
{
    public const INSTRUCTIONS_SHEET = 'Instructions';

    public const ASSIGNMENTS_SHEET = 'Historical Assignments';

    public const REFERENCE_SHEET = 'Reference Data';

    public const FILENAME = 'Historical_Crew_Import_Template.xlsx';

    /**
     * @return array{path: string, filename: string}
     */
    public function export(int $companyId, User $actor): array
    {
        $spreadsheet = new Spreadsheet;

        $instructions = $spreadsheet->getActiveSheet();
        $instructions->setTitle(self::INSTRUCTIONS_SHEET);
        $this->writeInstructions($instructions);

        $assignments = $spreadsheet->createSheet();
        $assignments->setTitle(self::ASSIGNMENTS_SHEET);
        $this->writeAssignmentsSheet($assignments);

        $reference = $spreadsheet->createSheet();
        $reference->setTitle(self::REFERENCE_SHEET);
        $this->writeReferenceSheet($reference, $companyId, $actor);

        $spreadsheet->setActiveSheetIndex(1);

        $path = sys_get_temp_dir().'/'.uniqid('historical-crew-import-template-', true).'.xlsx';

        (new Xlsx($spreadsheet))->save($path);

        return [
            'path' => $path,
            'filename' => self::FILENAME,
        ];
    }

    private function writeInstructions(Worksheet $sheet): void
    {
        $lines = [
            ['Historical Crew Import — Instructions'],
            [''],
            ['Purpose'],
            ['Reconstruct historical crew movements and, when safe, bootstrap where the employee currently is in OMS-HRM.'],
            ['Sea Service is a downstream result of a completed On Vessel → Disembarked period — not the main reason for this import.'],
            [''],
            ['Workflow'],
            ['1. Fill the Historical Assignments sheet using values from Reference Data.'],
            ['2. Upload the completed workbook in Add Past Data → Import Excel.'],
            ['3. Validate File runs authoritative historical rules (no records are written yet).'],
            ['4. Review Ready / Warning / Blocked rows, including Inferred State.'],
            ['5. Confirm import to revalidate and persist Ready + Warning rows (Blocked rows are skipped).'],
            [''],
            ['How movement dates work'],
            ['Enter the employee\'s known movement dates in sequence.'],
            ['OMS-HRM reconstructs the movement timeline automatically.'],
            ['The last valid movement determines the employee\'s current operational state when no newer active OMS assignment exists.'],
            ['Do not guess unknown historical dates.'],
            [''],
            ['Important'],
            ['- Do not enter future dates.'],
            ['- Employee, Vessel, and Rank are required. At least one movement date is required.'],
            ['- On Vessel and Disembarked are optional — a row may end at Pre-Mobilisation, Join Standby, Training, On Vessel, Demobilisation Standby, or Home.'],
            ['- Training End automatically returns the employee to Join Standby.'],
            ['- Disembarked automatically moves the employee to Demobilisation Standby until Home / Redeployment is recorded.'],
            ['- Sea Service is created only for a completed On Vessel → Disembarked period.'],
            ['- An employee may have at most one open/current assignment in the workbook, and it must be the chronologically latest row.'],
            ['- If the employee already has an active Crew Assignment in OMS-HRM, this import will not create another current assignment.'],
            ['- Employee is identified by Employee No (not by name).'],
            ['- Formula cells (=...) are not allowed — use plain values only.'],
            ['- Maximum 5,000 historical assignment rows per workbook.'],
            ['- Vessel / Rank / Client must match Reference Data exactly (names are unique).'],
            ['- Inactive Vessel / Rank / Client values are allowed for historical backfill (shown as warnings).'],
            ['- Historical Client may differ from the Vessel\'s Current Client in Reference Data.'],
            [''],
            ['Accepted date format'],
            ['Prefer YYYY-MM-DD (example: 2024-01-15).'],
            ['Excel date cells are also accepted and normalized to company calendar dates.'],
            [''],
            ['Movement sequence'],
            ['Pre-Mobilisation → Join Standby → Training Start → Training End → On Vessel → Disembarked → Home / Redeployment'],
            ['Only enter dates you know. OMS derives phases from those events.'],
        ];

        foreach ($lines as $index => $line) {
            $sheet->setCellValueByColumnAndRow(1, $index + 1, $line[0]);
        }

        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A3')->getFont()->setBold(true);
        $sheet->getStyle('A7')->getFont()->setBold(true);
        $sheet->getStyle('A14')->getFont()->setBold(true);
        $sheet->getStyle('A20')->getFont()->setBold(true);
        $sheet->getStyle('A39')->getFont()->setBold(true);
        $sheet->getStyle('A43')->getFont()->setBold(true);
        $sheet->getColumnDimension('A')->setWidth(110);
    }

    private function writeAssignmentsSheet(Worksheet $sheet): void
    {
        $headers = HistoricalCrewImportColumns::displayHeaders();
        $required = array_flip(HistoricalCrewImportColumns::requiredHeaders());

        foreach ($headers as $columnIndex => $header) {
            $column = $columnIndex + 1;
            $sheet->setCellValueByColumnAndRow($column, 1, $header);
            $canonical = HistoricalCrewImportColumns::normalizeHeader($header);

            if (isset($required[$canonical])) {
                $sheet->getStyleByColumnAndRow($column, 1)->getFont()->setBold(true);
                $sheet->getStyleByColumnAndRow($column, 1)->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('FEF3C7');
            } else {
                $sheet->getStyleByColumnAndRow($column, 1)->getFont()->setBold(true);
            }

            $sheet->getColumnDimensionByColumn($column)->setWidth(
                in_array($canonical, HistoricalCrewImportColumns::dateHeaders(), true) ? 18 : 22,
            );
        }

        $sample = [
            HistoricalCrewImportColumns::EMPLOYEE_NO => 'EXAMPLE001',
            HistoricalCrewImportColumns::EMPLOYEE => '',
            HistoricalCrewImportColumns::VESSEL => 'Example Vessel',
            HistoricalCrewImportColumns::RANK => 'Example Rank',
            HistoricalCrewImportColumns::CLIENT => '',
            HistoricalCrewImportColumns::MOBILISATION_DATE => '2024-01-01',
            HistoricalCrewImportColumns::JOIN_STANDBY_DATE => '2024-01-05',
            HistoricalCrewImportColumns::TRAINING_START_DATE => '',
            HistoricalCrewImportColumns::TRAINING_END_DATE => '',
            HistoricalCrewImportColumns::VESSEL_JOIN_DATE => '2024-01-15',
            HistoricalCrewImportColumns::DISEMBARK_DATE => '',
            HistoricalCrewImportColumns::TRAVEL_HOME_DATE => '',
            HistoricalCrewImportColumns::REMARKS => 'SAMPLE — replace with real historical rows before upload',
        ];

        foreach (HistoricalCrewImportColumns::headers() as $columnIndex => $header) {
            $value = $sample[$header] ?? '';
            $column = $columnIndex + 1;

            if ($header === HistoricalCrewImportColumns::EMPLOYEE_NO) {
                $sheet->setCellValueExplicitByColumnAndRow($column, 2, $value, DataType::TYPE_STRING);
            } else {
                $sheet->setCellValueByColumnAndRow($column, 2, $value);
            }
        }

        foreach (HistoricalCrewImportColumns::dateHeaders() as $header) {
            $columnIndex = array_search($header, HistoricalCrewImportColumns::headers(), true);

            if ($columnIndex === false) {
                continue;
            }

            $columnLetter = Coordinate::stringFromColumnIndex($columnIndex + 1);
            $sheet->getStyle("{$columnLetter}2:{$columnLetter}5001")
                ->getNumberFormat()
                ->setFormatCode('yyyy-mm-dd');
        }

        $employeeCol = Coordinate::stringFromColumnIndex(1);
        $sheet->getStyle("{$employeeCol}2:{$employeeCol}5001")
            ->getNumberFormat()
            ->setFormatCode(NumberFormat::FORMAT_TEXT);

        $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:{$lastColumn}1");
        $sheet->getStyle('A1:'.$lastColumn.'1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    }

    private function writeReferenceSheet(Worksheet $sheet, int $companyId, User $actor): void
    {
        $row = 1;
        $row = $this->writeEmployeeReference($sheet, $companyId, $actor, $row);
        $row += 2;
        $row = $this->writeVesselReference($sheet, $companyId, $row);
        $row += 2;
        $row = $this->writeRankReference($sheet, $row);
        $row += 2;
        $this->writeClientReference($sheet, $row);

        foreach (range(1, 5) as $column) {
            $sheet->getColumnDimensionByColumn($column)->setWidth(24);
        }
    }

    private function writeEmployeeReference(Worksheet $sheet, int $companyId, User $actor, int $startRow): int
    {
        $sheet->setCellValueByColumnAndRow(1, $startRow, 'Employees');
        $sheet->getStyleByColumnAndRow(1, $startRow)->getFont()->setBold(true)->setSize(12);

        $headerRow = $startRow + 1;
        foreach (['Employee No', 'Employee', 'Status', 'Department'] as $index => $header) {
            $sheet->setCellValueByColumnAndRow($index + 1, $headerRow, $header);
            $sheet->getStyleByColumnAndRow($index + 1, $headerRow)->getFont()->setBold(true);
        }

        $query = Employee::query()
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->with(['department:id,name'])
            ->orderBy('name');

        $query = EmployeeVisibilityScope::apply($query, $actor, $companyId);

        $row = $headerRow + 1;

        foreach ($query->get(['id', 'employee_no', 'name', 'status', 'department_id']) as $employee) {
            $this->writeSafeString($sheet, 1, $row, (string) ($employee->employee_no ?? ''));
            $this->writeSafeString($sheet, 2, $row, (string) $employee->name);
            $this->writeSafeString($sheet, 3, $row, $this->statusLabel((string) $employee->status));
            $this->writeSafeString($sheet, 4, $row, $employee->department?->name ?? '');
            $row++;
        }

        return $row - 1;
    }

    private function writeVesselReference(Worksheet $sheet, int $companyId, int $startRow): int
    {
        $sheet->setCellValueByColumnAndRow(1, $startRow, 'Vessels');
        $sheet->getStyleByColumnAndRow(1, $startRow)->getFont()->setBold(true)->setSize(12);

        $headerRow = $startRow + 1;
        foreach (['Vessel', 'Status', 'Current Client'] as $index => $header) {
            $sheet->setCellValueByColumnAndRow($index + 1, $headerRow, $header);
            $sheet->getStyleByColumnAndRow($index + 1, $headerRow)->getFont()->setBold(true);
        }

        $row = $headerRow + 1;

        $vessels = ResolvesCompanyVessels::queryForCompany($companyId)
            ->with(['client:id,name'])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get(['id', 'name', 'is_active', 'client_id']);

        foreach ($vessels as $vessel) {
            /** @var Vessel $vessel */
            $this->writeSafeString($sheet, 1, $row, (string) $vessel->name);
            $this->writeSafeString($sheet, 2, $row, $vessel->is_active ? 'Active' : 'Inactive');
            $this->writeSafeString($sheet, 3, $row, $vessel->client?->name ?? '');
            $row++;
        }

        return $row - 1;
    }

    private function writeRankReference(Worksheet $sheet, int $startRow): int
    {
        $sheet->setCellValueByColumnAndRow(1, $startRow, 'Ranks');
        $sheet->getStyleByColumnAndRow(1, $startRow)->getFont()->setBold(true)->setSize(12);

        $headerRow = $startRow + 1;
        foreach (['Rank', 'Status'] as $index => $header) {
            $sheet->setCellValueByColumnAndRow($index + 1, $headerRow, $header);
            $sheet->getStyleByColumnAndRow($index + 1, $headerRow)->getFont()->setBold(true);
        }

        $row = $headerRow + 1;

        foreach (Rank::query()->orderByDesc('is_active')->orderBy('name')->get(['id', 'name', 'is_active']) as $rank) {
            $this->writeSafeString($sheet, 1, $row, (string) $rank->name);
            $this->writeSafeString($sheet, 2, $row, $rank->is_active ? 'Active' : 'Inactive');
            $row++;
        }

        return $row - 1;
    }

    private function writeClientReference(Worksheet $sheet, int $startRow): int
    {
        $sheet->setCellValueByColumnAndRow(1, $startRow, 'Clients');
        $sheet->getStyleByColumnAndRow(1, $startRow)->getFont()->setBold(true)->setSize(12);

        $headerRow = $startRow + 1;
        foreach (['Client', 'Status'] as $index => $header) {
            $sheet->setCellValueByColumnAndRow($index + 1, $headerRow, $header);
            $sheet->getStyleByColumnAndRow($index + 1, $headerRow)->getFont()->setBold(true);
        }

        $row = $headerRow + 1;

        foreach (Client::query()->orderByDesc('is_active')->orderBy('name')->get(['id', 'name', 'is_active']) as $client) {
            $this->writeSafeString($sheet, 1, $row, (string) $client->name);
            $this->writeSafeString($sheet, 2, $row, $client->is_active ? 'Active' : 'Inactive');
            $row++;
        }

        return $row - 1;
    }

    private function writeSafeString(Worksheet $sheet, int $column, int $row, string $value): void
    {
        $sheet->setCellValueExplicitByColumnAndRow(
            $column,
            $row,
            HistoricalSpreadsheetSafeString::forExport($value),
            DataType::TYPE_STRING,
        );
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'active' => 'Active',
            'inactive' => 'Inactive',
            'terminated' => 'Terminated',
            'on_leave' => 'On leave',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }
}
