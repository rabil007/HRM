<?php

namespace App\Enums;

enum CrewReliefStatus: string
{
    case NoRelief = 'no_relief';
    case ReliefPlanned = 'relief_planned';
    case AssignmentCreated = 'assignment_created';
    case Mobilising = 'mobilising';
    case ReadyToJoin = 'ready_to_join';
    case ReliefOnboard = 'relief_onboard';

    public function label(): string
    {
        return match ($this) {
            self::NoRelief => 'No Relief',
            self::ReliefPlanned => 'Relief Planned',
            self::AssignmentCreated => 'Assignment Created',
            self::Mobilising => 'Mobilising',
            self::ReadyToJoin => 'Ready to Join',
            self::ReliefOnboard => 'Relief Onboard',
        };
    }

    public function actionLabel(): string
    {
        return match ($this) {
            self::NoRelief => 'Plan Relief',
            self::ReliefPlanned => 'Open Relief Plan',
            self::AssignmentCreated,
            self::Mobilising,
            self::ReadyToJoin,
            self::ReliefOnboard => 'Open Relief Assignment',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Normal filter choices for operational desks. Legacy/historical
     * `ready_to_join` rows remain resolvable via URL and internal queries.
     *
     * @return list<self>
     */
    public static function filterable(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $status): bool => $status !== self::ReadyToJoin,
        ));
    }

    /**
     * Statuses that are not yet ready to join / onboard.
     *
     * @return list<self>
     */
    public static function notReady(): array
    {
        return [
            self::NoRelief,
            self::ReliefPlanned,
            self::AssignmentCreated,
            self::Mobilising,
        ];
    }

    public function isReadyOrOnboard(): bool
    {
        return $this === self::ReadyToJoin || $this === self::ReliefOnboard;
    }
}
