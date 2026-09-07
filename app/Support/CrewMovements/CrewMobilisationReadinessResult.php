<?php

namespace App\Support\CrewMovements;

use App\Enums\CrewMobilisationReadinessStatus;

/**
 * Derived mobilisation readiness. Advisory only — never a movement blocker.
 *
 * @phpstan-type ReadinessCheck array{
 *     code: string,
 *     severity: 'ok'|'warning'|'critical'|string,
 *     label: string,
 *     message: string,
 *     document_type_id: int|null
 * }
 */
final class CrewMobilisationReadinessResult
{
    /**
     * @param  list<ReadinessCheck>  $checks
     * @param  list<ReadinessCheck>  $problems
     */
    public function __construct(
        public readonly CrewMobilisationReadinessStatus $status,
        public readonly int $checksClear,
        public readonly int $checksTotal,
        public readonly array $checks,
        public readonly array $problems,
        public readonly ?string $documentsHref,
        public readonly bool $applies,
    ) {}

    public static function notApplicable(): self
    {
        return new self(
            status: CrewMobilisationReadinessStatus::Ready,
            checksClear: 0,
            checksTotal: 0,
            checks: [],
            problems: [],
            documentsHref: null,
            applies: false,
        );
    }

    public function hasConfiguredChecks(): bool
    {
        return $this->checksTotal > 0;
    }

    public function presentationLabel(): string
    {
        if ($this->applies && ! $this->hasConfiguredChecks()) {
            return 'No Checks Configured';
        }

        return $this->status->label();
    }

    /**
     * @return array{
     *     applies: bool,
     *     status: string,
     *     status_label: string,
     *     checks_clear: int,
     *     checks_total: int,
     *     advisory_note: string,
     *     problems: list<ReadinessCheck>,
     *     checks: list<ReadinessCheck>,
     *     documents_href: string|null
     * }
     */
    public function toArray(bool $compact = false): array
    {
        $payload = [
            'applies' => $this->applies,
            'status' => $this->status->value,
            'status_label' => $this->presentationLabel(),
            'checks_clear' => $this->checksClear,
            'checks_total' => $this->checksTotal,
            'advisory_note' => 'Operational warning only. This does not block crew movement.',
            'problems' => array_slice($this->problems, 0, 3),
            'documents_href' => $this->documentsHref,
        ];

        if (! $compact) {
            $payload['checks'] = $this->checks;
        }

        return $payload;
    }
}
