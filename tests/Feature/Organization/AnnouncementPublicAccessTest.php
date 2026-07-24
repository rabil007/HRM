<?php

use App\Enums\AnnouncementStatus;
use App\Models\Announcement;
use App\Models\AnnouncementAttachment;
use App\Models\AnnouncementRecipient;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{company: Company, otherCompany: Company}
 */
function makePublicAnnouncementCompanies(): array
{
    $make = function (string $prefix): Company {
        $code = $prefix.fake()->unique()->numerify('##');
        $country = Country::query()->create([
            'code' => $code,
            'name' => $prefix.'land',
            'dial_code' => '+971',
            'is_active' => true,
        ]);
        $currency = Currency::query()->create([
            'code' => $code,
            'name' => $prefix.' Currency',
            'symbol' => 'P$',
            'is_active' => true,
        ]);

        return Company::query()->create([
            'name' => $prefix.' Co',
            'slug' => strtolower($prefix).'-'.fake()->unique()->numerify('####'),
            'working_days' => [1, 2, 3, 4, 5],
            'country_id' => $country->id,
            'currency_id' => $currency->id,
            'timezone' => 'Asia/Dubai',
            'payroll_cycle' => 'monthly',
            'status' => 'active',
        ]);
    };

    return [
        'company' => $make('Pub'),
        'otherCompany' => $make('Oth'),
    ];
}

/**
 * @return array{
 *     announcement: Announcement,
 *     recipient: AnnouncementRecipient,
 *     attachment: AnnouncementAttachment
 * }
 */
function makePublicAnnouncementBundle(Company $company, array $overrides = []): array
{
    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'work_email' => 'secret@example.test',
        'phone' => '+971501111111',
        'name' => 'Hidden Employee',
    ]);

    $announcement = Announcement::query()->create(array_merge([
        'company_id' => $company->id,
        'title' => 'Safety notice',
        'body_html' => '<p>Wear PPE on site</p>',
        'category' => 'safety',
        'priority' => 'high',
        'status' => AnnouncementStatus::Published,
        'channels' => ['whatsapp', 'email'],
        'published_at' => now(),
        'requires_acknowledgement' => true,
    ], $overrides['announcement'] ?? []));

    $recipient = AnnouncementRecipient::query()->create(array_merge([
        'company_id' => $company->id,
        'announcement_id' => $announcement->id,
        'employee_id' => $employee->id,
        'employee_name' => $employee->name,
        'email' => 'secret@example.test',
        'phone' => '971501111111',
        'public_token' => $overrides['token'] ?? str_repeat('p', 48),
    ], $overrides['recipient'] ?? []));

    Storage::fake('local');
    Storage::disk('local')->put('announcements/demo.pdf', 'attachment-bytes');

    $attachment = AnnouncementAttachment::query()->create([
        'company_id' => $company->id,
        'announcement_id' => $announcement->id,
        'original_name' => 'demo.pdf',
        'stored_name' => 'demo.pdf',
        'disk' => 'local',
        'path' => 'announcements/demo.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 16,
    ]);

    return compact('announcement', 'recipient', 'attachment');
}

test('invalid recipient tokens return 404 for public announcement pages', function () {
    ['company' => $company] = makePublicAnnouncementCompanies();
    makePublicAnnouncementBundle($company);

    $this->get('/announcements/public/'.str_repeat('x', 48))->assertNotFound();
    $this->post('/announcements/public/'.str_repeat('x', 48).'/acknowledge')->assertNotFound();
});

test('recipient can view only their assigned announcement without contact details', function () {
    ['company' => $company] = makePublicAnnouncementCompanies();
    ['recipient' => $recipient] = makePublicAnnouncementBundle($company);

    $this->get('/announcements/public/'.$recipient->public_token)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('public/announcements/show')
            ->where('company_name', 'Pub Co')
            ->where('announcement.title', 'Safety notice')
            ->where('announcement.body_html', '<p>Wear PPE on site</p>')
            ->where('announcement.category', 'Safety')
            ->where('announcement.priority', 'High')
            ->where('announcement.requires_acknowledgement', true)
            ->missing('employee_name')
            ->missing('email')
            ->missing('phone')
            ->missing('employee_id')
        );
});

