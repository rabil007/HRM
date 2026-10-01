<?php

namespace App\Support\Positions;

use App\Models\Department;
use App\Models\Position;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;

final class ImportPositionsFromCsv
{
    /**
     * @return array{ok: true, message: string}|array{ok: false, error: string}
     */
    public function handle(UploadedFile $uploaded, int $companyId): array
    {
        $path = $uploaded->getRealPath() ?: $uploaded->path();
        $handle = fopen((string) $path, 'r');

        if ($handle === false) {
            return ['ok' => false, 'error' => 'Could not read the uploaded file.'];
        }

        $header = fgetcsv($handle);
        if (! is_array($header) || count($header) === 0) {
            fclose($handle);

            return ['ok' => false, 'error' => 'The CSV file is empty.'];
        }

        $map = $this->mapHeader($header);
        if (! isset($map['title'])) {
            fclose($handle);

            return ['ok' => false, 'error' => 'The CSV must include a title column.'];
        }

        $departmentsByName = Department::query()
            ->where('company_id', $companyId)
            ->get(['id', 'name'])
            ->keyBy(fn (Department $department): string => mb_strtolower(trim($department->name)));

        $canUpdate = (bool) Auth::user()?->can('positions.update');

        $created = 0;
        $updated = 0;
        $emptyTitles = 0;
        $unknownDepartments = 0;
        $permissionDenied = 0;
        $failedRows = 0;

        while (($row = fgetcsv($handle)) !== false) {
            if (! is_array($row)) {
                continue;
            }

            if (($created + $updated + $failedRows + $emptyTitles) >= 2000) {
                break;
            }

            $title = trim((string) ($row[$map['title']] ?? ''));
            if ($title === '') {
                $emptyTitles++;

                continue;
            }

            if (mb_strlen($title) > 200) {
                $failedRows++;

                continue;
            }

            $departmentId = null;
            if (isset($map['department'])) {
                $departmentName = trim((string) ($row[$map['department']] ?? ''));
                if ($departmentName !== '') {
                    $department = $departmentsByName->get(mb_strtolower($departmentName));
                    if ($department === null) {
                        $unknownDepartments++;
                        $failedRows++;

                        continue;
                    }
                    $departmentId = (int) $department->id;
                }
            }

            $attributes = [
                'description' => $this->nullableString($row, $map, 'description', 5000),
                'grade' => $this->nullableString($row, $map, 'grade', 50),
                'min_salary' => $this->nullableDecimal($row, $map, 'min_salary'),
                'max_salary' => $this->nullableDecimal($row, $map, 'max_salary'),
                'status' => $this->parseStatus($row, $map),
                'is_crew_position' => $this->parseBoolean($row, $map, 'is_crew_position', true),
                'max_tour_of_duty_days' => $this->nullableInteger($row, $map, 'max_tour_of_duty_days', 1, 365),
            ];

            if ($attributes['min_salary'] === false || $attributes['max_salary'] === false || $attributes['max_tour_of_duty_days'] === false) {
                $failedRows++;

                continue;
            }

            $existingQuery = Position::query()
                ->where('company_id', $companyId)
                ->where('title', $title);

            if ($departmentId === null) {
                $existingQuery->whereNull('department_id');
            } else {
                $existingQuery->where('department_id', $departmentId);
            }

            $existing = $existingQuery->first();

            if ($existing !== null) {
                if (! $canUpdate) {
                    $permissionDenied++;

                    continue;
                }

                $existing->update($attributes);
                $updated++;

                continue;
            }

            Position::query()->create([
                'company_id' => $companyId,
                'department_id' => $departmentId,
                'title' => $title,
                ...$attributes,
            ]);
            $created++;
        }

        fclose($handle);

        $imported = $created + $updated;
        if ($imported === 0) {
            $parts = [];
            if ($emptyTitles > 0) {
                $parts[] = "{$emptyTitles} empty title(s)";
            }
            if ($unknownDepartments > 0) {
                $parts[] = "{$unknownDepartments} unknown department(s)";
            }
            if ($permissionDenied > 0) {
                $parts[] = "{$permissionDenied} existing row(s) skipped (no update permission)";
            }
            if ($failedRows > 0) {
                $parts[] = "{$failedRows} invalid row(s)";
            }

            return [
                'ok' => false,
                'error' => $parts === []
                    ? 'No rows were imported. Ensure each row has a title.'
                    : 'No rows were imported. '.implode('; ', $parts).'.',
            ];
        }

        $message = "Imported {$imported} position row(s) ({$created} created, {$updated} updated).";
        if ($unknownDepartments > 0) {
            $message .= " Skipped {$unknownDepartments} unknown department(s).";
        }
        if ($permissionDenied > 0) {
            $message .= " Skipped {$permissionDenied} existing row(s) (no update permission).";
        }
        if ($failedRows > 0) {
            $message .= " Skipped {$failedRows} invalid row(s).";
        }

        return ['ok' => true, 'message' => $message];
    }

