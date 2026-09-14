<?php

use App\Models\Company;
use App\Models\HikvisionAccessEvent;
use App\Models\HikvisionPerson;
use App\Models\User;
use App\Support\Hikvision\ResolveHikvisionPersonFromAcsEvent;

/**
 * @return array{company: Company, otherCompany: Company}
 */
function makeAcsPersonResolutionCompanies(): array
{
    $user = User::factory()->create();
    $company = setupCompanyWithSettingsPermissions($user, []);
    $otherCompany = additionalHikvisionTestCompany($company, 'acs-person-other-'.fake()->unique()->numerify('####'));

    return ['company' => $company, 'otherCompany' => $otherCompany];
}

test('acs event resolves hikvision person from explicit personId', function () {
    ['company' => $company] = makeAcsPersonResolutionCompanies();

    $person = HikvisionPerson::query()->create([
        'company_id' => $company->id,
        'person_id' => 'hv-person-explicit-1',
        'person_code' => '1034',
        'full_name' => 'Mohammed Rabil T',
    ]);

    $resolved = ResolveHikvisionPersonFromAcsEvent::resolve($company->id, [
        'name' => 'Someone Else',
        'personId' => 'hv-person-explicit-1',
        'employeeNoString' => '9999',
    ]);

    expect($resolved['person_hikvision_id'])->toBe('hv-person-explicit-1')
        ->and($resolved['hikvision_person_id'])->toBe($person->id);
});

test('acs event resolves hikvision person from unique employeeNoString person_code', function () {
    ['company' => $company] = makeAcsPersonResolutionCompanies();

    $person = HikvisionPerson::query()->create([
        'company_id' => $company->id,
        'person_id' => 'hv-person-code-1',
        'person_code' => '1034',
        'full_name' => 'Mohammed Rabil T',
    ]);

    $resolved = ResolveHikvisionPersonFromAcsEvent::resolve($company->id, [
        'name' => 'Mohammed Rabil',
        'employeeNoString' => '1034',
    ]);

    expect($resolved['person_hikvision_id'])->toBe('hv-person-code-1')
        ->and($resolved['hikvision_person_id'])->toBe($person->id);
});

test('acs event resolution is scoped to company_id', function () {
    ['company' => $company, 'otherCompany' => $otherCompany] = makeAcsPersonResolutionCompanies();

    HikvisionPerson::query()->create([
        'company_id' => $otherCompany->id,
        'person_id' => 'hv-person-other-company',
        'person_code' => '1034',
        'full_name' => 'Mohammed Rabil T',
    ]);

    $resolved = ResolveHikvisionPersonFromAcsEvent::resolve($company->id, [
        'name' => 'Mohammed Rabil T',
        'employeeNoString' => '1034',
        'personId' => 'hv-person-other-company',
    ]);

    expect($resolved['person_hikvision_id'])->toBe('')
        ->and($resolved['hikvision_person_id'])->toBeNull();
});

test('acs event does not link when person_code matches multiple people in the company', function () {
    ['company' => $company] = makeAcsPersonResolutionCompanies();

    HikvisionPerson::query()->create([
        'company_id' => $company->id,
        'person_id' => 'hv-dup-code-a',
        'person_code' => '1034',
        'full_name' => 'Person A',
    ]);
    HikvisionPerson::query()->create([
        'company_id' => $company->id,
        'person_id' => 'hv-dup-code-b',
        'person_code' => '1034',
        'full_name' => 'Person B',
    ]);

    $resolved = ResolveHikvisionPersonFromAcsEvent::resolve($company->id, [
        'name' => 'Unknown',
        'employeeNoString' => '1034',
    ]);

    expect($resolved['person_hikvision_id'])->toBe('')
        ->and($resolved['hikvision_person_id'])->toBeNull();
});

test('acs event resolves unique name alias omitting trailing initial', function (string $fullName) {
    ['company' => $company] = makeAcsPersonResolutionCompanies();

    $person = HikvisionPerson::query()->create([
        'company_id' => $company->id,
        'person_id' => 'hv-person-alias-1',
        'full_name' => $fullName,
    ]);

    $resolved = ResolveHikvisionPersonFromAcsEvent::resolve($company->id, [
        'name' => 'Mohammed Rabil',
        'attendanceStatus' => 'checkIn',
    ]);

    expect($resolved['person_hikvision_id'])->toBe('hv-person-alias-1')
        ->and($resolved['hikvision_person_id'])->toBe($person->id);
})->with([
    'Mohammed Rabil T',
    'Mohammed Rabil T.',
]);

