<?php

namespace App\Support\CrewMovements;

use App\Enums\CrewAccommodationStatus;
use App\Models\RoomType;
use App\Support\Settings\CompanyTimezone;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class CrewAlreadyArrivedAccommodation
{
    public const STATUS_REQUIRED_MESSAGE = 'Select pre-join accommodation when Already Arrived is enabled.';

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public static function validationRules(int $companyId, bool $required, string $prefix = ''): array
    {
        $key = static fn (string $field): string => $prefix !== '' ? "{$prefix}.{$field}" : $field;

        return [
            $key('accommodation_status') => [
                $required ? 'required' : 'nullable',
                'string',
                Rule::in(CrewAccommodationStatus::values()),
            ],
            $key('hotel_id') => [
                Rule::requiredIf(fn (): bool => $required
                    && self::inputIsHotel($prefix)),
                'nullable',
                'integer',
                Rule::exists('hotels', 'id')->where('company_id', $companyId)->where('is_active', true),
            ],
            $key('room_type_id') => [
                'nullable',
                'integer',
                Rule::exists('room_types', 'id')
                    ->where('company_id', $companyId)
                    ->where('is_active', true),
            ],
            $key('check_in_date') => [
                Rule::requiredIf(fn (): bool => $required
                    && self::inputIsHotel($prefix)),
                'nullable',
                'date',
            ],
        ];
    }

    public static function validateAgainstArrival(
        Validator $validator,
        int $companyId,
        array $input,
        mixed $arrivalDate,
        string $prefix = '',
    ): void {
        $status = $input['accommodation_status'] ?? null;

        if ($status === null || $status === '') {
            return;
        }

        if ($arrivalDate === null || $arrivalDate === '') {
            return;
        }

        $timezone = CompanyTimezone::forCompanyId($companyId);
        $arrivalLocalDate = Carbon::parse((string) $arrivalDate, $timezone)->startOfDay();
        $today = Carbon::now($timezone)->startOfDay();
        $errorKey = static fn (string $field): string => $prefix !== '' ? "{$prefix}.{$field}" : $field;

        if ($status === CrewAccommodationStatus::Hotel->value) {
            $checkInRaw = $input['check_in_date'] ?? null;

            if ($checkInRaw !== null && $checkInRaw !== '') {
                $checkInDate = Carbon::parse((string) $checkInRaw, $timezone)->startOfDay();

                if ($checkInDate->gt($today)) {
                    $validator->errors()->add(
                        $errorKey('check_in_date'),
                        'Hotel check-in cannot be in the future.',
                    );
                }

                if ($checkInDate->lt($arrivalLocalDate)) {
                    $validator->errors()->add(
                        $errorKey('check_in_date'),
                        'Hotel check-in cannot be before the actual arrival date.',
                    );
                }
            }

            $hotelId = isset($input['hotel_id']) ? (int) $input['hotel_id'] : 0;
            $roomTypeId = isset($input['room_type_id']) && $input['room_type_id'] !== null && $input['room_type_id'] !== ''
                ? (int) $input['room_type_id']
                : null;

            if ($hotelId > 0 && $roomTypeId !== null) {
                $validRoom = RoomType::query()
                    ->whereKey($roomTypeId)
                    ->where('company_id', $companyId)
                    ->where('hotel_id', $hotelId)
                    ->where('is_active', true)
                    ->exists();

                if (! $validRoom) {
                    $validator->errors()->add(
                        $errorKey('room_type_id'),
                        'The selected room type is invalid or inactive.',
                    );
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>|null
     */
    public static function payloadFromAttributes(array $attributes): ?array
    {
        if (! array_key_exists('accommodation_status', $attributes)
            || $attributes['accommodation_status'] === null
            || $attributes['accommodation_status'] === '') {
            return null;
        }

        return [
            'accommodation_status' => $attributes['accommodation_status'],
            'hotel_id' => $attributes['hotel_id'] ?? null,
            'room_type_id' => $attributes['room_type_id'] ?? null,
            'check_in_date' => $attributes['check_in_date'] ?? null,
        ];
    }

    public static function accommodationWasRecorded(?array $payload): bool
    {
        return $payload !== null;
    }

    private static function inputIsHotel(string $prefix): bool
    {
        $request = request();

        if ($request === null) {
            return false;
        }

        $status = $prefix === ''
            ? $request->input('accommodation_status')
            : $request->input("{$prefix}.accommodation_status");

        return $status === CrewAccommodationStatus::Hotel->value;
    }
}
