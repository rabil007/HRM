<?php

use App\Models\EmailTemplate;
use App\Models\User;

test('owner can view email template library page', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['settings.integrations.email-templates.view']);

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

test('users without email template permission cannot view template library', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['settings.application.view']);

    $this->actingAs($user)
        ->get(route('application.email-templates.index'))
        ->assertForbidden();
});

test('email templates can be created and customized', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, [
        'settings.integrations.email-templates.view',
        'settings.integrations.email-templates.create',
    ]);

    $this->actingAs($user)
        ->post(route('application.email-templates.store'), [
            'slug' => 'crew_welcome',
            'label' => 'Crew welcome',
            'category' => 'hr',
            'to_preset' => 'hr@example.com, backup@example.com',
            'cc_preset' => 'manager@example.com',
            'subject' => 'Welcome to the team',
            'body_html' => "Hello,\n\nWelcome aboard.",
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

test('email template can be updated', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, [
        'settings.integrations.email-templates.view',
        'settings.integrations.email-templates.update',
    ]);

    $template = EmailTemplate::query()->where('slug', 'document_share')->firstOrFail();

    $this->actingAs($user)
        ->put(route('application.email-templates.update', $template), [
            'slug' => 'document_share',
            'label' => 'Updated document share',
            'category' => 'document',
            'subject' => 'Updated document subject',
            'body_html' => 'Updated message body.',
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
    setupCompanyWithSettingsPermissions($user, [
        'settings.integrations.email-templates.view',
        'settings.integrations.email-templates.create',
    ]);

    $this->actingAs($user)
        ->post(route('application.email-templates.store'), [
            'slug' => 'bad_presets',
            'label' => 'Bad presets',
            'category' => 'general',
            'to_preset' => 'not-an-email',
            'cc_preset' => '',
            'subject' => 'Test',
            'body_html' => 'Body',
            'is_default' => false,
            'enabled' => true,
            'sort_order' => 0,
        ])
        ->assertSessionHasErrors('to_preset');
});

test('default email template cannot be deleted', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, [
        'settings.integrations.email-templates.view',
        'settings.integrations.email-templates.delete',
    ]);

    $template = EmailTemplate::query()->where('slug', 'document_share')->firstOrFail();

    $this->actingAs($user)
        ->delete(route('application.email-templates.destroy', $template))
        ->assertRedirect()
        ->assertSessionHasErrors('template');

    expect(EmailTemplate::query()->whereKey($template->id)->exists())->toBeTrue();
});

test('document expiry alert template can set daily dispatch time', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, [
        'settings.integrations.email-templates.view',
        'settings.integrations.email-templates.update',
    ]);

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
    setupCompanyWithSettingsPermissions($user, [
        'settings.integrations.email-templates.view',
        'settings.integrations.email-templates.create',
    ]);

    $existingDefault = EmailTemplate::query()->where('slug', 'document_share')->firstOrFail();

    $this->actingAs($user)
        ->post(route('application.email-templates.store'), [
            'slug' => 'document_share_alt',
            'label' => 'Alternate document share',
            'category' => 'document',
            'subject' => 'Alt subject',
            'body_html' => '<p>Alt body</p>',
            'is_default' => true,
            'enabled' => true,
            'sort_order' => 1,
        ])
        ->assertRedirect();

    expect($existingDefault->fresh()->is_default)->toBeFalse()
        ->and(EmailTemplate::query()->where('slug', 'document_share_alt')->value('is_default'))->toBeTrue();
});
