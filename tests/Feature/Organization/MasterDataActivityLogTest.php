<?php

use App\Models\ApprovalLocation;
use App\Models\Bank;
use App\Models\Client;
use App\Models\CompanyVisaType;
use App\Models\Country;
use App\Models\Course;
use App\Models\Currency;
use App\Models\DocumentType;
use App\Models\Gender;
use App\Models\Project;
use App\Models\Rank;
use App\Models\Religion;
use App\Models\SssaOption;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselType;
use App\Models\VisaType;

test('activity log is recorded for master data creation', function (string $modelClass, callable $create) {
    $this->actingAs(User::factory()->create());

    $model = $create();

    $this->assertDatabaseHas('activity_log', [
        'event' => 'created',
        'subject_type' => $modelClass,
        'subject_id' => $model->id,
    ]);
})->with([
    'client' => [
        Client::class,
        fn () => Client::query()->create(['name' => 'Activity Client '.uniqid(), 'is_active' => true]),
    ],
    'project' => [
        Project::class,
        fn () => Project::query()->create(['title' => 'Activity Project '.uniqid(), 'is_active' => true]),
    ],
    'vessel type' => [
        VesselType::class,
        fn () => VesselType::query()->create(['name' => 'Activity Vessel Type '.uniqid(), 'is_active' => true]),
    ],
    'vessel' => [
        Vessel::class,
        function () {
            $type = VesselType::query()->create(['name' => 'Type '.uniqid(), 'is_active' => true]);

            return Vessel::query()->create([
                'name' => 'Activity Vessel '.uniqid(),
                'vessel_type_id' => $type->id,
                'is_active' => true,
            ]);
        },
    ],
    'bank' => [
        Bank::class,
        function () {
            $country = Country::query()->create([
                'code' => 'B'.fake()->unique()->numerify('##'),
                'name' => 'Bank Land',
                'dial_code' => '+971',
                'is_active' => true,
            ]);

            return Bank::query()->create([
                'name' => 'Activity Bank '.uniqid(),
                'country_id' => $country->id,
                'is_active' => true,
            ]);
        },
    ],
    'course' => [
        Course::class,
        fn () => Course::query()->create(['name' => 'Activity Course '.uniqid(), 'is_active' => true]),
    ],
    'document type' => [
        DocumentType::class,
        fn () => DocumentType::query()->create(['title' => 'Activity Doc Type '.uniqid(), 'is_active' => true]),
    ],
    'rank' => [
        Rank::class,
        fn () => Rank::query()->create(['name' => 'Activity Rank '.uniqid(), 'is_active' => true]),
    ],
    'country' => [
        Country::class,
        fn () => Country::query()->create([
            'code' => 'C'.fake()->unique()->numerify('##'),
            'name' => 'Activity Country',
            'dial_code' => '+1',
            'is_active' => true,
        ]),
    ],
    'currency' => [
        Currency::class,
        fn () => Currency::query()->create([
            'code' => 'Y'.fake()->unique()->numerify('##'),
            'name' => 'Activity Currency',
            'symbol' => 'A$',
            'is_active' => true,
        ]),
    ],
    'gender' => [
        Gender::class,
        fn () => Gender::query()->create(['name' => 'Activity Gender '.uniqid(), 'is_active' => true]),
    ],
    'religion' => [
        Religion::class,
        fn () => Religion::query()->create(['name' => 'Activity Religion '.uniqid(), 'is_active' => true]),
    ],
    'visa type' => [
        VisaType::class,
        fn () => VisaType::query()->create(['name' => 'Activity Visa '.uniqid(), 'is_active' => true]),
    ],
    'company visa type' => [
        CompanyVisaType::class,
        fn () => CompanyVisaType::query()->create(['name' => 'Activity Co Visa '.uniqid(), 'is_active' => true]),
    ],
    'approval location' => [
        ApprovalLocation::class,
        fn () => ApprovalLocation::query()->create(['name' => 'Activity Location '.uniqid(), 'is_active' => true]),
    ],
    'sssa option' => [
        SssaOption::class,
        fn () => SssaOption::query()->create(['name' => 'Activity SSSA '.uniqid(), 'is_active' => true]),
    ],
]);
