<?php

namespace Database\Factories;

use App\Enums\CrewAccommodationStatus;
use App\Enums\CrewAccommodationStayType;
use App\Models\CrewAccommodationStay;
use App\Models\CrewAssignment;
use App\Models\Hotel;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrewAccommodationStay>
 */
class CrewAccommodationStayFactory extends Factory
{
    protected $model = CrewAccommodationStay::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'crew_assignment_id' => CrewAssignment::factory(),
            'company_id' => static function (array $attributes): int {
                $assignmentId = $attributes['crew_assignment_id'] ?? null;

                if ($assignmentId === null) {
                    throw new \InvalidArgumentException('crew_assignment_id must be set on CrewAccommodationStayFactory.');
                }

                return (int) CrewAssignment::query()
                    ->whereKey($assignmentId)
                    ->value('company_id');
            },
            'hotel_id' => null,
            'room_type_id' => null,
            'stay_type' => CrewAccommodationStayType::PreJoin,
            'accommodation_status' => CrewAccommodationStatus::NoAccommodation,
            'check_in_date' => null,
            'check_out_date' => null,
            'started_from_phase_id' => null,
            'created_by' => null,
            'updated_by' => null,
        ];
    }

    public function hotelStay(): static
    {
        return $this->state(function (array $attributes): array {
            $companyId = $attributes['company_id'] ?? null;

            if ($companyId === null && isset($attributes['crew_assignment_id'])) {
                $companyId = CrewAssignment::query()
                    ->whereKey($attributes['crew_assignment_id'])
                    ->value('company_id');
            }

            return [
                'accommodation_status' => CrewAccommodationStatus::Hotel,
                'hotel_id' => Hotel::factory()->state([
                    'company_id' => $companyId,
                ]),
                'room_type_id' => RoomType::factory()->state([
                    'company_id' => $companyId,
                ]),
                'check_in_date' => now()->toDateString(),
                'check_out_date' => null,
            ];
        });
    }
}
