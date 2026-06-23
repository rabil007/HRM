<?php

use App\Enums\EmailTemplateCategory;
use App\Models\EmailTemplate;
use Database\Seeders\EmailTemplatesSeeder;

test('email templates seeder creates default payslip delivery template', function () {
    EmailTemplate::query()->where('slug', 'payslip_delivery')->forceDelete();

    (new EmailTemplatesSeeder)->run();

    $template = EmailTemplate::query()->where('slug', 'payslip_delivery')->first();

    expect($template)->not->toBeNull()
        ->and($template->category)->toBe(EmailTemplateCategory::Payroll)
        ->and($template->is_default)->toBeTrue()
        ->and($template->enabled)->toBeTrue()
        ->and($template->subject)->toContain('{{period_name}}')
        ->and($template->body_html)->toContain('{{employee_name}}')
        ->and($template->body_html)->toContain('{{net_salary}}')
        ->and($template->body_html)->toContain('{{company_name}}');
});

test('email templates seeder is idempotent', function () {
    (new EmailTemplatesSeeder)->run();
    (new EmailTemplatesSeeder)->run();

    expect(EmailTemplate::query()->where('slug', 'payslip_delivery')->count())->toBe(1);
});
