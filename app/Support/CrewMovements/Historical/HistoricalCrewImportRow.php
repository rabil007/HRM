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

    public function vesselJoinDate(): ?string
    {
        return $this->raw[HistoricalCrewImportColumns::VESSEL_JOIN_DATE] ?? null;
    }

    public function disembarkDate(): ?string
    {
        return $this->raw[HistoricalCrewImportColumns::DISEMBARK_DATE] ?? null;
    }

    public function remarks(): ?string
    {
        $value = $this->raw[HistoricalCrewImportColumns::REMARKS] ?? null;

        return $value !== null && $value !== '' ? $value : null;
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
}
