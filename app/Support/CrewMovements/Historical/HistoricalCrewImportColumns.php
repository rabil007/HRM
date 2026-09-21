<?php

namespace App\Support\CrewMovements\Historical;

/**
 * Canonical Historical Assignments sheet column keys (snake_case headers).
 */
final class HistoricalCrewImportColumns
{
    public const EMPLOYEE_NO = 'employee_no';

    public const VESSEL = 'vessel';

    public const RANK = 'rank';

    public const CLIENT = 'client';

    public const MOBILISATION_DATE = 'mobilisation_date';

    public const TRAVEL_IN_DATE = 'travel_in_date';

    public const JOIN_STANDBY_DATE = 'join_standby_date';

    public const TRAINING_START_DATE = 'training_start_date';

    public const TRAINING_END_DATE = 'training_end_date';

    public const POST_TRAINING_JOIN_STANDBY_DATE = 'post_training_join_standby_date';

    public const READY_TO_JOIN_DATE = 'ready_to_join_date';

    public const VESSEL_JOIN_DATE = 'vessel_join_date';

    public const DISEMBARK_DATE = 'disembark_date';

    public const DEMOB_STANDBY_DATE = 'demob_standby_date';

    public const TRAVEL_HOME_DATE = 'travel_home_date';

    public const ASSIGNMENT_CLOSE_DATE = 'assignment_close_date';

    public const REMARKS = 'remarks';

    /**
     * @return list<string>
     */
    public static function headers(): array
    {
        return [
            self::EMPLOYEE_NO,
            self::VESSEL,
            self::RANK,
            self::CLIENT,
            self::MOBILISATION_DATE,
            self::TRAVEL_IN_DATE,
            self::JOIN_STANDBY_DATE,
            self::TRAINING_START_DATE,
            self::TRAINING_END_DATE,
            self::POST_TRAINING_JOIN_STANDBY_DATE,
            self::READY_TO_JOIN_DATE,
            self::VESSEL_JOIN_DATE,
            self::DISEMBARK_DATE,
            self::DEMOB_STANDBY_DATE,
            self::TRAVEL_HOME_DATE,
            self::ASSIGNMENT_CLOSE_DATE,
            self::REMARKS,
        ];
    }

    /**
     * @return list<string>
     */
    public static function requiredHeaders(): array
    {
        return [
            self::EMPLOYEE_NO,
            self::VESSEL,
            self::RANK,
            self::VESSEL_JOIN_DATE,
            self::DISEMBARK_DATE,
        ];
    }

    /**
     * @return list<string>
     */
    public static function dateHeaders(): array
    {
        return [
            self::MOBILISATION_DATE,
            self::TRAVEL_IN_DATE,
            self::JOIN_STANDBY_DATE,
            self::TRAINING_START_DATE,
            self::TRAINING_END_DATE,
            self::POST_TRAINING_JOIN_STANDBY_DATE,
            self::READY_TO_JOIN_DATE,
            self::VESSEL_JOIN_DATE,
            self::DISEMBARK_DATE,
            self::DEMOB_STANDBY_DATE,
            self::TRAVEL_HOME_DATE,
            self::ASSIGNMENT_CLOSE_DATE,
        ];
    }

    /**
     * Display headers with required markers for the template.
     *
     * @return list<string>
     */
    public static function displayHeaders(): array
    {
        $required = array_flip(self::requiredHeaders());

        return array_map(
            static fn (string $header): string => isset($required[$header]) ? "{$header} *" : $header,
            self::headers(),
        );
    }

    public static function normalizeHeader(string $header): string
    {
        $normalized = mb_strtolower(trim($header));
        $normalized = preg_replace('/\s*\*+\s*$/', '', $normalized) ?? $normalized;

        return trim($normalized);
    }
}
