<?php

namespace App\Support\CrewMovements\Historical;

/**
 * Historical Assignments sheet columns.
 *
 * Template headers are product-facing labels; {@see self::normalizeHeader()}
 * maps them to stable internal keys used by the parser and preview service.
 */
final class HistoricalCrewImportColumns
{
    public const EMPLOYEE_NO = 'employee_no';

    public const EMPLOYEE = 'employee';

    public const VESSEL = 'vessel';

    public const RANK = 'rank';

    public const CLIENT = 'client';

    public const MOBILISATION_DATE = 'mobilisation_date';

    public const JOIN_STANDBY_DATE = 'join_standby_date';

    public const TRAINING_START_DATE = 'training_start_date';

    public const TRAINING_END_DATE = 'training_end_date';

    public const POST_TRAINING_JOIN_STANDBY_DATE = 'post_training_join_standby_date';

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
            self::EMPLOYEE,
            self::VESSEL,
            self::RANK,
            self::CLIENT,
            self::MOBILISATION_DATE,
            self::JOIN_STANDBY_DATE,
            self::TRAINING_START_DATE,
            self::TRAINING_END_DATE,
            self::POST_TRAINING_JOIN_STANDBY_DATE,
            self::VESSEL_JOIN_DATE,
            self::DISEMBARK_DATE,
            self::DEMOB_STANDBY_DATE,
            self::TRAVEL_HOME_DATE,
            self::ASSIGNMENT_CLOSE_DATE,
            self::REMARKS,
        ];
    }

    /**
     * Product-facing template labels keyed by internal header.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::EMPLOYEE_NO => 'Employee No',
            self::EMPLOYEE => 'Employee',
            self::VESSEL => 'Vessel',
            self::RANK => 'Rank',
            self::CLIENT => 'Client',
            self::MOBILISATION_DATE => 'Pre-Mobilisation',
            self::JOIN_STANDBY_DATE => 'Join Standby',
            self::TRAINING_START_DATE => 'Training Start',
            self::TRAINING_END_DATE => 'Training End',
            self::POST_TRAINING_JOIN_STANDBY_DATE => 'Post-Training Join Standby',
            self::VESSEL_JOIN_DATE => 'On Vessel',
            self::DISEMBARK_DATE => 'Disembarked',
            self::DEMOB_STANDBY_DATE => 'Demobilisation Standby',
            self::TRAVEL_HOME_DATE => 'Home / Redeployment',
            self::ASSIGNMENT_CLOSE_DATE => 'Assignment Closed',
            self::REMARKS => 'Remarks',
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
            self::JOIN_STANDBY_DATE,
            self::TRAINING_START_DATE,
            self::TRAINING_END_DATE,
            self::POST_TRAINING_JOIN_STANDBY_DATE,
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
        $labels = self::labels();

        return array_map(
            static function (string $key) use ($required, $labels): string {
                $label = $labels[$key] ?? $key;

                return isset($required[$key]) ? "{$label} *" : $label;
            },
            self::headers(),
        );
    }

    /**
     * Normalize a spreadsheet header to an internal column key, or '' if unknown.
     */
    public static function normalizeHeader(string $header): string
    {
        $normalized = mb_strtolower(trim($header));
        $normalized = preg_replace('/\s*\*+\s*$/', '', $normalized) ?? $normalized;
        $normalized = trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);

        if ($normalized === '') {
            return '';
        }

        if (self::isRejectedLegacyHeader($normalized)) {
            throw new \InvalidArgumentException(
                'This workbook uses an outdated Historical Crew template (Travel In / Ready to Join columns are no longer supported). Download a fresh template and try again.',
            );
        }

        $aliases = self::headerAliases();

        return $aliases[$normalized] ?? '';
    }

    public static function isRejectedLegacyHeader(string $normalizedHeader): bool
    {
        return in_array($normalizedHeader, [
            'travel in',
            'travel_in',
            'travel_in_date',
            'arrival',
            'arrival_at',
            'arrival / travel in',
            'ready to join',
            'ready_to_join',
            'ready_to_join_date',
            'ready_to_join_at',
        ], true);
    }

    /**
     * Accepted spreadsheet headers → internal keys.
     *
     * @return array<string, string>
     */
    private static function headerAliases(): array
    {
        $aliases = [];

        foreach (self::labels() as $key => $label) {
            $aliases[mb_strtolower($label)] = $key;
            $aliases[$key] = $key;
        }

        // Extra friendly / transitional aliases (not legacy P1/P3).
        $aliases['employee number'] = self::EMPLOYEE_NO;
        $aliases['employee name'] = self::EMPLOYEE;
        $aliases['pre mobilisation'] = self::MOBILISATION_DATE;
        $aliases['pre-mobilisation'] = self::MOBILISATION_DATE;
        $aliases['mobilisation'] = self::MOBILISATION_DATE;
        $aliases['mobilisation_date'] = self::MOBILISATION_DATE;
        $aliases['post training join standby'] = self::POST_TRAINING_JOIN_STANDBY_DATE;
        $aliases['home / redeployment'] = self::TRAVEL_HOME_DATE;
        $aliases['home/redeployment'] = self::TRAVEL_HOME_DATE;
        $aliases['demobilisation standby'] = self::DEMOB_STANDBY_DATE;
        $aliases['assignment closed'] = self::ASSIGNMENT_CLOSE_DATE;
        $aliases['on vessel'] = self::VESSEL_JOIN_DATE;
        $aliases['disembarked'] = self::DISEMBARK_DATE;

        return $aliases;
    }
}
