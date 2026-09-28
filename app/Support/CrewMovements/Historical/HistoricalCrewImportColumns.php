<?php

namespace App\Support\CrewMovements\Historical;

/**
 * Past Crew Data sheet columns for simplified operational periods.
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

    public const SIGN_ON_ACCOMMODATION = 'sign_on_accommodation';

    public const SIGN_ON_HOTEL = 'sign_on_hotel';

    public const SIGN_ON_ROOM_TYPE = 'sign_on_room_type';

    public const ONSITE_FROM = 'onsite_from';

    public const ONSITE_TO = 'onsite_to';

    public const SIGN_OFF_STANDBY_FROM = 'sign_off_standby_from';

    public const SIGN_OFF_STANDBY_TO = 'sign_off_standby_to';

    public const SIGN_OFF_ACCOMMODATION = 'sign_off_accommodation';

    public const SIGN_OFF_HOTEL = 'sign_off_hotel';

    public const SIGN_OFF_ROOM_TYPE = 'sign_off_room_type';

    public const HOME_AVAILABLE_FROM = 'home_available_from';

    public const REMARKS = 'remarks';

    public const OUTDATED_TEMPLATE_MESSAGE = 'This workbook uses an older Past Crew Data template. Download the latest template and try again.';

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
            self::ONSITE_FROM,
            self::ONSITE_TO,
            self::SIGN_OFF_STANDBY_FROM,
            self::SIGN_OFF_STANDBY_TO,
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
            self::ONSITE_FROM => 'Onsite From',
            self::ONSITE_TO => 'Onsite To',
            self::SIGN_OFF_STANDBY_FROM => 'Sign-Off Standby From',
            self::SIGN_OFF_STANDBY_TO => 'Sign-Off Standby To',
            self::HOME_AVAILABLE_FROM => 'Home Date',
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
            self::ONSITE_FROM,
            self::ONSITE_TO,
            self::SIGN_OFF_STANDBY_FROM,
            self::SIGN_OFF_STANDBY_TO,
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
        $trimmed = trim($header);

        if ($trimmed === '') {
            return '';
        }

        $normalized = mb_strtolower($trimmed);
        $normalized = preg_replace('/\s*\*+\s*$/', '', $normalized) ?? $normalized;
        $normalized = trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);

        if ($normalized === '') {
            return '';
        }

        if (self::isRejectedLegacyHeader($normalized)) {
            throw new \InvalidArgumentException(self::OUTDATED_TEMPLATE_MESSAGE);
        }

        $aliases = self::headerAliases();

        if (isset($aliases[$normalized])) {
            return $aliases[$normalized];
        }

        $display = preg_replace('/\s*\*+\s*$/', '', $trimmed) ?? $trimmed;
        $display = trim(preg_replace('/\s+/', ' ', $display) ?? $display);
        if ($display === '') {
            $display = $trimmed;
        }

        throw new \InvalidArgumentException(
            "Unknown Past Crew Data column '{$display}'. Do not rename template columns. Download the latest template if needed."
        );
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
            // Accommodation and hotel columns removed from Past Crew Data.
            'sign-on accommodation',
            'sign_on_accommodation',
            'sign on accommodation',
            'sign-on hotel',
            'sign_on_hotel',
            'sign on hotel',
            'sign-on room type',
            'sign_on_room_type',
            'sign on room type',
            'sign-off accommodation',
            'sign_off_accommodation',
            'sign off accommodation',
            'sign-off hotel',
            'sign_off_hotel',
            'sign off hotel',
            'sign-off room type',
            'sign_off_room_type',
            'sign off room type',
            'pre-join accommodation',
            'pre join accommodation',
            'pre_join_accommodation',
            'pre-join hotel',
            'pre join hotel',
            'pre_join_hotel',
            'pre-join room type',
            'pre join room type',
            'pre_join_room_type',
            'post-sign-off accommodation',
            'post sign-off accommodation',
            'post_signoff_accommodation',
            'post_sign_off_accommodation',
            'post-sign-off hotel',
            'post sign-off hotel',
            'post_signoff_hotel',
            'post_sign_off_hotel',
            'post-sign-off room type',
            'post sign-off room type',
            'post_signoff_room_type',
            'post_sign_off_room_type',
            'accommodation',
            'hotel',
            'room type',
            'room_type',
            // Hotel check-in/out were removed from user input; dates derive from standby.
            'pre-join hotel check-in',
            'pre join hotel check-in',
            'pre_join_hotel_check_in',
            'pre-join hotel check-out',
            'pre join hotel check-out',
            'pre_join_hotel_check_out',
            'post-sign-off hotel check-in',
            'post sign-off hotel check-in',
            'post_signoff_hotel_check_in',
            'post-sign-off hotel check-out',
            'post sign-off hotel check-out',
            'post_signoff_hotel_check_out',
            'sign-on hotel check-in',
            'sign-on hotel check-out',
            'sign-off hotel check-in',
            'sign-off hotel check-out',
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
        $aliases['home / available from'] = self::HOME_AVAILABLE_FROM;
        $aliases['home available from'] = self::HOME_AVAILABLE_FROM;
        $aliases['home date'] = self::HOME_AVAILABLE_FROM;

        return $aliases;
    }
}
