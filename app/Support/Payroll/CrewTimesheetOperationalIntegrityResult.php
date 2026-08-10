<?php

namespace App\Support\Payroll;

/**
 * Structured operational-integrity findings for a Daily Crew timesheet.
 *
 * @phpstan-type IntegrityFinding array{
 *     code: string,
 *     message: string,
 *     pay_category: string|null,
 *     severity: 'blocking'|'warning'
 * }
 */
final class CrewTimesheetOperationalIntegrityResult
{
    /**
     * @param  list<IntegrityFinding>  $blocking
     * @param  list<IntegrityFinding>  $warnings
     */
    public function __construct(
        public readonly array $blocking = [],
        public readonly array $warnings = [],
    ) {}

    public function hasBlocking(): bool
    {
        return $this->blocking !== [];
    }

    public function hasWarnings(): bool
    {
        return $this->warnings !== [];
    }

    public function firstBlockingMessage(): ?string
    {
        return $this->blocking[0]['message'] ?? null;
    }

    /**
     * @return array{blocking: list<IntegrityFinding>, warnings: list<IntegrityFinding>}
     */
    public function toArray(): array
    {
        return [
            'blocking' => $this->blocking,
            'warnings' => $this->warnings,
        ];
    }
}
