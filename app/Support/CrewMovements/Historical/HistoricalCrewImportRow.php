<?php

namespace App\Support\CrewMovements\Historical;

/**
 * One parsed spreadsheet row before identity resolution / domain validation.
 */
final class HistoricalCrewImportRow
{
    /**
     * @param  array<string, string|null>  $raw
     * @param  array<string, string>  $fieldErrors  Parse-level field errors (missing/malformed)
     */
    public function __construct(
        public readonly int $rowNumber,
        public readonly array $raw,
        public readonly array $fieldErrors = [],
    ) {}

    public function employeeNo(): ?string
    {
        $value = $this->raw[HistoricalCrewImportColumns::EMPLOYEE_NO] ?? null;

        return $value !== null && $value !== '' ? $value : null;
    }

    public function vesselName(): ?string
    {
        $value = $this->raw[HistoricalCrewImportColumns::VESSEL] ?? null;

        return $value !== null && $value !== '' ? $value : null;
    }

    public function rankName(): ?string
    {
        $value = $this->raw[HistoricalCrewImportColumns::RANK] ?? null;

        return $value !== null && $value !== '' ? $value : null;
    }

    public function clientName(): ?string
    {
        $value = $this->raw[HistoricalCrewImportColumns::CLIENT] ?? null;

        return $value !== null && $value !== '' ? $value : null;
    }

    public function onsiteFrom(): ?string
    {
        return $this->nullableRaw(HistoricalCrewImportColumns::ONSITE_FROM);
    }

    public function onsiteTo(): ?string
    {
        return $this->nullableRaw(HistoricalCrewImportColumns::ONSITE_TO);
    }

    public function remarks(): ?string
    {
        return $this->nullableRaw(HistoricalCrewImportColumns::REMARKS);
    }

    public function isEmpty(): bool
    {
        foreach ($this->raw as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Fingerprint for exact duplicate detection inside the workbook.
     */
    public function exactDuplicateKey(): string
    {
        $parts = [];

        foreach (HistoricalCrewImportColumns::headers() as $header) {
            $parts[] = mb_strtolower(trim((string) ($this->raw[$header] ?? '')));
        }

        return implode('|', $parts);
    }

    private function nullableRaw(string $header): ?string
    {
        $value = $this->raw[$header] ?? null;

        return $value !== null && $value !== '' ? $value : null;
    }
}
