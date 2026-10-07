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
        ->and(EmailTemplate::query()->where('slug', 'leave_request_updated')->count())->toBe(1)
        ->and(EmailTemplate::query()->where('slug', 'leave_request_approver_action_required')->count())->toBe(1)
        ->and(EmailTemplate::query()->where('slug', 'leave_request_notification_only')->count())->toBe(1)
        ->and(EmailTemplate::query()->where('slug', 'leave_request_approved')->count())->toBe(1)
        ->and(EmailTemplate::query()->where('slug', 'leave_request_rejected')->count())->toBe(1)
        ->and(EmailTemplate::query()->where('slug', 'user_invitation')->count())->toBe(1);
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

test('email templates seeder creates default password reset template', function () {
    EmailTemplate::query()->where('slug', 'password_reset')->forceDelete();

    (new EmailTemplatesSeeder)->run();

    $template = EmailTemplate::query()->where('slug', 'password_reset')->first();

    expect($template)->not->toBeNull()
        ->and($template->category)->toBe(EmailTemplateCategory::Notification)
        ->and($template->is_default)->toBeFalse()
        ->and($template->enabled)->toBeTrue()
        ->and($template->subject)->toContain('{{brand_name}}')
        ->and($template->body_html)->toContain('{{user_name}}')
        ->and($template->body_html)->toContain('{{reset_url}}')
        ->and($template->body_html)->toContain('{{expire_minutes}}');
});

test('email templates seeder creates default user invitation template', function () {
    EmailTemplate::query()->where('slug', 'user_invitation')->forceDelete();

    (new EmailTemplatesSeeder)->run();

    $template = EmailTemplate::query()->where('slug', 'user_invitation')->first();

    expect($template)->not->toBeNull()
        ->and($template->category)->toBe(EmailTemplateCategory::Notification)
        ->and($template->is_default)->toBeFalse()
        ->and($template->enabled)->toBeTrue()
        ->and($template->subject)->toContain('{{company_name}}')
        ->and($template->body_html)->toContain('{{invitee_name}}')
        ->and($template->body_html)->toContain('{{inviter_name}}')
        ->and($template->body_html)->toContain('{{accept_url}}')
        ->and($template->body_html)->toContain('{{expires_at}}');
});

test('email templates seeder preserves customized user invitation template', function () {
    $template = EmailTemplatesSeeder::seedUserInvitationTemplate();
    $template->update([
        'subject' => 'Custom invite subject',
        'body_html' => 'Custom invite body',
    ]);

    (new EmailTemplatesSeeder)->run();

    $template->refresh();

    expect($template->subject)->toBe('Custom invite subject')
        ->and($template->body_html)->toBe('Custom invite body');
});

test('email templates seeder creates crew movement correction decided template', function () {
    EmailTemplate::query()->where('slug', 'crew_movement_correction_decided')->forceDelete();

    (new EmailTemplatesSeeder)->run();

    $template = EmailTemplate::query()->where('slug', 'crew_movement_correction_decided')->first();

    expect($template)->not->toBeNull()
        ->and($template->category)->toBe(EmailTemplateCategory::Notification)
        ->and($template->enabled)->toBeTrue()
        ->and($template->subject)->toContain('{{status}}')
        ->and($template->body_html)->toContain('{{assignment_no}}')
        ->and($template->body_html)->toContain('{{employee_name}}');
});

test('email templates seeder preserves customized recruitment requirement templates', function () {
    (new EmailTemplatesSeeder)->run();

    $template = EmailTemplate::query()
        ->where('slug', 'requirement_target_date_due_today')
        ->firstOrFail();

    $template->update([
        'subject' => 'Custom due today {{requirement_number}}',
        'body_html' => 'Custom due today body {{requirement_number}}',
        'enabled' => false,
    ]);

    (new EmailTemplatesSeeder)->run();

    $template->refresh();

    expect($template->subject)->toBe('Custom due today {{requirement_number}}')
        ->and($template->body_html)->toBe('Custom due today body {{requirement_number}}')
        ->and($template->enabled)->toBeFalse();
});

test('email templates seeder creates recruitment requirement templates', function () {
    $slugs = [
        'requirement_submitted_for_approval',
        'requirement_assigned_for_approval',
        'requirement_approved',
        'requirement_returned',
        'requirement_target_date_three_days_before',
        'requirement_target_date_due_today',
        'requirement_deadline_extension_requested',
        'requirement_deadline_extension_approved',
        'requirement_deadline_extension_rejected',
        'requirement_deadline_extended_by_requester',
    ];

    EmailTemplate::query()->whereIn('slug', $slugs)->forceDelete();

    (new EmailTemplatesSeeder)->run();

    foreach ($slugs as $slug) {
        $template = EmailTemplate::query()->where('slug', $slug)->first();

        expect($template)->not->toBeNull()
            ->and($template->category)->toBe(EmailTemplateCategory::Recruitment)
            ->and($template->enabled)->toBeTrue()
            ->and($template->subject)->toContain('{{requirement_number}}')
            ->and($template->body_html)->not->toBe('');
    }
});
