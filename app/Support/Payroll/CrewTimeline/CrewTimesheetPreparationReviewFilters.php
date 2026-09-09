<?php

namespace App\Support\Payroll\CrewTimeline;

use Illuminate\Http\Request;

final class CrewTimesheetPreparationReviewFilters
{
    public const SUMMARY_SIGN_ON_STANDBY = 'sign_on_standby';

    public const SUMMARY_ONSITE = 'onsite';

    public const SUMMARY_SIGN_OFF_STANDBY = 'sign_off_standby';

    public const SUMMARY_BLOCKING = 'blocking';

    public const SUMMARY_INFORMATIONAL = 'informational';

    /**
     * @var list<string>
     */
    public const SUMMARY_VALUES = [
        self::SUMMARY_SIGN_ON_STANDBY,
        self::SUMMARY_ONSITE,
        self::SUMMARY_SIGN_OFF_STANDBY,
        self::SUMMARY_BLOCKING,
        self::SUMMARY_INFORMATIONAL,
    ];

    public function __construct(
        public readonly string $search = '',
        public readonly string $departmentId = '',
        public readonly string $positionId = '',
        public readonly string $summary = '',
    ) {}

    public static function fromRequest(Request $request): self
    {
        $summary = trim((string) $request->query('summary', ''));

        return new self(
            search: trim((string) $request->query('search', '')),
            departmentId: trim((string) $request->query('department_id', '')),
            positionId: trim((string) $request->query('position_id', '')),
            summary: in_array($summary, self::SUMMARY_VALUES, true) ? $summary : '',
        );
    }

    public function isActive(): bool
    {
        return $this->search !== ''
            || $this->departmentId !== ''
            || $this->positionId !== ''
            || $this->summary !== '';
    }
}
