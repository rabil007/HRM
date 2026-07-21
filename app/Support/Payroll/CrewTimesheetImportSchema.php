<?php

namespace App\Support\Payroll;

use App\Models\SalaryInputType;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class CrewTimesheetImportSchema
{
    public const HEADER_EMPLOYEE_NO = 'Employee No';

    public const HEADER_REMARKS = 'Remarks';

    public const HEADER_SIGN_ON_STANDBY_FROM = 'Sign-On Standby From';

    public const HEADER_SIGN_ON_STANDBY_TO = 'Sign-On Standby To';

    public const HEADER_ONSITE_FROM = 'Onsite From';

    public const HEADER_ONSITE_TO = 'Onsite To';

    public const HEADER_SIGN_OFF_STANDBY_FROM = 'Sign-Off Standby From';

    public const HEADER_SIGN_OFF_STANDBY_TO = 'Sign-Off Standby To';

    public const HEADER_UNPAID_LEAVE_DAYS = 'Unpaid Leave Days';

    public const HEADER_OVERTIME_HOURS = 'Overtime Hours';

    /**
     * @return list<string>
     */
    public static function rosterHeaders(): array
    {
        return [
            self::HEADER_EMPLOYEE_NO,
            'Employee Name',
            'Division',
            'Department',
            'Position',
            self::HEADER_SIGN_ON_STANDBY_FROM,
            self::HEADER_SIGN_ON_STANDBY_TO,
            self::HEADER_ONSITE_FROM,
            self::HEADER_ONSITE_TO,
            self::HEADER_SIGN_OFF_STANDBY_FROM,
            self::HEADER_SIGN_OFF_STANDBY_TO,
            self::HEADER_UNPAID_LEAVE_DAYS,
            self::HEADER_OVERTIME_HOURS,
        ];
    }

    /**
     * @return list<string>
     */
    public function headers(int $companyId): array
    {
        return array_merge(
            self::rosterHeaders(),
            $this->activeSalaryInputTypes($companyId)
                ->pluck('name')
                ->all(),
            [self::HEADER_REMARKS],
        );
    }

    /**
     * @return Collection<int, SalaryInputType>
     */
    public function activeSalaryInputTypes(int $companyId): Collection
    {
        (new ProvisionDefaultSalaryInputTypes)->handle($companyId);

        return SalaryInputType::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function lastColumnLetter(int $companyId): string
    {
        return $this->columnLetter(count($this->headers($companyId)));
    }

    public function columnLetter(int $columnIndex): string
    {
        $letter = '';
        $index = $columnIndex;

        while ($index > 0) {
            $remainder = ($index - 1) % 26;
            $letter = chr(65 + $remainder).$letter;
            $index = intdiv($index - 1, 26);
        }

        return $letter;
    }

    /**
     * @return array{
     *     remarks_column: ?string,
     *     salary_type_columns: array<string, int>
     * }
     */
    public function mapHeaderColumns(Worksheet $sheet, int $companyId): array
    {
        $typesByNormalizedName = $this->activeSalaryInputTypes($companyId)
            ->keyBy(fn (SalaryInputType $type) => $this->normalizeHeader((string) $type->name));

        $map = [
            'remarks_column' => null,
            'salary_type_columns' => [],
        ];

        $highestColumnIndex = max(
            count($this->headers($companyId)),
            Coordinate::columnIndexFromString($sheet->getHighestDataColumn()),
        );

        for ($columnIndex = 1; $columnIndex <= $highestColumnIndex; $columnIndex++) {
            $header = $this->normalizeHeader(
                (string) $sheet->getCellByColumnAndRow($columnIndex, 1)->getCalculatedValue(),
            );

            if ($header === '') {
                continue;
            }

            $columnLetter = $this->columnLetter($columnIndex);

            if ($header === $this->normalizeHeader(self::HEADER_REMARKS)) {
                $map['remarks_column'] = $columnLetter;

                continue;
            }

            $type = $typesByNormalizedName->get($header);

            if ($type !== null) {
                $map['salary_type_columns'][$columnLetter] = (int) $type->id;
            }
        }

        return $map;
    }

    private function normalizeHeader(string $header): string
    {
        return mb_strtolower(trim($header));
    }
}
