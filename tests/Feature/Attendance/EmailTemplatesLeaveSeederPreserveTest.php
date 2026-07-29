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
        'is_default' => false,
    ]);

    $otherDefault = EmailTemplate::query()->updateOrCreate(
        ['slug' => 'hr_other_default_marker'],
        [
            'label' => 'Other HR Default',
            'category' => 'hr',
            'subject' => 'Other default',
            'body_html' => 'Body',
            'enabled' => true,
            'is_default' => true,
            'to_preset' => null,
            'cc_preset' => null,
            'include_company_footer' => true,
        ],
    );

    EmailTemplate::query()->where('slug', 'leave_request_updated')->update([
        'subject' => 'Custom updated subject',
        'enabled' => false,
        'is_default' => false,
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
        ->and($submitted->is_default)->toBeFalse()
        ->and($updated->subject)->toBe('Custom updated subject')
        ->and($updated->enabled)->toBeFalse()
        ->and($updated->is_default)->toBeFalse()
        ->and($otherDefault->fresh()->is_default)->toBeTrue()
        ->and($actionRequired->enabled)->toBeTrue()
        ->and($actionRequired->subject)->toContain('{{employee_name}}')
        ->and(EmailTemplate::query()->where('slug', 'leave_request_approver_action_required')->count())->toBe(1);
});

test('soft-deleted leave email template slug does not cause unique violation on reseed', function () {
    EmailTemplatesSeeder::seedLeaveRequestApprovedTemplate();

    $approved = EmailTemplate::query()->where('slug', 'leave_request_approved')->firstOrFail();
    $approved->update([
        'subject' => 'Soft deleted custom subject',
        'enabled' => false,
        'is_default' => false,
    ]);
    $approved->delete();

    (new EmailTemplatesSeeder)->run();
    (new EmailTemplatesSeeder)->run();

    $restored = EmailTemplate::withTrashed()->where('slug', 'leave_request_approved')->get();

    expect($restored)->toHaveCount(1)
        ->and($restored->first()->trashed())->toBeFalse()
        ->and($restored->first()->subject)->toBe('Soft deleted custom subject')
        ->and($restored->first()->enabled)->toBeFalse()
        ->and($restored->first()->is_default)->toBeFalse();
});
