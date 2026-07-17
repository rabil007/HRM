<?php

namespace App\Support\CrewMovements\Corrections;

use App\Models\Client;
use App\Models\Company;
use App\Models\CompanyVisaType;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\Rank;
use App\Models\Vessel;
use Carbon\Carbon;
use Carbon\CarbonInterface;

final class CrewMovementCorrectionValueSnapshot
{
    public function __construct(
        private readonly CrewMovementCorrectionFieldCatalog $catalog = new CrewMovementCorrectionFieldCatalog,
    ) {}

    /**
     * @param  list<string>  $fields
     * @return array<string, array{value: mixed, display: string|null}>
     */
    public function capture(CrewAssignment $assignment, CrewAssignmentPhase $phase, array $fields): array
    {
        $snapshot = [];

        foreach ($fields as $field) {
            $value = $this->rawValue($assignment, $phase, $field);
            $snapshot[$field] = [
                'value' => $this->serializeValue($field, $value),
                'display' => $this->displayValue($assignment, $field, $value),
            ];
        }

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>  $proposed
     * @return array<string, array{value: mixed, display: string|null}>
     */
    public function captureProposed(
        CrewAssignment $assignment,
        CrewAssignmentPhase $phase,
        array $proposed,
    ): array {
        $snapshot = [];

        foreach ($proposed as $field => $value) {
            $snapshot[$field] = [
                'value' => $this->serializeValue((string) $field, $value),
                'display' => $this->displayValue($assignment, (string) $field, $value),
            ];
        }

        return $snapshot;
    }

    public function rawValue(CrewAssignment $assignment, CrewAssignmentPhase $phase, string $field): mixed
    {
        if ($this->catalog->isAssignmentField($field)) {
            return $assignment->getAttribute($field);
        }

        if ($this->catalog->isDetailsField($field)) {
            $key = substr($field, strlen('details.'));
            $details = $phase->details ?? [];

            return $details[$key] ?? null;
        }

        return $phase->getAttribute($field);
    }

    public function serializeValue(string $field, mixed $value): mixed
    {
        if ($value instanceof CarbonInterface) {
            return $value->toIso8601String();
        }

        if ($this->catalog->isDateTimeField($field) && is_string($value) && $value !== '') {
            return Carbon::parse($value)->toIso8601String();
        }

        if (in_array($field, ['vessel_id', 'rank_id', 'client_id', 'company_visa_type_id'], true)) {
            return $value === null || $value === '' ? null : (int) $value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? null : $trimmed;
        }

        return $value;
    }

    public function displayValue(CrewAssignment $assignment, string $field, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($this->catalog->isDateTimeField($field)) {
            $timezone = $this->companyTimezone((int) $assignment->company_id);
            $carbon = $value instanceof CarbonInterface
                ? $value->copy()->timezone($timezone)
                : Carbon::parse((string) $value, $timezone)->timezone($timezone);

            return $carbon->format('Y-m-d H:i');
        }

        return match ($field) {
            'vessel_id' => Vessel::query()->whereKey((int) $value)->value('name'),
            'rank_id' => Rank::query()->whereKey((int) $value)->value('name'),
            'client_id' => Client::query()->whereKey((int) $value)->value('name'),
            'company_visa_type_id' => CompanyVisaType::query()->whereKey((int) $value)->value('name'),
            default => is_scalar($value) ? (string) $value : null,
        };
    }

    /**
     * @param  array<string, array{value?: mixed}|mixed>  $snapshot
     */
    public function valuesMatch(array $snapshot, CrewAssignment $assignment, CrewAssignmentPhase $phase): bool
    {
        foreach ($snapshot as $field => $entry) {
            $expected = is_array($entry) && array_key_exists('value', $entry)
                ? $entry['value']
                : $entry;
            $actual = $this->serializeValue(
                (string) $field,
                $this->rawValue($assignment, $phase, (string) $field),
            );

            if ($this->normalizeComparable($expected) !== $this->normalizeComparable($actual)) {
                return false;
            }
        }

        return true;
    }

    private function normalizeComparable(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if ($value instanceof CarbonInterface) {
            return $value->toIso8601String();
        }

        return (string) $value;
    }

    private function companyTimezone(int $companyId): string
    {
        return (string) (Company::query()->whereKey($companyId)->value('timezone')
            ?? config('app.timezone', 'UTC'));
    }
}
