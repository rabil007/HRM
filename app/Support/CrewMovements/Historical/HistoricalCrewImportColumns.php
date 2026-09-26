<?php

namespace App\Support\CrewMovements\Historical;

/**
 * Historical Assignments sheet columns for simplified operational periods.
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

    public const SIGN_ON_STANDBY_FROM = 'sign_on_standby_from';

    public const SIGN_ON_STANDBY_TO = 'sign_on_standby_to';

    public const PRE_JOIN_ACCOMMODATION = 'pre_join_accommodation';

    public const PRE_JOIN_HOTEL = 'pre_join_hotel';

    public const PRE_JOIN_ROOM_TYPE = 'pre_join_room_type';

    public const PRE_JOIN_HOTEL_CHECK_IN = 'pre_join_hotel_check_in';

    public const PRE_JOIN_HOTEL_CHECK_OUT = 'pre_join_hotel_check_out';

    public const ONSITE_FROM = 'onsite_from';

    public const ONSITE_TO = 'onsite_to';

    public const SIGN_OFF_STANDBY_FROM = 'sign_off_standby_from';

    public const SIGN_OFF_STANDBY_TO = 'sign_off_standby_to';

    public const POST_SIGNOFF_ACCOMMODATION = 'post_signoff_accommodation';

    public const POST_SIGNOFF_HOTEL = 'post_signoff_hotel';

    public const POST_SIGNOFF_ROOM_TYPE = 'post_signoff_room_type';

    public const POST_SIGNOFF_HOTEL_CHECK_IN = 'post_signoff_hotel_check_in';

    public const POST_SIGNOFF_HOTEL_CHECK_OUT = 'post_signoff_hotel_check_out';

    public const HOME_AVAILABLE_FROM = 'home_available_from';

    public const REMARKS = 'remarks';

    /**
     * @return list<string>
     */
    public static function headers(): array
    {
        return [
            self::EMPLOYEE_NO,
            self::EMPLOYEE,
            self::RANK,
            self::VESSEL,
            self::CLIENT,
            self::SIGN_ON_STANDBY_FROM,
            self::SIGN_ON_STANDBY_TO,
            self::PRE_JOIN_ACCOMMODATION,
            self::PRE_JOIN_HOTEL,
            self::PRE_JOIN_ROOM_TYPE,
            self::PRE_JOIN_HOTEL_CHECK_IN,
            self::PRE_JOIN_HOTEL_CHECK_OUT,
            self::ONSITE_FROM,
            self::ONSITE_TO,
            self::SIGN_OFF_STANDBY_FROM,
            self::SIGN_OFF_STANDBY_TO,
            self::POST_SIGNOFF_ACCOMMODATION,
            self::POST_SIGNOFF_HOTEL,
            self::POST_SIGNOFF_ROOM_TYPE,
            self::POST_SIGNOFF_HOTEL_CHECK_IN,
            self::POST_SIGNOFF_HOTEL_CHECK_OUT,
            self::HOME_AVAILABLE_FROM,
            self::REMARKS,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::EMPLOYEE_NO => 'Employee No',
            self::EMPLOYEE => 'Employee Name',
            self::RANK => 'Rank',
            self::VESSEL => 'Vessel',
            self::CLIENT => 'Client',
            self::SIGN_ON_STANDBY_FROM => 'Sign-On Standby From',
            self::SIGN_ON_STANDBY_TO => 'Sign-On Standby To',
            self::PRE_JOIN_ACCOMMODATION => 'Pre-Join Accommodation',
            self::PRE_JOIN_HOTEL => 'Pre-Join Hotel',
            self::PRE_JOIN_ROOM_TYPE => 'Pre-Join Room Type',
            self::PRE_JOIN_HOTEL_CHECK_IN => 'Pre-Join Hotel Check-In',
            self::PRE_JOIN_HOTEL_CHECK_OUT => 'Pre-Join Hotel Check-Out',
            self::ONSITE_FROM => 'Onsite From',
            self::ONSITE_TO => 'Onsite To',
            self::SIGN_OFF_STANDBY_FROM => 'Sign-Off Standby From',
            self::SIGN_OFF_STANDBY_TO => 'Sign-Off Standby To',
            self::POST_SIGNOFF_ACCOMMODATION => 'Post-Sign-Off Accommodation',
            self::POST_SIGNOFF_HOTEL => 'Post-Sign-Off Hotel',
            self::POST_SIGNOFF_ROOM_TYPE => 'Post-Sign-Off Room Type',
            self::POST_SIGNOFF_HOTEL_CHECK_IN => 'Post-Sign-Off Hotel Check-In',
            self::POST_SIGNOFF_HOTEL_CHECK_OUT => 'Post-Sign-Off Hotel Check-Out',
            self::HOME_AVAILABLE_FROM => 'Home / Available From',
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
        ];
    }

    /**
     * @return list<string>
     */
    public static function dateHeaders(): array
    {
        return [
            self::SIGN_ON_STANDBY_FROM,
            self::SIGN_ON_STANDBY_TO,
            self::PRE_JOIN_HOTEL_CHECK_IN,
            self::PRE_JOIN_HOTEL_CHECK_OUT,
            self::ONSITE_FROM,
            self::ONSITE_TO,
            self::SIGN_OFF_STANDBY_FROM,
            self::SIGN_OFF_STANDBY_TO,
            self::POST_SIGNOFF_HOTEL_CHECK_IN,
            self::POST_SIGNOFF_HOTEL_CHECK_OUT,
            self::HOME_AVAILABLE_FROM,
        ];
    }

    /**
     * Period starts (and Home) that count as meaningful movement input.
     *
     * @return list<string>
     */
    public static function movementPeriodHeaders(): array
    {
        return [
            self::SIGN_ON_STANDBY_FROM,
            self::ONSITE_FROM,
            self::SIGN_OFF_STANDBY_FROM,
            self::HOME_AVAILABLE_FROM,
        ];
    }

    /**
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
                'This workbook uses an outdated Past Crew Data template. Columns such as Pre-Mobilisation, Training Start/End, Travel In, Ready to Join, and editable Days totals are no longer supported. Download a fresh template and try again.',
            );
        }

        $aliases = self::headerAliases();

        return $aliases[$normalized] ?? '';
    }

    public static function isRejectedLegacyHeader(string $normalizedHeader): bool
    {
        return in_array($normalizedHeader, [
            'pre-mobilisation',
            'pre mobilisation',
            'mobilisation',
            'mobilisation_date',
            'join standby',
            'join_standby',
            'join_standby_date',
            'training start',
            'training_start',
            'training_start_date',
            'training end',
            'training_end',
            'training_end_date',
            'on vessel',
            'vessel_join_date',
            'disembarked',
            'disembark_date',
            'home / redeployment',
            'home/redeployment',
            'travel_home_date',
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
            'post-training join standby',
            'post training join standby',
            'post_training_join_standby',
            'post_training_join_standby_date',
            'post_training_join_standby_at',
            'demobilisation standby',
            'demobilization standby',
            'demob standby',
            'demob_standby',
            'demob_standby_date',
            'demob_standby_at',
            'assignment closed',
            'assignment_closed',
            'assignment_close_date',
            'assignment_closed_at',
            'standby days',
            'onsite days',
            'sign-on standby days',
            'sign-off standby days',
        ], true);
    }

    /**
     * @return array<string, string>
     */
    private static function headerAliases(): array
    {
        $aliases = [];

        foreach (self::labels() as $key => $label) {
            $aliases[mb_strtolower($label)] = $key;
            $aliases[$key] = $key;
        }

        $aliases['employee number'] = self::EMPLOYEE_NO;
        $aliases['employee'] = self::EMPLOYEE;
        $aliases['employee name'] = self::EMPLOYEE;
        $aliases['onsite / on vessel from'] = self::ONSITE_FROM;
        $aliases['onsite / on vessel to'] = self::ONSITE_TO;
        $aliases['home / available'] = self::HOME_AVAILABLE_FROM;
        $aliases['home available from'] = self::HOME_AVAILABLE_FROM;

        return $aliases;
    }
}
