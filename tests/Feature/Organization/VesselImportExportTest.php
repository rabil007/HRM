<?php

use App\Imports\VesselsImport;
use App\Models\Client;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\EmployeeSeaService;
use App\Models\Rank;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselType;
use Illuminate\Http\UploadedFile;

/**
 * @param  list<list<string|null>>  $rows
 */
function makeVesselsImportCsv(array $rows, ?array $headers = null): UploadedFile
{
    $header = $headers ?? VesselsImport::templateHeaders();
    $lines = [implode(',', $header)];

    foreach ($rows as $row) {
        $lines[] = implode(',', array_map(
            fn (?string $value): string => $value ?? '',
            $row,
        ));
    }

    return UploadedFile::fake()->createWithContent(
        'vessels.csv',
        implode("\n", $lines)."\n",
    );
}

function makeVesselImportExportFixtures(): array
{
    $user = User::factory()->create();

    $country = Country::query()->create([
        'code' => 'VIE',
        'name' => 'Vessel Import Export Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'VIE',
        'name' => 'Vessel Import Export Currency',
        'symbol' => 'V$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Vessel Import Co',
        'slug' => 'vessel-import-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $otherCompany = Company::query()->create([
        'name' => 'Other Vessel Import Co',
        'slug' => 'other-vessel-import-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $vesselType = VesselType::query()->create([
        'name' => 'AHTS',
        'is_active' => true,
    ]);

    $tugType = VesselType::query()->create([
        'name' => 'Tug',
        'is_active' => true,
    ]);

    $client = Client::query()->create([
        'name' => 'ADNOC',
        'is_active' => true,
    ]);

    $otherClient = Client::query()->create([
        'name' => 'NMDC',
        'is_active' => true,
    ]);

    grantCompanyPermissions($user, $company, [
        'crew_operations.vessels.view',
        'crew_operations.vessels.create',
        'crew_operations.vessels.update',
    ]);

    return compact(
        'user',
        'company',
        'otherCompany',
        'vesselType',
        'tugType',
        'client',
        'otherClient',
    );
}

