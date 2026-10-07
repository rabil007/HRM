<?php

use App\Enums\EmailTemplateCategory;
use App\Models\EmailTemplate;
use Database\Seeders\RequirementEmailTemplatesSeeder;

test('requirement email templates seeder creates all six built-in requirement templates', function () {
    EmailTemplate::query()->whereIn('slug', RequirementEmailTemplatesSeeder::SLUGS)->forceDelete();

    (new RequirementEmailTemplatesSeeder)->run();

    foreach (RequirementEmailTemplatesSeeder::SLUGS as $slug) {
        $template = EmailTemplate::query()->where('slug', $slug)->first();

        expect($template)->not->toBeNull()
            ->and($template->category)->toBe(EmailTemplateCategory::Recruitment)
            ->and($template->enabled)->toBeTrue()
            ->and($template->subject)->toContain('{{requirement_number}}')
            ->and($template->body_html)->not->toBe('');
    }
});

test('requirement email templates seeder is idempotent', function () {
    (new RequirementEmailTemplatesSeeder)->run();
    (new RequirementEmailTemplatesSeeder)->run();

    foreach (RequirementEmailTemplatesSeeder::SLUGS as $slug) {
        expect(EmailTemplate::query()->where('slug', $slug)->count())->toBe(1);
    }
});

test('requirement email templates seeder preserves customized and disabled requirement templates', function () {
    (new RequirementEmailTemplatesSeeder)->run();

    EmailTemplate::query()->where('slug', 'requirement_target_date_due_today')->update([
        'subject' => 'Custom due today {{requirement_number}}',
        'body_html' => 'Custom due today body {{requirement_number}}',
        'enabled' => false,
    ]);

    EmailTemplate::query()->where('slug', 'requirement_approved')->update([
        'subject' => 'Custom approved {{requirement_number}}',
        'body_html' => 'Custom approved body',
        'enabled' => false,
    ]);

    (new RequirementEmailTemplatesSeeder)->run();

    $dueToday = EmailTemplate::query()->where('slug', 'requirement_target_date_due_today')->firstOrFail();
    $approved = EmailTemplate::query()->where('slug', 'requirement_approved')->firstOrFail();

    expect($dueToday->subject)->toBe('Custom due today {{requirement_number}}')
        ->and($dueToday->body_html)->toBe('Custom due today body {{requirement_number}}')
        ->and($dueToday->enabled)->toBeFalse()
        ->and($approved->subject)->toBe('Custom approved {{requirement_number}}')
        ->and($approved->body_html)->toBe('Custom approved body')
        ->and($approved->enabled)->toBeFalse();
});

test('requirement email templates seeder does not restore unrelated soft-deleted built-in templates', function () {
    $unrelated = EmailTemplate::query()->updateOrCreate(
        ['slug' => 'payslip_delivery'],
        [
            'label' => 'Payslip Delivery',
            'category' => 'payroll',
            'subject' => 'Custom payslip {{period_name}}',
            'body_html' => 'Custom payslip body',
            'enabled' => false,
            'is_default' => false,
        ],
    );

    $unrelated->update([
        'subject' => 'Soft deleted payslip {{period_name}}',
        'body_html' => 'Soft deleted payslip body',
    ]);
    $unrelated->delete();

    (new RequirementEmailTemplatesSeeder)->run();

    $restored = EmailTemplate::withTrashed()->where('slug', 'payslip_delivery')->firstOrFail();

    expect($restored->trashed())->toBeTrue()
        ->and($restored->subject)->toBe('Soft deleted payslip {{period_name}}')
        ->and($restored->body_html)->toBe('Soft deleted payslip body');
});
