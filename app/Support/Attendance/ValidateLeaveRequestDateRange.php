<?php

namespace App\Support\Attendance;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Authoritative leave-request date-range validation for domain workflows.
 */
final class ValidateLeaveRequestDateRange
{
    /**
     * @return array{start_date: string, end_date: string}
     *
     * @throws ValidationException
     */
    public function handle(mixed $startDate, mixed $endDate): array
    {
        $start = is_string($startDate) ? trim($startDate) : '';
        $end = is_string($endDate) ? trim($endDate) : '';

        if ($start === '') {
            throw ValidationException::withMessages([
                'start_date' => 'A valid leave start date is required.',
            ]);
        }

        if ($end === '') {
            throw ValidationException::withMessages([
                'end_date' => 'A valid leave end date is required.',
            ]);
        }

        $parsedStart = $this->parseStrictDate($start, 'start_date');
        $parsedEnd = $this->parseStrictDate($end, 'end_date');

        if ($parsedStart->greaterThan($parsedEnd)) {
            throw ValidationException::withMessages([
                'start_date' => 'The start date must be on or before the end date.',
            ]);
        }

        return [
            'start_date' => $parsedStart->toDateString(),
            'end_date' => $parsedEnd->toDateString(),
        ];
    }

    /**
     * @throws ValidationException
     */
    private function parseStrictDate(string $value, string $field): CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw ValidationException::withMessages([
                $field => 'The '.$this->fieldLabel($field).' must be a valid date in Y-m-d format.',
            ]);
        }

        try {
            $parsed = CarbonImmutable::createFromFormat('Y-m-d', $value);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                $field => 'The '.$this->fieldLabel($field).' is not a real calendar date.',
            ]);
        }

        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            throw ValidationException::withMessages([
                $field => 'The '.$this->fieldLabel($field).' is not a real calendar date.',
            ]);
        }

        return $parsed->startOfDay();
    }

    private function fieldLabel(string $field): string
    {
        return match ($field) {
            'start_date' => 'start date',
            'end_date' => 'end date',
            default => $field,
        };
    }
}
