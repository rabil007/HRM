<?php

use App\Models\EmailTemplate;
use Database\Seeders\EmailTemplatesSeeder;

test('leave email template seeding preserves administrator customizations', function () {
    EmailTemplatesSeeder::seedLeaveRequestSubmittedTemplate();
    EmailTemplatesSeeder::seedLeaveRequestUpdatedTemplate();
    EmailTemplatesSeeder::seedLeaveRequestApprovedTemplate();

    EmailTemplate::query()->where('slug', 'leave_request_submitted')->update([
        'subject' => 'Custom subject {{employee_name}}',
        'body_html' => 'Custom body {{leave_type}}',
        'enabled' => false,
        'to_preset' => 'hr@example.com',
        'cc_preset' => 'audit@example.com',
        'include_company_footer' => false,
    ]);

    EmailTemplate::query()->where('slug', 'leave_request_updated')->update([
        'subject' => 'Custom updated subject',
        'enabled' => false,
    ]);

    EmailTemplate::query()->where('slug', 'leave_request_approver_action_required')->delete();

    (new EmailTemplatesSeeder)->run();
    (new EmailTemplatesSeeder)->run();

    $submitted = EmailTemplate::query()->where('slug', 'leave_request_submitted')->firstOrFail();
    $updated = EmailTemplate::query()->where('slug', 'leave_request_updated')->firstOrFail();
    $actionRequired = EmailTemplate::query()->where('slug', 'leave_request_approver_action_required')->firstOrFail();

    expect($submitted->subject)->toBe('Custom subject {{employee_name}}')
        ->and($submitted->body_html)->toBe('Custom body {{leave_type}}')
        ->and($submitted->enabled)->toBeFalse()
        ->and($submitted->to_preset)->toBe('hr@example.com')
        ->and($submitted->cc_preset)->toBe('audit@example.com')
        ->and($submitted->include_company_footer)->toBeFalse()
        ->and($updated->subject)->toBe('Custom updated subject')
        ->and($updated->enabled)->toBeFalse()
        ->and($actionRequired->enabled)->toBeTrue()
        ->and($actionRequired->subject)->toContain('{{employee_name}}')
        ->and(EmailTemplate::query()->where('slug', 'leave_request_approver_action_required')->count())->toBe(1);
});