test('attachments from another announcement or company cannot be downloaded', function () {
    ['company' => $company, 'otherCompany' => $otherCompany] = makePublicAnnouncementCompanies();
    ['recipient' => $recipient, 'attachment' => $ownAttachment] = makePublicAnnouncementBundle($company, [
        'token' => str_repeat('a', 48),
    ]);

    $otherAnnouncement = Announcement::query()->create([
        'company_id' => $company->id,
        'title' => 'Sibling',
        'body_html' => '<p>Sibling</p>',
        'category' => 'general',
        'priority' => 'normal',
        'status' => AnnouncementStatus::Published,
        'channels' => ['email'],
        'published_at' => now(),
    ]);

    $siblingAttachment = AnnouncementAttachment::query()->create([
        'company_id' => $company->id,
        'announcement_id' => $otherAnnouncement->id,
        'original_name' => 'sibling.pdf',
        'stored_name' => 'sibling.pdf',
        'disk' => 'local',
        'path' => 'announcements/demo.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 16,
    ]);

    $foreignAnnouncement = Announcement::query()->create([
        'company_id' => $otherCompany->id,
        'title' => 'Foreign',
        'body_html' => '<p>Foreign</p>',
        'category' => 'general',
        'priority' => 'normal',
        'status' => AnnouncementStatus::Published,
        'channels' => ['email'],
        'published_at' => now(),
    ]);

    $foreignAttachment = AnnouncementAttachment::query()->create([
        'company_id' => $otherCompany->id,
        'announcement_id' => $foreignAnnouncement->id,
        'original_name' => 'foreign.pdf',
        'stored_name' => 'foreign.pdf',
        'disk' => 'local',
        'path' => 'announcements/demo.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 16,
    ]);

    $this->get("/announcements/public/{$recipient->public_token}/attachments/{$ownAttachment->id}")
        ->assertOk();

    $this->get("/announcements/public/{$recipient->public_token}/attachments/{$siblingAttachment->id}")
        ->assertNotFound();

    $this->get("/announcements/public/{$recipient->public_token}/attachments/{$foreignAttachment->id}")
        ->assertNotFound();
});

test('required announcements can be acknowledged idempotently', function () {
    ['company' => $company] = makePublicAnnouncementCompanies();
    ['recipient' => $recipient] = makePublicAnnouncementBundle($company, [
        'token' => str_repeat('b', 48),
    ]);

    $this->post('/announcements/public/'.$recipient->public_token.'/acknowledge')
        ->assertRedirect('/announcements/public/'.$recipient->public_token);

    $first = $recipient->fresh();
    expect($first?->acknowledged_at)->not->toBeNull();

    $this->post('/announcements/public/'.$recipient->public_token.'/acknowledge')
        ->assertRedirect('/announcements/public/'.$recipient->public_token);

    expect($recipient->fresh()?->acknowledged_at?->toIso8601String())
        ->toBe($first?->acknowledged_at?->toIso8601String());
});

test('announcements without acknowledgement requirement cannot be acknowledged', function () {
    ['company' => $company] = makePublicAnnouncementCompanies();
    ['recipient' => $recipient] = makePublicAnnouncementBundle($company, [
        'token' => str_repeat('c', 48),
        'announcement' => [
            'requires_acknowledgement' => false,
        ],
    ]);

    $this->post('/announcements/public/'.$recipient->public_token.'/acknowledge')
        ->assertNotFound();

    expect($recipient->fresh()?->acknowledged_at)->toBeNull();
});

test('draft announcements are not publicly viewable', function () {
    ['company' => $company] = makePublicAnnouncementCompanies();
    ['recipient' => $recipient] = makePublicAnnouncementBundle($company, [
        'token' => str_repeat('d', 48),
        'announcement' => [
            'status' => AnnouncementStatus::Draft,
            'published_at' => null,
        ],
    ]);

    $this->get('/announcements/public/'.$recipient->public_token)->assertNotFound();
});
