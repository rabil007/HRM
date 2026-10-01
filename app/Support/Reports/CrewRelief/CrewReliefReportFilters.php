<?php

namespace App\Support\Reports\CrewRelief;

use Illuminate\Http\Request;

final class CrewReliefReportFilters
{
    public const PRESET_NEXT_7_DAYS = 'next_7_days';

    public const PRESET_NEXT_14_DAYS = 'next_14_days';

    public const PRESET_NEXT_30_DAYS = 'next_30_days';

    public const PRESET_NO_RELIEF = 'no_relief';

    public const PRESET_NOT_READY = 'not_ready';

    public const PRESET_OVERDUE = 'overdue';

    public const PRESET_ALL = 'all';

    public function __construct(
        public readonly string $search = '',
        public readonly string $vesselId = '',
        public readonly string $clientId = '',
        public readonly string $positionId = '',
        public readonly string $plannedSignoffFrom = '',
        public readonly string $plannedSignoffTo = '',
        public readonly string $readiness = '',
        public readonly string $attention = '',
        public readonly string $preset = self::PRESET_NEXT_30_DAYS,
        public readonly int $perPage = 25,
    ) {}

    public static function fromRequest(Request $request, int $companyId): self
    {
        $preset = (string) $request->query('preset', self::PRESET_NEXT_30_DAYS);
        if (! in_array($preset, [
            self::PRESET_NEXT_7_DAYS,
            self::PRESET_NEXT_14_DAYS,
            self::PRESET_NEXT_30_DAYS,
            self::PRESET_NO_RELIEF,
            self::PRESET_NOT_READY,
            self::PRESET_OVERDUE,
            self::PRESET_ALL,
        ], true)) {
            $preset = self::PRESET_NEXT_30_DAYS;
        }

        $perPage = (int) $request->query('per_page', 25);
        if (! in_array($perPage, [25, 50, 100], true)) {
            $perPage = 25;
        }

        $readiness = (string) $request->query('readiness', '');
        if (! in_array($readiness, ['all', 'ready', 'in_progress', 'not_assigned', 'at_risk', 'joined'], true)) {
            $readiness = '';
        }

        $attention = (string) $request->query('attention', '');
        if (! in_array($attention, ['all', 'critical', 'warning', 'healthy'], true)) {
            $attention = '';
        }

        return new self(
            search: trim((string) $request->query('search', '')),
            vesselId: (string) $request->query('vessel_id', ''),
            clientId: (string) $request->query('client_id', ''),
            positionId: (string) ($request->query('position_id') ?? ''),
            plannedSignoffFrom: trim((string) $request->query('planned_signoff_from', '')),
            plannedSignoffTo: trim((string) $request->query('planned_signoff_to', '')),
            readiness: $readiness,
            attention: $attention,
            preset: $preset,
            perPage: $perPage,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'search' => $this->search,
            'vessel_id' => $this->vesselId,
            'client_id' => $this->clientId,
            'position_id' => $this->positionId,
            'planned_signoff_from' => $this->plannedSignoffFrom,
            'planned_signoff_to' => $this->plannedSignoffTo,
            'readiness' => $this->readiness,
            'attention' => $this->attention,
            'preset' => $this->preset,
            'per_page' => $this->perPage,
        ];
    }

    /**
     * @return array<string, string|int>
     */
    public function toQueryArray(): array
    {
        return array_filter(
            $this->toArray(),
            fn (mixed $value, string $key): bool => $value !== ''
                && ! ($key === 'preset' && $value === self::PRESET_NEXT_30_DAYS)
                && ! ($key === 'per_page' && (int) $value === 25),
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
