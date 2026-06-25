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

    expect(EmailTemplate::query()->where('slug', 'payslip_delivery')->count())->toBe(1)
        ->and(EmailTemplate::query()->where('slug', 'leave_request_submitted')->count())->toBe(1)
        ->and(EmailTemplate::query()->where('slug', 'leave_request_approved')->count())->toBe(1)
        ->and(EmailTemplate::query()->where('slug', 'leave_request_rejected')->count())->toBe(1);
});

test('email templates seeder creates default leave request submitted template', function () {
    EmailTemplate::query()->where('slug', 'leave_request_submitted')->forceDelete();

    (new EmailTemplatesSeeder)->run();

    $template = EmailTemplate::query()->where('slug', 'leave_request_submitted')->first();

    expect($template)->not->toBeNull()
        ->and($template->category)->toBe(EmailTemplateCategory::Hr)
        ->and($template->is_default)->toBeTrue()
        ->and($template->enabled)->toBeTrue()
        ->and($template->subject)->toContain('{{employee_name}}')
        ->and($template->subject)->toContain('{{leave_type}}')
        ->and($template->body_html)->toContain('{{employee_name}}')
        ->and($template->body_html)->toContain('{{leave_type}}');
});

test('email templates seeder creates default leave request approved template', function () {
    EmailTemplate::query()->where('slug', 'leave_request_approved')->forceDelete();

    (new EmailTemplatesSeeder)->run();

    $template = EmailTemplate::query()->where('slug', 'leave_request_approved')->first();

    expect($template)->not->toBeNull()
        ->and($template->category)->toBe(EmailTemplateCategory::Hr)
        ->and($template->is_default)->toBeFalse()
        ->and($template->enabled)->toBeTrue()
        ->and($template->subject)->toContain('{{leave_type}}')
        ->and($template->body_html)->toContain('{{employee_name}}')
        ->and($template->body_html)->toContain('{{leave_type}}');
});

test('email templates seeder creates default leave request rejected template', function () {
    EmailTemplate::query()->where('slug', 'leave_request_rejected')->forceDelete();

    (new EmailTemplatesSeeder)->run();

    $template = EmailTemplate::query()->where('slug', 'leave_request_rejected')->first();

    expect($template)->not->toBeNull()
        ->and($template->category)->toBe(EmailTemplateCategory::Hr)
        ->and($template->is_default)->toBeFalse()
        ->and($template->enabled)->toBeTrue()
        ->and($template->subject)->toContain('{{leave_type}}')
        ->and($template->body_html)->toContain('{{rejection_reason}}')
        ->and($template->body_html)->toContain('{{employee_name}}')
        ->and($template->body_html)->toContain('{{leave_type}}');
});
