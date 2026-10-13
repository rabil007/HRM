<?php

use App\Support\CrewMovements\CrewAlreadyArrivedStart;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;

test('already arrived flag parses common truthy values', function (mixed $value, bool $expected) {
    expect(CrewAlreadyArrivedStart::isEnabled($value))->toBe($expected);
})->with([
    'true bool' => [true, true],
    'false bool' => [false, false],
    'one' => [1, true],
    'zero' => [0, false],
    'true string' => ['true', true],
    'false string' => ['false', false],
    'on' => ['on', true],
    'null' => [null, false],
]);

test('actual arrival validation requires a date', function () {
    ['company' => $company] = makeCrewAssignmentFixtures();
    $validator = Validator::make([], []);

    CrewAlreadyArrivedStart::validateActualArrivalDate($validator, $company->id, null, 'planned_arrival_at');

    expect($validator->errors()->first('planned_arrival_at'))->toBe(CrewAlreadyArrivedStart::REQUIRED_MESSAGE);
});

test('actual arrival validation rejects future company-local dates', function () {
    ['company' => $company] = makeCrewAssignmentFixtures();
    $company->update(['timezone' => 'Asia/Dubai']);
    Carbon::setTestNow(Carbon::parse('2026-10-13 12:00:00', 'Asia/Dubai'));
    $validator = Validator::make([], []);

    CrewAlreadyArrivedStart::validateActualArrivalDate($validator, $company->id, '2026-10-14', 'planned_arrival_at');

    expect($validator->errors()->first('planned_arrival_at'))->toBe(CrewAlreadyArrivedStart::FUTURE_MESSAGE);
});

test('resolve actual arrival uses company timezone start of day', function () {
    ['company' => $company] = makeCrewAssignmentFixtures();
    $company->update(['timezone' => 'Asia/Dubai']);

    $arrival = CrewAlreadyArrivedStart::resolveActualArrivalAt($company->id, '2026-10-04');

    expect($arrival->timezone('Asia/Dubai')->format('Y-m-d H:i:s'))->toBe('2026-10-04 00:00:00');
});
