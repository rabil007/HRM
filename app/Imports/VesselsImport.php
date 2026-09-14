<?php

namespace App\Imports;

use Illuminate\Http\UploadedFile;

final class VesselsImport
{
    public const MAX_ROWS = 2000;

    /**
     * @return array{
     *     present_columns: list<string>,
     *     rows: list<array{
     *         row: int,
     *         vessel_id: ?string,
     *         client: ?string,
     *         name: ?string,
     *         vessel_type: ?string,
     *         imo_no: ?string,
     *         official_no: ?string,
     *         call_sign: ?string,
     *         grt: ?string,
     *         bhp: ?string,
     *         is_active: ?string
     *     }>
     * }
     */
    public function parse(UploadedFile $file): array
    {
        $path = $file->getRealPath() ?: $file->path();
        $handle = fopen((string) $path, 'r');

        if ($handle === false) {
            throw new \InvalidArgumentException('Could not read the uploaded file.');
        }

        $header = fgetcsv($handle);

        if (! is_array($header) || $header === []) {
            fclose($handle);

            throw new \InvalidArgumentException('The CSV file is empty.');
        }

        $map = $this->mapHeaders($header);

        if (! isset($map['name'], $map['vessel_type'])) {
            fclose($handle);

            throw new \InvalidArgumentException('The CSV must include name and vessel_type columns.');
        }

        $presentColumns = array_keys($map);
        $rows = [];
        $rowNumber = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;

            if (! is_array($row)) {
                continue;
            }

            if ($this->isBlankRow($row)) {
                continue;
            }

            $rows[] = [
                'row' => $rowNumber,
                'vessel_id' => $this->cell($row, $map, 'vessel_id'),
                'client' => $this->cell($row, $map, 'client'),
                'name' => $this->cell($row, $map, 'name'),
                'vessel_type' => $this->cell($row, $map, 'vessel_type'),
                'imo_no' => $this->cell($row, $map, 'imo_no'),
                'official_no' => $this->cell($row, $map, 'official_no'),
                'call_sign' => $this->cell($row, $map, 'call_sign'),
                'grt' => $this->cell($row, $map, 'grt'),
                'bhp' => $this->cell($row, $map, 'bhp'),
                'is_active' => $this->cell($row, $map, 'is_active'),
            ];

            if (count($rows) >= self::MAX_ROWS) {
                break;
            }
        }

        fclose($handle);

        return [
            'present_columns' => $presentColumns,
            'rows' => $rows,
        ];
    }

    /**
     * @return list<string>
     */
    public static function templateHeaders(): array
    {
        return [
            'vessel_id',
            'client',
            'name',
            'vessel_type',
            'imo_no',
            'official_no',
            'call_sign',
            'grt',
            'bhp',
            'is_active',
        ];
    }

    /**
     * @param  list<string|null>  $header
     * @return array<string, int>
     */
    private function mapHeaders(array $header): array
    {
        $map = [];

        foreach ($header as $index => $cell) {
            $key = mb_strtolower(trim((string) $cell));

            if (in_array($key, ['vessel_id', 'vessel id', 'id'], true)) {
                $map['vessel_id'] = (int) $index;
            }

            if (in_array($key, ['client', 'client_name'], true)) {
                $map['client'] = (int) $index;
            }

            if (in_array($key, ['name', 'vessel', 'vessel name', 'vessel_name'], true)) {
                $map['name'] = (int) $index;
            }

            if (in_array($key, ['vessel_type', 'vessel type', 'type'], true)) {
                $map['vessel_type'] = (int) $index;
            }

            if (in_array($key, ['imo_no', 'imo no', 'imo'], true)) {
                $map['imo_no'] = (int) $index;
            }

            if (in_array($key, ['official_no', 'official no', 'official number'], true)) {
                $map['official_no'] = (int) $index;
            }

            if (in_array($key, ['call_sign', 'call sign'], true)) {
                $map['call_sign'] = (int) $index;
            }

            if (in_array($key, ['grt', 'gross tonnage', 'gross_tonnage'], true)) {
                $map['grt'] = (int) $index;
            }

            if (in_array($key, ['bhp', 'brake horsepower', 'horsepower'], true)) {
                $map['bhp'] = (int) $index;
            }

            if (in_array($key, ['active', 'is_active', 'status', 'enabled'], true)) {
                $map['is_active'] = (int) $index;
            }
        }

        return $map;
    }

    /**
     * @param  list<string|null>  $row
     * @param  array<string, int>  $map
     */
    private function cell(array $row, array $map, string $field): ?string
    {
        if (! isset($map[$field])) {
            return null;
        }

        $value = trim((string) ($row[$map[$field]] ?? ''));

        return $value === '' ? null : $value;
    }

    /**
     * @param  list<string|null>  $row
     */
    private function isBlankRow(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }
}