test('vessel export only includes current company vessels', function () {
    ['user' => $user, 'company' => $company, 'otherCompany' => $otherCompany, 'vesselType' => $vesselType, 'client' => $client] = makeVesselImportExportFixtures();

    $companyVessel = Vessel::query()->create([
        'company_id' => $company->id,
        'client_id' => $client->id,
        'name' => 'Sea Eagle',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    Vessel::query()->create([
        'company_id' => $otherCompany->id,
        'name' => 'Other Company Vessel',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.vessels.export'));

    $response->assertOk();
    $content = $response->streamedContent();

    expect($content)->toContain('Sea Eagle')
        ->and($content)->toContain((string) $companyVessel->id)
        ->and($content)->not->toContain('Other Company Vessel');
});

test('vessel export includes stable vessel_id and user friendly master data names', function () {
    ['user' => $user, 'company' => $company, 'vesselType' => $vesselType, 'client' => $client] = makeVesselImportExportFixtures();

    $vessel = Vessel::query()->create([
        'company_id' => $company->id,
        'client_id' => $client->id,
        'name' => 'Sea Falcon',
        'vessel_type_id' => $vesselType->id,
        'imo_no' => '9559133',
        'official_no' => 'SLR11116',
        'call_sign' => '9LS2029',
        'grt' => 4500,
        'bhp' => 12000,
        'is_active' => true,
    ]);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.vessels.export'));

    $response->assertOk();
    $lines = array_values(array_filter(explode("\n", trim($response->streamedContent()))));
    $header = str_getcsv($lines[0]);
    $row = str_getcsv($lines[1]);

    expect($header)->toBe(VesselsImport::templateHeaders())
        ->and($row[0])->toBe((string) $vessel->id)
        ->and($row[1])->toBe('ADNOC')
        ->and($row[2])->toBe('Sea Falcon')
        ->and($row[3])->toBe('AHTS')
        ->and($row[4])->toBe('9559133')
        ->and($row[5])->toBe('SLR11116')
        ->and($row[6])->toBe('9LS2029')
        ->and($row[7])->toBe('4500.00')
        ->and($row[8])->toBe('12000')
        ->and($row[9])->toBe('yes');
});

test('vessel import template contains existing current-company vessels with stable ids', function () {
    ['user' => $user, 'company' => $company, 'otherCompany' => $otherCompany, 'vesselType' => $vesselType, 'tugType' => $tugType] = makeVesselImportExportFixtures();

    $seaEagle = Vessel::query()->create([
        'company_id' => $company->id,
        'client_id' => null,
        'name' => 'Sea Eagle',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    $seaFalcon = Vessel::query()->create([
        'company_id' => $company->id,
        'client_id' => null,
        'name' => 'Sea Falcon',
        'vessel_type_id' => $tugType->id,
        'is_active' => true,
    ]);

    Vessel::query()->create([
        'company_id' => $otherCompany->id,
        'name' => 'Foreign Vessel',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.vessels.import.template', [
            'company_id' => $otherCompany->id,
        ]));

    $response->assertOk();
    $content = $response->streamedContent();
    $lines = array_values(array_filter(explode("\n", trim($content))));
    $header = str_getcsv($lines[0]);
    $rows = array_map(str_getcsv(...), array_slice($lines, 1));
    $ids = array_column($rows, 0);
    $names = array_column($rows, 2);

    expect($header)->toBe(VesselsImport::templateHeaders())
        ->and($header)->not->toContain('company_id')
        ->and($names)->toContain('Sea Eagle')
        ->and($names)->toContain('Sea Falcon')
        ->and($names)->not->toContain('Foreign Vessel')
        ->and($ids)->toContain((string) $seaEagle->id)
        ->and($ids)->toContain((string) $seaFalcon->id)
        ->and($content)->not->toContain('Foreign Vessel');
});

test('vessel import template exports unassigned client as blank and assigned client by name', function () {
    ['user' => $user, 'company' => $company, 'vesselType' => $vesselType, 'client' => $client] = makeVesselImportExportFixtures();

    $unassigned = Vessel::query()->create([
        'company_id' => $company->id,
        'client_id' => null,
        'name' => 'Sea Eagle',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    $assigned = Vessel::query()->create([
        'company_id' => $company->id,
        'client_id' => $client->id,
        'name' => 'Ocean Pearl',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.vessels.import.template'));

    $response->assertOk();
    $lines = array_values(array_filter(explode("\n", trim($response->streamedContent()))));
    $rowsByName = collect(array_slice($lines, 1))
        ->map(fn (string $line): array => str_getcsv($line))
        ->keyBy(fn (array $row): string => $row[2]);

    expect($rowsByName['Sea Eagle'][0])->toBe((string) $unassigned->id)
        ->and($rowsByName['Sea Eagle'][1])->toBe('')
        ->and($rowsByName['Ocean Pearl'][0])->toBe((string) $assigned->id)
        ->and($rowsByName['Ocean Pearl'][1])->toBe('ADNOC');
});

test('vessel import template for an empty company is header-only', function () {
    ['user' => $user, 'company' => $company] = makeVesselImportExportFixtures();

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.vessels.import.template'));

    $response->assertOk();
    $content = $response->streamedContent();
    $lines = array_values(array_filter(explode("\n", trim($content))));

    expect($lines)->toHaveCount(1)
        ->and(str_getcsv($lines[0]))->toBe(VesselsImport::templateHeaders())
        ->and($content)->not->toContain('Sea Eagle')
        ->and($content)->not->toContain('ADNOC');
});

test('vessel import template round-trip preserves existing records without duplicates or deletes', function () {
    ['user' => $user, 'company' => $company, 'vesselType' => $vesselType, 'client' => $client] = makeVesselImportExportFixtures();

    $vessel = Vessel::query()->create([
        'company_id' => $company->id,
        'client_id' => $client->id,
        'name' => 'Sea Eagle',
        'vessel_type_id' => $vesselType->id,
        'imo_no' => '9559133',
        'official_no' => 'SLR11116',
        'call_sign' => '9LS2029',
        'grt' => 4500,
        'bhp' => 12000,
        'is_active' => true,
    ]);

    $untouched = Vessel::query()->create([
        'company_id' => $company->id,
        'client_id' => $client->id,
        'name' => 'Sea Falcon',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    $vesselId = $vessel->id;
    $untouchedId = $untouched->id;

    $csv = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.vessels.import.template'))
        ->streamedContent();

    $file = UploadedFile::fake()->createWithContent('vessels.csv', $csv);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.vessels.import'), ['file' => $file])
        ->assertRedirect(route('organization.vessels.index'))
        ->assertSessionHas('success');

    $vessel->refresh();
    $untouched->refresh();

    expect(Vessel::query()->where('company_id', $company->id)->count())->toBe(2)
        ->and($vessel->id)->toBe($vesselId)
        ->and($vessel->name)->toBe('Sea Eagle')
        ->and((int) $vessel->client_id)->toBe((int) $client->id)
        ->and($vessel->imo_no)->toBe('9559133')
        ->and($vessel->official_no)->toBe('SLR11116')
        ->and($vessel->call_sign)->toBe('9LS2029')
        ->and($vessel->grt)->toBe('4500.00')
        ->and((int) $vessel->bhp)->toBe(12000)
        ->and($vessel->is_active)->toBeTrue()
        ->and(Vessel::query()->find($untouchedId))->not->toBeNull()
        ->and($untouched->id)->toBe($untouchedId)
        ->and($untouched->name)->toBe('Sea Falcon');
});

test('vessel import template client backfill updates only the client on the same vessel', function () {
    ['user' => $user, 'company' => $company, 'vesselType' => $vesselType, 'client' => $client] = makeVesselImportExportFixtures();

    $vessel = Vessel::query()->create([
        'company_id' => $company->id,
        'client_id' => null,
        'name' => 'Sea Eagle',
        'vessel_type_id' => $vesselType->id,
        'imo_no' => '9559133',
        'official_no' => 'SLR11116',
        'call_sign' => '9LS2029',
        'grt' => 4500,
        'bhp' => 12000,
        'is_active' => true,
    ]);

    $vesselId = $vessel->id;

    $csv = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.vessels.import.template'))
        ->streamedContent();

    $lines = array_values(array_filter(explode("\n", trim($csv))));
    $header = str_getcsv($lines[0]);
    $row = str_getcsv($lines[1]);
    $row[1] = 'ADNOC';

    $handle = fopen('php://temp', 'r+');
    fputcsv($handle, $header);
    fputcsv($handle, $row);
    rewind($handle);
    $updatedCsv = stream_get_contents($handle);
    fclose($handle);

    $file = UploadedFile::fake()->createWithContent('vessels.csv', $updatedCsv);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.vessels.import'), ['file' => $file])
        ->assertRedirect(route('organization.vessels.index'));

    $vessel->refresh();

    expect(Vessel::query()->where('company_id', $company->id)->count())->toBe(1)
        ->and($vessel->id)->toBe($vesselId)
        ->and((int) $vessel->client_id)->toBe((int) $client->id)
        ->and($vessel->name)->toBe('Sea Eagle')
        ->and($vessel->imo_no)->toBe('9559133')
        ->and($vessel->official_no)->toBe('SLR11116')
        ->and($vessel->call_sign)->toBe('9LS2029')
        ->and($vessel->grt)->toBe('4500.00')
        ->and((int) $vessel->bhp)->toBe(12000)
        ->and($vessel->is_active)->toBeTrue();
});

test('vessel import updates existing row by vessel_id without creating duplicate on rename', function () {
    ['user' => $user, 'company' => $company, 'vesselType' => $vesselType, 'client' => $client, 'otherClient' => $otherClient] = makeVesselImportExportFixtures();

    $vessel = Vessel::query()->create([
        'company_id' => $company->id,
        'client_id' => null,
        'name' => 'Sea Eagle',
        'vessel_type_id' => $vesselType->id,
        'imo_no' => '9559133',
        'official_no' => 'SLR11116',
        'call_sign' => '9LS2029',
        'grt' => 4500,
        'bhp' => 12000,
        'is_active' => true,
    ]);

    $vesselId = $vessel->id;

    $file = makeVesselsImportCsv([
        [(string) $vesselId, 'NMDC', 'Sea Eagle II', 'AHTS', '9559133', 'SLR11116', '9LS2029', '4500', '12000', 'yes'],
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.vessels.import'), ['file' => $file])
        ->assertRedirect(route('organization.vessels.index'))
        ->assertSessionHas('success');

    $vessel->refresh();

    expect(Vessel::query()->where('company_id', $company->id)->count())->toBe(1)
        ->and($vessel->id)->toBe($vesselId)
        ->and($vessel->name)->toBe('Sea Eagle II')
        ->and((int) $vessel->client_id)->toBe((int) $otherClient->id)
        ->and($vessel->imo_no)->toBe('9559133');
});

test('vessel import client only change preserves other supplied values', function () {
    ['user' => $user, 'company' => $company, 'vesselType' => $vesselType, 'client' => $client, 'otherClient' => $otherClient] = makeVesselImportExportFixtures();

    $vessel = Vessel::query()->create([
        'company_id' => $company->id,
        'client_id' => $client->id,
        'name' => 'Sea Eagle',
        'vessel_type_id' => $vesselType->id,
        'imo_no' => '9559133',
        'official_no' => 'SLR11116',
        'call_sign' => '9LS2029',
        'grt' => 4500,
        'bhp' => 12000,
        'is_active' => true,
    ]);

    $file = makeVesselsImportCsv([
        [(string) $vessel->id, 'NMDC', 'Sea Eagle', 'AHTS', '9559133', 'SLR11116', '9LS2029', '4500', '12000', 'yes'],
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.vessels.import'), ['file' => $file])
        ->assertRedirect(route('organization.vessels.index'));

    $vessel->refresh();

    expect((int) $vessel->client_id)->toBe((int) $otherClient->id)
        ->and($vessel->name)->toBe('Sea Eagle')
        ->and($vessel->official_no)->toBe('SLR11116')
        ->and((int) $vessel->bhp)->toBe(12000);
});

test('blank vessel_id creates a new company scoped vessel', function () {
    ['user' => $user, 'company' => $company, 'tugType' => $tugType, 'client' => $client] = makeVesselImportExportFixtures();

    $file = makeVesselsImportCsv([
        ['', 'ADNOC', 'Sea Pearl', 'Tug', '1234567', 'ABC998', '9LS9988', '2800', '7500', 'yes'],
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.vessels.import'), ['file' => $file])
        ->assertRedirect(route('organization.vessels.index'));

    $this->assertDatabaseHas('vessels', [
        'company_id' => $company->id,
        'name' => 'Sea Pearl',
        'client_id' => $client->id,
        'vessel_type_id' => $tugType->id,
        'imo_no' => '1234567',
        'bhp' => 7500,
    ]);
});

test('blank vessel_id with existing vessel name is rejected instead of duplicating', function () {
    ['user' => $user, 'company' => $company, 'vesselType' => $vesselType, 'client' => $client] = makeVesselImportExportFixtures();

    $existing = Vessel::query()->create([
        'company_id' => $company->id,
        'client_id' => $client->id,
        'name' => 'Sea Eagle',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    $file = makeVesselsImportCsv([
        ['', 'ADNOC', 'Sea Eagle', 'AHTS', '', '', '', '', '', 'yes'],
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.vessels.import.preview'), ['file' => $file])
        ->assertOk()
        ->assertJsonPath('summary.importable', 0)
        ->assertJsonPath('rows.0.errors.0.field', 'name')
        ->assertJson(fn ($json) => $json
            ->where('rows.0.errors.0.message', fn ($message) => str_contains($message, (string) $existing->id))
            ->etc());

    expect(Vessel::query()->where('company_id', $company->id)->where('name', 'Sea Eagle')->count())->toBe(1);
});

test('vessel import preview rejects cross company and invalid vessel ids', function () {
    ['user' => $user, 'company' => $company, 'otherCompany' => $otherCompany, 'vesselType' => $vesselType, 'client' => $client] = makeVesselImportExportFixtures();

    $otherVessel = Vessel::query()->create([
        'company_id' => $otherCompany->id,
        'name' => 'Foreign Vessel',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    $file = makeVesselsImportCsv([
        [(string) $otherVessel->id, 'ADNOC', 'Sea Eagle', 'AHTS', '', '', '', '', '', 'yes'],
        ['999999', 'ADNOC', 'Sea Hawk', 'AHTS', '', '', '', '', '', 'yes'],
    ]);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.vessels.import.preview'), ['file' => $file])
        ->assertOk();

    $response->assertJsonPath('summary.errors', 2)
        ->assertJsonPath('rows.0.errors.0.field', 'vessel_id')
        ->assertJsonPath('rows.1.errors.0.field', 'vessel_id');
});

test('vessel import preview rejects unknown client and vessel type', function () {
    ['user' => $user, 'company' => $company] = makeVesselImportExportFixtures();

    $file = makeVesselsImportCsv([
        ['', 'ABC Offshore', 'Sea Pearl', 'AHSV', '', '', '', '', '', 'yes'],
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.vessels.import.preview'), ['file' => $file])
        ->assertOk()
        ->assertJsonPath('summary.importable', 0)
        ->assertJson(fn ($json) => $json
            ->has('rows.0.errors', 2)
            ->etc());
});

test('vessel import preview detects duplicate new vessel names in one upload', function () {
    ['user' => $user, 'company' => $company, 'vesselType' => $vesselType, 'client' => $client] = makeVesselImportExportFixtures();

    $file = makeVesselsImportCsv([
        ['', 'ADNOC', 'TAWAM 1', 'AHTS', '', '', '', '', '', 'yes'],
        ['', 'ADNOC', 'TAWAM 1', 'AHTS', '', '', '', '', '', 'yes'],
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.vessels.import.preview'), ['file' => $file])
        ->assertOk()
        ->assertJsonPath('summary.errors', 1)
        ->assertJsonPath('rows.1.errors.0.field', 'name')
        ->assertJsonPath(
            'rows.1.errors.0.message',
            'TAWAM 1 appears more than once in this upload (first seen on row 2).',
        );
});

test('vessel import skips duplicate new vessel names instead of failing the request', function () {
    ['user' => $user, 'company' => $company, 'vesselType' => $vesselType, 'client' => $client] = makeVesselImportExportFixtures();

    $file = makeVesselsImportCsv([
        ['', 'ADNOC', 'TAWAM 1', 'AHTS', '', '', '', '', '', 'yes'],
        ['', 'ADNOC', 'TAWAM 1', 'AHTS', '', '', '', '', '', 'yes'],
        ['', 'ADNOC', 'Sea Eagle', 'AHTS', '', '', '', '', '', 'yes'],
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.vessels.import'), ['file' => $file])
        ->assertRedirect(route('organization.vessels.index'))
        ->assertSessionHas('success');

    expect(Vessel::query()->where('company_id', $company->id)->count())->toBe(2)
        ->and(Vessel::query()->where('company_id', $company->id)->where('name', 'TAWAM 1')->count())->toBe(1)
        ->and(Vessel::query()->where('company_id', $company->id)->where('name', 'Sea Eagle')->exists())->toBeTrue();
});

test('vessel import preview detects duplicate vessel_id rows in one upload', function () {
    ['user' => $user, 'company' => $company, 'vesselType' => $vesselType, 'client' => $client] = makeVesselImportExportFixtures();

    $vessel = Vessel::query()->create([
        'company_id' => $company->id,
        'client_id' => $client->id,
        'name' => 'Sea Eagle',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    $file = makeVesselsImportCsv([
        [(string) $vessel->id, 'ADNOC', 'Sea Eagle', 'AHTS', '', '', '', '', '', 'yes'],
        [(string) $vessel->id, 'ADNOC', 'Sea Eagle II', 'AHTS', '', '', '', '', '', 'yes'],
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.vessels.import.preview'), ['file' => $file])
        ->assertOk()
        ->assertJsonPath('summary.errors', 1)
        ->assertJsonPath('rows.1.errors.0.field', 'vessel_id');
});

test('vessel import does not delete vessels missing from csv', function () {
    ['user' => $user, 'company' => $company, 'vesselType' => $vesselType, 'client' => $client] = makeVesselImportExportFixtures();

    $first = Vessel::query()->create([
        'company_id' => $company->id,
        'client_id' => $client->id,
        'name' => 'Vessel One',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    $second = Vessel::query()->create([
        'company_id' => $company->id,
        'client_id' => $client->id,
        'name' => 'Vessel Two',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    $third = Vessel::query()->create([
        'company_id' => $company->id,
        'client_id' => $client->id,
        'name' => 'Vessel Three',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    $file = makeVesselsImportCsv([
        [(string) $first->id, 'ADNOC', 'Vessel One Updated', 'AHTS', '', '', '', '', '', 'yes'],
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.vessels.import'), ['file' => $file])
        ->assertRedirect(route('organization.vessels.index'));

    expect(Vessel::query()->where('company_id', $company->id)->count())->toBe(3)
        ->and(Vessel::query()->find($second->id))->not->toBeNull()
        ->and(Vessel::query()->find($third->id))->not->toBeNull()
        ->and(Vessel::query()->find($first->id)?->name)->toBe('Vessel One Updated');
});

test('vessel import changing client does not rewrite crew assignment or sea service snapshots', function () {
    ['user' => $user, 'company' => $company, 'vesselType' => $vesselType, 'client' => $client, 'otherClient' => $otherClient] = makeVesselImportExportFixtures();

    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $rank = Rank::query()->create(['name' => 'Master', 'is_active' => true]);

    $vessel = Vessel::query()->create([
        'company_id' => $company->id,
        'client_id' => $client->id,
        'name' => 'Sea Eagle',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel, [
        'client_id' => $client->id,
    ]);

    $seaService = EmployeeSeaService::factory()->forEmployee($employee)->create([
        'vessel_type_id' => $vesselType->id,
        'vessel_id' => $vessel->id,
        'client_id' => $client->id,
    ]);

    $file = makeVesselsImportCsv([
        [(string) $vessel->id, 'NMDC', 'Sea Eagle', 'AHTS', '', '', '', '', '', 'yes'],
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.vessels.import'), ['file' => $file])
        ->assertRedirect(route('organization.vessels.index'));

    expect((int) $vessel->fresh()->client_id)->toBe((int) $otherClient->id)
        ->and((int) $assignment->fresh()->client_id)->toBe((int) $client->id)
        ->and((int) $seaService->fresh()->client_id)->toBe((int) $client->id);
});

test('legacy vessel csv without vessel_id still creates new rows', function () {
    ['user' => $user, 'company' => $company, 'vesselType' => $vesselType, 'client' => $client] = makeVesselImportExportFixtures();

    VesselType::query()->whereKey($vesselType->id)->update(['name' => 'H/LIFT']);

    $csv = "client,name,vessel_type,grt,bhp,is_active\n{$client->name},SAPURA 1200,H/LIFT,5000,9000,yes\n";
    $file = UploadedFile::fake()->createWithContent('vessels.csv', $csv);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.vessels.import'), ['file' => $file])
        ->assertRedirect(route('organization.vessels.index'));

    $this->assertDatabaseHas('vessels', [
        'company_id' => $company->id,
        'name' => 'SAPURA 1200',
        'grt' => '5000.00',
        'bhp' => 9000,
    ]);
});

test('vessel import preview reports creates updates and zero deletes', function () {
    ['user' => $user, 'company' => $company, 'vesselType' => $vesselType, 'client' => $client] = makeVesselImportExportFixtures();

    $existing = Vessel::query()->create([
        'company_id' => $company->id,
        'client_id' => $client->id,
        'name' => 'Sea Eagle',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    $file = makeVesselsImportCsv([
        [(string) $existing->id, 'ADNOC', 'Sea Eagle', 'AHTS', '', '', '', '', '', 'yes'],
        ['', 'ADNOC', 'Sea Pearl', 'AHTS', '', '', '', '', '', 'yes'],
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.vessels.import.preview'), ['file' => $file])
        ->assertOk()
        ->assertJsonPath('summary.total', 2)
        ->assertJsonPath('summary.updates', 1)
        ->assertJsonPath('summary.creates', 1)
        ->assertJsonPath('summary.deletes', 0);
});
