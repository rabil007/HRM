<?php

use App\Models\EmailTemplate;
use App\Models\User;

test('platform user can view email template library page', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'view');

    $this->actingAs($user)
        ->get(route('application.email-templates.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/email-templates')
            ->has('templates')
            ->has('categories')
            ->where('can.create', false)
            ->where('can.update', false)
            ->where('can.delete', false)
            ->where('expiry_alert_template_slug', 'document_expiry_alert')
            ->has('scheduler_timezone'),
        );
});

test('platform manager has full capabilities on email templates page', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'manage');

    $this->actingAs($user)
        ->get(route('application.email-templates.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/email-templates')
            ->where('can.create', true)
            ->where('can.update', true)
            ->where('can.delete', true),
        );
});

test('users without platform access cannot view email template library', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['settings.integrations.email-templates.view']);

    $this->actingAs($user)
        ->get(route('application.email-templates.index'))
        ->assertForbidden();
});

test('email templates can be created and customized by platform manager', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'manage');

    $this->actingAs($user)
        ->post(route('application.email-templates.store'), [
            'slug' => 'crew_welcome',
            'label' => 'Crew welcome',
            'category' => 'hr',
            'to_preset' => 'hr@example.com, backup@example.com',
            'cc_preset' => 'manager@example.com',
            'subject' => 'Welcome to the team',
            'body_html' => "Hello,\n\nWelcome aboard.",
            'include_company_footer' => true,
            'is_default' => false,
            'enabled' => true,
            'sort_order' => 1,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $template = EmailTemplate::query()->where('slug', 'crew_welcome')->first();

    expect($template)->not->toBeNull()
        ->and($template->category->value)->toBe('hr')
        ->and($template->to_preset)->toBe('hr@example.com, backup@example.com')
        ->and($template->cc_preset)->toBe('manager@example.com')
        ->and($template->subject)->toBe('Welcome to the team');
});

test('email template can be updated by platform manager', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'manage');

    $template = EmailTemplate::query()->where('slug', 'document_share')->firstOrFail();

    $this->actingAs($user)
        ->put(route('application.email-templates.update', $template), [
            'slug' => 'document_share',
            'label' => 'Updated document share',
            'category' => 'document',
            'subject' => 'Updated document subject',
            'body_html' => 'Updated message body.',
            'include_company_footer' => true,
            'is_default' => true,
            'enabled' => true,
            'sort_order' => 0,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $template->refresh();

    expect($template->label)->toBe('Updated document share')
        ->and($template->subject)->toBe('Updated document subject');
});

test('email template rejects invalid comma-separated preset addresses', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'manage');

    $this->actingAs($user)
        ->post(route('application.email-templates.store'), [
            'slug' => 'bad_presets',
            'label' => 'Bad presets',
            'category' => 'general',
            'to_preset' => 'not-an-email',
            'cc_preset' => '',
            'subject' => 'Test',
            'body_html' => 'Body',
            'include_company_footer' => true,
            'is_default' => false,
            'enabled' => true,
            'sort_order' => 0,
        ])
        ->assertSessionHasErrors('to_preset');
});

test('default email template cannot be deleted', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'manage');

    $template = EmailTemplate::query()->where('slug', 'document_share')->firstOrFail();

    $this->actingAs($user)
        ->delete(route('application.email-templates.destroy', $template))
        ->assertRedirect()
        ->assertSessionHasErrors('template');

    expect(EmailTemplate::query()->whereKey($template->id)->exists())->toBeTrue();
});

test('document expiry alert template can set daily dispatch time', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'manage');

    $template = EmailTemplate::query()->where('slug', 'document_expiry_alert')->firstOrFail();

    $this->actingAs($user)
        ->put(route('application.email-templates.update', $template), [
            'slug' => 'document_expiry_alert',
            'label' => $template->label,
            'category' => 'notification',
            'to_preset' => 'alerts@example.com',
            'cc_preset' => '',
            'dispatch_at' => '10:45',
            'subject' => $template->subject,
            'body_html' => $template->body_html,
            'include_company_footer' => true,
            'is_default' => true,
            'enabled' => true,
            'sort_order' => 0,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($template->fresh()->dispatch_at)->toBe('10:45');
});

test('marking template as default clears other defaults in category', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'manage');

    $existingDefault = EmailTemplate::query()->where('slug', 'document_share')->firstOrFail();

    $this->actingAs($user)
        ->post(route('application.email-templates.store'), [
            'slug' => 'document_share_alt',
            'label' => 'Alternate document share',
            'category' => 'document',
            'subject' => 'Alt subject',
            'body_html' => '<p>Alt body</p>',
            'include_company_footer' => true,
            'is_default' => true,
            'enabled' => true,
            'sort_order' => 1,
        ])
        ->assertRedirect();

    expect($existingDefault->fresh()->is_default)->toBeFalse()
        ->and(EmailTemplate::query()->where('slug', 'document_share_alt')->value('is_default'))->toBeTrue();
});

test('platform users can preview saved email templates as html', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'view');

    $template = EmailTemplate::query()->where('slug', 'leave_request_submitted')->firstOrFail();

    $this->actingAs($user)
        ->get(route('application.email-templates.preview', $template))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
        ->assertSee('New leave request', false)
        ->assertSee('Jane Smith', false);
});

test('platform users can preview the user invitation email template', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'view');

    $template = EmailTemplate::query()->where('slug', 'user_invitation')->firstOrFail();

    $this->actingAs($user)
        ->get(route('application.email-templates.preview', $template))
        ->assertOk()
        ->assertSee('Invitation to join', false)
        ->assertSee('Alex Invitee', false)
        ->assertSee('Accept invitation', false);
});

test('platform users can preview draft email template content', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'view');

    $response = $this->actingAs($user)
        ->postJson(route('application.email-templates.preview-draft'), [
            'slug' => 'leave_request_submitted',
            'subject' => 'Preview — {{employee_name}}',
            'body_html' => 'Custom intro for {{employee_name}}.',
            'include_company_footer' => false,
        ])
        ->assertOk()
        ->assertJsonPath('subject', 'Preview — Jane Smith')
        ->assertJson(fn ($json) => $json->whereType('html', 'string')->etc());

    expect((string) $response->json('html'))->not->toContain('background-color:#1e2930');
});

test('email template can store include company footer preference', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'manage');

    $template = EmailTemplate::query()->where('slug', 'leave_request_submitted')->firstOrFail();

    $this->actingAs($user)
        ->put(route('application.email-templates.update', $template), [
            'slug' => 'leave_request_submitted',
            'label' => $template->label,
            'category' => 'hr',
            'subject' => $template->subject,
            'body_html' => $template->body_html,
            'include_company_footer' => false,
            'is_default' => true,
            'enabled' => true,
            'sort_order' => 0,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($template->fresh()->include_company_footer)->toBeFalse();
});

test('users without platform access cannot preview templates', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['settings.integrations.email-templates.view']);

    $template = EmailTemplate::query()->where('slug', 'document_share')->firstOrFail();

    $this->actingAs($user)
        ->get(route('application.email-templates.preview', $template))
        ->assertForbidden();
});

test('users without platform manage access cannot delete email templates', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'view');

    $template = EmailTemplate::query()->where('slug', 'document_share')->firstOrFail();

    $this->actingAs($user)
        ->delete(route('application.email-templates.destroy', $template))
        ->assertForbidden();
});
