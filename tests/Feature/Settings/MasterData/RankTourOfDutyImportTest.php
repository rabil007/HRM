<?php

use App\Models\Rank;
use Illuminate\Http\UploadedFile;

beforeEach(function () {
    $this->fixtures = makeCrewAssignmentFixtures();
    $this->user = $this->fixtures['user'];
    $this->company = $this->fixtures['company'];
    $this->user->update(['current_company_id' => $this->company->id]);
    grantCompanyPermissions($this->user, $this->company, [
        'settings.master-data.ranks.view',
        'settings.master-data.ranks.create',
        'settings.master-data.ranks.update',
    ]);
});

it('imports max tour of duty days from csv', function () {
    $csv = "name,is_active,max_tour_of_duty_days\nTour Import Captain,yes,90\n";
    $file = UploadedFile::fake()->createWithContent('ranks.csv', $csv);

    $this->actingAs($this->user)
        ->post(route('settings.master-data.ranks.import'), ['file' => $file])
        ->assertRedirect(route('settings.master-data.ranks.index'));

    $rank = Rank::query()->where('name', 'Tour Import Captain')->first();

    expect($rank)->not->toBeNull()
        ->and($rank->max_tour_of_duty_days)->toBe(90);
});

it('rejects out of range tour of duty values and skips those rows', function () {
    $csv = "name,is_active,max_tour_of_duty_days\nBad Tour Rank,yes,999\nGood Tour Rank,yes,75\n";
    $file = UploadedFile::fake()->createWithContent('ranks.csv', $csv);

    $this->actingAs($this->user)
        ->post(route('settings.master-data.ranks.import'), ['file' => $file])
        ->assertRedirect(route('settings.master-data.ranks.index'))
        ->assertSessionHas('success');

    expect(Rank::query()->where('name', 'Bad Tour Rank')->exists())->toBeFalse()
        ->and(Rank::query()->where('name', 'Good Tour Rank')->value('max_tour_of_duty_days'))->toBe(75);
});

it('preserves existing tour of duty when csv cell is blank', function () {
    $rank = Rank::query()->create([
        'name' => 'Preserve Tour Rank',
        'is_active' => true,
        'max_tour_of_duty_days' => 88,
    ]);

    $csv = "name,is_active,max_tour_of_duty_days\nPreserve Tour Rank,yes,\n";
    $file = UploadedFile::fake()->createWithContent('ranks.csv', $csv);

    $this->actingAs($this->user)
        ->post(route('settings.master-data.ranks.import'), ['file' => $file])
        ->assertRedirect();

    expect($rank->fresh()->max_tour_of_duty_days)->toBe(88);
});

it('downloads template including tour of duty column', function () {
    $this->actingAs($this->user)
        ->get(route('settings.master-data.ranks.import.template'))
        ->assertOk()
        ->assertSee('max_tour_of_duty_days', false);
});