    /**
     * @param  list<string|null>  $header
     * @return array<string, int>
     */
    private function mapHeader(array $header): array
    {
        $map = [];

        foreach ($header as $index => $cell) {
            $key = mb_strtolower(trim((string) $cell));
            $key = preg_replace('/^\xEF\xBB\xBF/', '', $key) ?? $key;

            if (in_array($key, ['title', 'position', 'position title', 'name'], true)) {
                $map['title'] = (int) $index;
            }
            if (in_array($key, ['department', 'department name'], true)) {
                $map['department'] = (int) $index;
            }
            if (in_array($key, ['description'], true)) {
                $map['description'] = (int) $index;
            }
            if (in_array($key, ['grade'], true)) {
                $map['grade'] = (int) $index;
            }
            if (in_array($key, ['min_salary', 'min salary', 'minimum salary'], true)) {
                $map['min_salary'] = (int) $index;
            }
            if (in_array($key, ['max_salary', 'max salary', 'maximum salary'], true)) {
                $map['max_salary'] = (int) $index;
            }
            if (in_array($key, ['status', 'active', 'is_active', 'enabled'], true)) {
                $map['status'] = (int) $index;
            }
            if (in_array($key, ['is_crew_position', 'crew position', 'crew'], true)) {
                $map['is_crew_position'] = (int) $index;
            }
            if (in_array($key, ['max_tour_of_duty_days', 'max tour of duty days', 'tour of duty days'], true)) {
                $map['max_tour_of_duty_days'] = (int) $index;
            }
        }

        return $map;
    }

    /**
     * @param  list<string|null>  $row
     * @param  array<string, int>  $map
     */
    private function nullableString(array $row, array $map, string $field, int $max): ?string
    {
        if (! isset($map[$field])) {
            return null;
        }

        $value = trim((string) ($row[$map[$field]] ?? ''));
        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, $max);
    }

    /**
     * @param  list<string|null>  $row
     * @param  array<string, int>  $map
     */
    private function nullableDecimal(array $row, array $map, string $field): float|false|null
    {
        if (! isset($map[$field])) {
            return null;
        }

        $raw = trim((string) ($row[$map[$field]] ?? ''));
        if ($raw === '') {
            return null;
        }

        $normalized = str_replace([',', ' '], '', $raw);
        if (! is_numeric($normalized) || (float) $normalized < 0) {
            return false;
        }

        return round((float) $normalized, 2);
    }

    /**
     * @param  list<string|null>  $row
     * @param  array<string, int>  $map
     */
    private function nullableInteger(array $row, array $map, string $field, int $min, int $max): int|false|null
    {
        if (! isset($map[$field])) {
            return null;
        }

        $raw = trim((string) ($row[$map[$field]] ?? ''));
        if ($raw === '') {
            return null;
        }

        if (! ctype_digit($raw)) {
            return false;
        }

        $value = (int) $raw;
        if ($value < $min || $value > $max) {
            return false;
        }

        return $value;
    }

    /**
     * @param  list<string|null>  $row
     * @param  array<string, int>  $map
     */
    private function parseStatus(array $row, array $map): string
    {
        if (! isset($map['status'])) {
            return 'active';
        }

        $value = mb_strtolower(trim((string) ($row[$map['status']] ?? '')));
        if ($value === '' || in_array($value, ['1', 'yes', 'true', 'y', 'active', 'enabled'], true)) {
            return 'active';
        }

        if (in_array($value, ['0', 'no', 'false', 'n', 'inactive', 'disabled'], true)) {
            return 'inactive';
        }

        return 'active';
    }

    /**
     * @param  list<string|null>  $row
     * @param  array<string, int>  $map
     */
    private function parseBoolean(array $row, array $map, string $field, bool $default): bool
    {
        if (! isset($map[$field])) {
            return $default;
        }

        $value = mb_strtolower(trim((string) ($row[$map[$field]] ?? '')));
        if ($value === '') {
            return $default;
        }

        return in_array($value, ['1', 'yes', 'true', 'y', 'active'], true);
    }
}