test('acs event does not treat a surname as a trailing initial alias', function (string $fullName) {
    ['company' => $company] = makeAcsPersonResolutionCompanies();

    HikvisionPerson::query()->create([
        'company_id' => $company->id,
        'person_id' => 'hv-person-surname-1',
        'full_name' => $fullName,
    ]);

    $resolved = ResolveHikvisionPersonFromAcsEvent::resolve($company->id, [
        'name' => explode(' ', $fullName)[0],
    ]);

    expect($resolved['person_hikvision_id'])->toBe('')
        ->and($resolved['hikvision_person_id'])->toBeNull();
})->with([
    'Ahmed Ali',
    'John Doe',
    'Sam Lee',
    'Kim Tan',
]);

test('acs event does not silently attach an ambiguous name match', function () {
    ['company' => $company] = makeAcsPersonResolutionCompanies();

    HikvisionPerson::query()->create([
        'company_id' => $company->id,
        'person_id' => 'hv-ambiguous-a',
        'full_name' => 'Mohammed Rabil T',
    ]);
    HikvisionPerson::query()->create([
        'company_id' => $company->id,
        'person_id' => 'hv-ambiguous-b',
        'full_name' => 'Mohammed Rabil K',
    ]);

    $resolved = ResolveHikvisionPersonFromAcsEvent::resolve($company->id, [
        'name' => 'Mohammed Rabil',
    ]);

    expect($resolved['person_hikvision_id'])->toBe('')
        ->and($resolved['hikvision_person_id'])->toBeNull();
});

test('upsertFromAcsEvent stores person_hikvision_id and hikvision_person_id', function () {
    ['company' => $company] = makeAcsPersonResolutionCompanies();

    $person = HikvisionPerson::query()->create([
        'company_id' => $company->id,
        'person_id' => 'hv-store-identity-1',
        'full_name' => 'Mohammed Rabil T',
    ]);

    $event = HikvisionAccessEvent::upsertFromAcsEvent(
        $company->id,
        [
            'major' => 5,
            'minor' => 75,
            'time' => '2026-09-14T09:03:00+04:00',
            'name' => 'Mohammed Rabil',
            'doorNo' => 1,
            'cardReaderNo' => 1,
            'currentVerifyMode' => 'faceOrFpOrCardOrPw',
            'attendanceStatus' => 'checkIn',
            'serialNo' => 44001,
        ],
        'device-oms-door',
        'OMS-Door',
    );

    expect($event)->not->toBeNull()
        ->and($event->person_hikvision_id)->toBe('hv-store-identity-1')
        ->and($event->hikvision_person_id)->toBe($person->id)
        ->and($event->person_name)->toBe('Mohammed Rabil')
        ->and($event->event_source)->toBe(HikvisionAccessEvent::EVENT_SOURCE_ACS_ISAPI);
});

test('authoritative re-fetch repairs missing identity on existing acs event without duplicating', function () {
    ['company' => $company] = makeAcsPersonResolutionCompanies();

    $person = HikvisionPerson::query()->create([
        'company_id' => $company->id,
        'person_id' => 'hv-repair-identity-1',
        'full_name' => 'Mohammed Rabil T',
    ]);

    $existing = HikvisionAccessEvent::query()->create([
        'company_id' => $company->id,
        'system_id' => 'device-oms-door:2026-09-14T09:03:00+04:00:5:75:1:Mohammed Rabil',
        'msg_type' => 'acs/5/75',
        'occurrence_time' => '2026-09-14 09:03:00',
        'device_id' => 'device-oms-door',
        'device_name' => 'OMS-Door',
        'person_name' => 'Mohammed Rabil',
        'person_hikvision_id' => null,
        'hikvision_person_id' => null,
        'door_no' => '1',
        'card_reader_no' => '1',
        'attendance_status' => HikvisionAccessEvent::ATTENDANCE_CHECK_IN,
        'event_source' => HikvisionAccessEvent::EVENT_SOURCE_ACS_ISAPI,
        'transaction_source' => HikvisionAccessEvent::TRANSACTION_DEVICE,
        'raw_payload' => [
            'serialNo' => 44002,
            'name' => 'Mohammed Rabil',
            'doorNo' => 1,
            'cardReaderNo' => 1,
        ],
        'fetched_at' => now()->subHour(),
    ]);

    $repaired = HikvisionAccessEvent::upsertFromAcsEvent(
        $company->id,
        [
            'major' => 5,
            'minor' => 75,
            'time' => '2026-09-14T09:03:00+04:00',
            'name' => 'Mohammed Rabil',
            'doorNo' => 1,
            'cardReaderNo' => 1,
            'currentVerifyMode' => 'faceOrFpOrCardOrPw',
            'attendanceStatus' => 'checkIn',
            'serialNo' => 44002,
        ],
        'device-oms-door',
        'OMS-Door',
    );

    expect(HikvisionAccessEvent::query()->forCompany($company->id)->count())->toBe(1)
        ->and($repaired?->is($existing))->toBeTrue()
        ->and($repaired->person_hikvision_id)->toBe('hv-repair-identity-1')
        ->and($repaired->hikvision_person_id)->toBe($person->id);
});
