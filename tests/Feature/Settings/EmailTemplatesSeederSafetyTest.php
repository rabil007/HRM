<?php

use App\Models\EmailTemplate;
use App\Support\Email\BuiltInEmailTemplates;
use App\Support\Email\EmailTemplatePreview;
use Database\Seeders\EmailTemplatesSeeder;

test('email templates seeder creates every required built-in template with meaningful metadata', function () {
    EmailTemplate::query()->whereIn('slug', BuiltInEmailTemplates::slugs())->forceDelete();

    (new EmailTemplatesSeeder)->run();

    foreach (BuiltInEmailTemplates::slugs() as $slug) {
        $template = EmailTemplate::query()->where('slug', $slug)->first();
        $definition = BuiltInEmailTemplates::definition($slug);

        expect($template)->not->toBeNull("Missing built-in template [{$slug}]")
            ->and($template->label)->not->toBe('')
            ->and($template->subject)->not->toBe('')
            ->and($template->body_html)->not->toBe('')
            ->and($template->subject)->not->toContain('Hello...')
            ->and($template->body_html)->not->toContain('Test message')
            ->and($template->body_html)->not->toContain('Automated expiry summary email.')
            ->and($template->body_html)->not->toContain('Automated company document expiry summary email.')
            ->and($template->subject)->toBe($definition['subject'])
            ->and($template->body_html)->toBe($definition['body_html']);
    }
});

test('email templates seeder is idempotent across every built-in slug', function () {
    (new EmailTemplatesSeeder)->run();
    (new EmailTemplatesSeeder)->run();

    foreach (BuiltInEmailTemplates::slugs() as $slug) {
        expect(EmailTemplate::query()->where('slug', $slug)->count())->toBe(1);
    }
});

test('email templates seeder preserves administrator customizations', function () {
    (new EmailTemplatesSeeder)->run();

    $template = EmailTemplate::query()->where('slug', 'payslip_delivery')->firstOrFail();
    $template->update([
        'subject' => 'Custom payslip subject {{period_name}}',
        'body_html' => 'Custom payslip body {{employee_name}}',
        'to_preset' => 'payroll@example.com',
        'cc_preset' => 'audit@example.com',
        'enabled' => false,
        'include_company_footer' => false,
    ]);

    (new EmailTemplatesSeeder)->run();

    $template->refresh();

    expect($template->subject)->toBe('Custom payslip subject {{period_name}}')
        ->and($template->body_html)->toBe('Custom payslip body {{employee_name}}')
        ->and($template->to_preset)->toBe('payroll@example.com')
        ->and($template->cc_preset)->toBe('audit@example.com')
        ->and($template->enabled)->toBeFalse()
        ->and($template->include_company_footer)->toBeFalse();
});

test('soft-deleted required system template is restored without clobbering custom content', function () {
    EmailTemplatesSeeder::seedPasswordResetTemplate();

    $template = EmailTemplate::query()->where('slug', 'password_reset')->firstOrFail();
    $template->update([
        'subject' => 'Custom reset subject',
        'body_html' => 'Custom reset body {{reset_url}}',
        'enabled' => false,
        'include_company_footer' => false,
    ]);
    $template->delete();

    (new EmailTemplatesSeeder)->run();

    $restored = EmailTemplate::withTrashed()->where('slug', 'password_reset')->get();

    expect($restored)->toHaveCount(1)
        ->and($restored->first()->trashed())->toBeFalse()
        ->and($restored->first()->subject)->toBe('Custom reset subject')
        ->and($restored->first()->body_html)->toBe('Custom reset body {{reset_url}}')
        ->and($restored->first()->enabled)->toBeFalse()
        ->and($restored->first()->include_company_footer)->toBeFalse();
});

test('known stock defaults are upgraded and customized content is not', function () {
    (new EmailTemplatesSeeder)->run();

    $share = EmailTemplate::query()->where('slug', 'document_share')->firstOrFail();
    $share->update([
        'subject' => 'Documents from Overseas Marine Services',
        'body_html' => "Hello,\n\nPlease find the attached employee documents.\n\nThank you.",
    ]);

    $custom = EmailTemplate::query()->where('slug', 'password_reset')->firstOrFail();
    $custom->update([
        'subject' => 'Keep my custom reset subject',
        'body_html' => 'Keep my custom reset body',
    ]);

    (new EmailTemplatesSeeder)->run();

    $share->refresh();
    $custom->refresh();
    $definition = BuiltInEmailTemplates::definition('document_share');

    expect($share->subject)->toBe($definition['subject'])
        ->and($share->body_html)->toBe($definition['body_html'])
        ->and($custom->subject)->toBe('Keep my custom reset subject')
        ->and($custom->body_html)->toBe('Keep my custom reset body');
});

test('seeded templates only use supported placeholders', function () {
    (new EmailTemplatesSeeder)->run();

    foreach (BuiltInEmailTemplates::slugs() as $slug) {
        $template = EmailTemplate::query()->where('slug', $slug)->firstOrFail();

        expect(BuiltInEmailTemplates::findUnsupportedPlaceholders(
            $slug,
            $template->subject,
            $template->body_html,
        ))->toBeEmpty("Unsupported placeholders on [{$slug}]");
    }
});

test('preview renders every built-in template without unresolved placeholders', function () {
    (new EmailTemplatesSeeder)->run();
    $preview = app(EmailTemplatePreview::class);

    foreach (BuiltInEmailTemplates::slugs() as $slug) {
        $template = EmailTemplate::query()->where('slug', $slug)->firstOrFail();
        $rendered = $preview->render($template);

        expect($rendered['html'])->not->toMatch('/\{\{[^}]+\}\}/')
            ->and($rendered['subject'])->not->toMatch('/\{\{[^}]+\}\}/');
    }
});

test('employee and company document expiry previews mirror production subjects and stay within the alert window', function () {
    (new EmailTemplatesSeeder)->run();
    $preview = app(EmailTemplatePreview::class);

    $employee = EmailTemplate::query()->where('slug', 'document_expiry_alert')->firstOrFail();
    $company = EmailTemplate::query()->where('slug', 'company_document_expiry_alert')->firstOrFail();

    $employeePreview = $preview->render($employee);
    $companyPreview = $preview->render($company);

    expect($employeePreview['subject'])
        ->toBe('Employee Document Expiry Alert — 2 document(s) require attention')
        ->and($employeePreview['html'])
        ->toContain('Employee Document Expiry Alert')
        ->toContain('John Doe')
        ->toContain('1042')
        ->toContain('Passport')
        ->toContain('24')
        ->toContain('Jane Doe')
        ->toContain('7')
        ->toContain('View Document Compliance')
        ->and($companyPreview['subject'])
        ->toBe('Company Document Expiry Alert — 2 document(s) require attention')
        ->and($companyPreview['html'])
        ->toContain('Company Document Expiry Alert')
        ->toContain('Trade License')
        ->toContain('TL-2026-001')
        ->toContain('20')
        ->toContain('Establishment Card')
        ->toContain('EC-5582')
        ->toContain('5')
        ->toContain('View Company Documents');
});
