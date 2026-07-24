<?php

namespace App\Support\Organization;

use Illuminate\Http\Request;

class SelectedRecordIds
{
    /**
     * @return list<int>
     */
    public static function fromRequest(Request $request): array
    {
        $rawIds = $request->query('ids');

        if ($rawIds === null || $rawIds === '') {
            return [];
        }

        abort_unless(is_string($rawIds), 422, 'The selected record IDs are invalid.');

        $values = explode(',', $rawIds);

        abort_if(count($values) > 100, 422, 'No more than 100 records may be exported at once.');

        $ids = collect($values)
            ->map(fn (string $value) => trim($value))
            ->filter(fn (string $value) => $value !== '')
            ->map(function (string $value): int {
                abort_unless(ctype_digit($value) && (int) $value > 0, 422, 'The selected record IDs are invalid.');

                return (int) $value;
            })
            ->unique()
            ->values()
            ->all();

        abort_if($ids === [], 422, 'Select at least one record to export.');

        return $ids;
    }
}
