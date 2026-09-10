<?php

use App\Models\User;
use App\Models\WhatsAppTemplate;

test('platform user can view whatsapp template library page', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'view');

    $this->actingAs($user)
        ->get(route('application.whatsapp-templates.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/whatsapp-templates')
            ->has('templates')
            ->has('categories')
            ->has('meta_template_manager_url')
            ->where('can.create', false)
            ->where('can.update', false)
            ->where('can.delete', false),
        );
});

test('platform manager has full capabilities on whatsapp templates page', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'manage');

    $this->actingAs($user)
        ->get(route('application.whatsapp-templates.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/whatsapp-templates')
            ->where('can.create', true)
            ->where('can.update', true)
            ->where('can.delete', true),
        );
});

test('users without platform access cannot view template library', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['settings.integrations.whatsapp-templates.view']);

    $this->actingAs($user)
        ->get(route('application.whatsapp-templates.index'))
        ->assertForbidden();
});

test('whatsapp templates can be created and customized by platform manager', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'manage');

    $this->actingAs($user)
        ->post(route('application.whatsapp-templates.store'), [
            'slug' => 'crew_document',
            'label' => 'Crew document',
            'category' => 'document',
            'meta_name' => 'crew_document',
            'meta_language' => 'en_US',
            'header_type' => 'document',
            'body_preview' => 'Hello {{name}}, your document is attached.',
            'is_default' => false,
            'enabled' => true,
            'sort_order' => 1,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $template = WhatsAppTemplate::query()->where('slug', 'crew_document')->first();

    expect($template)->not->toBeNull()
        ->and($template->meta_name)->toBe('crew_document')
        ->and($template->meta_language)->toBe('en_US')
        ->and($template->previewBodyFor('Ahmed'))->toBe('Hello Ahmed, your document is attached.');
});

test('whatsapp template can be updated by platform manager', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'manage');

    $template = WhatsAppTemplate::query()->where('slug', 'document_delivery')->firstOrFail();

    $this->actingAs($user)
        ->put(route('application.whatsapp-templates.update', $template), [
            'slug' => 'document_delivery',
            'label' => 'Updated document delivery',
            'category' => 'document',
            'meta_name' => 'document_delivery_v2',
            'meta_language' => 'en_GB',
            'header_type' => 'document',
            'body_preview' => 'Updated preview for {{name}}.',
            'is_default' => true,
            'enabled' => true,
            'sort_order' => 0,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $template->refresh();

    expect($template->label)->toBe('Updated document delivery')
        ->and($template->meta_name)->toBe('document_delivery_v2')
        ->and($template->meta_language)->toBe('en_GB');
});

test('default whatsapp template cannot be deleted', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'manage');

    $template = WhatsAppTemplate::query()->where('slug', 'document_delivery')->firstOrFail();

    $this->actingAs($user)
        ->delete(route('application.whatsapp-templates.destroy', $template))
        ->assertRedirect()
        ->assertSessionHasErrors('template');

    expect(WhatsAppTemplate::query()->whereKey($template->id)->exists())->toBeTrue();
});

test('users without platform manage cannot delete whatsapp templates', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'view');

    $template = WhatsAppTemplate::query()->where('slug', 'document_delivery')->firstOrFail();

    $this->actingAs($user)
        ->delete(route('application.whatsapp-templates.destroy', $template))
        ->assertForbidden();
});

test('announcement title body v2 templates require text header and single body parameter', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'manage');

    $valid = [
        'slug' => 'announcement_profile_valid',
        'label' => 'Valid Announcement Profile',
        'category' => 'announcement',
        'meta_name' => 'employee_profile_valid',
        'meta_language' => 'en',
        'header_type' => 'text',
        'payload_profile' => 'announcement_title_body_v2',
        'purpose' => 'general',
        'body_preview' => "Update from OMS:\n\n{{1}}\n\nThank you.",
        'is_default' => false,
        'enabled' => true,
        'sort_order' => 99,
    ];

    $this->actingAs($user)
        ->post(route('application.whatsapp-templates.store'), [
            ...$valid,
            'header_type' => 'none',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('header_type');

    $this->actingAs($user)
        ->post(route('application.whatsapp-templates.store'), [
            ...$valid,
            'header_type' => 'document',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('header_type');

    $this->actingAs($user)
        ->post(route('application.whatsapp-templates.store'), [
            ...$valid,
            'body_preview' => 'Hello {{1}} and {{2}}',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('body_preview');

    $this->actingAs($user)
        ->post(route('application.whatsapp-templates.store'), $valid)
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(WhatsAppTemplate::query()->where('slug', 'announcement_profile_valid')->exists())->toBeTrue();
});

test('document templates are not subject to announcement payload profile rules', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'manage');

    $this->actingAs($user)
        ->post(route('application.whatsapp-templates.store'), [
            'slug' => 'document_no_announcement_rules',
            'label' => 'Document without announcement rules',
            'category' => 'document',
            'meta_name' => 'document_no_announcement_rules',
            'meta_language' => 'en',
            'header_type' => 'document',
            'body_preview' => 'Hello {{name}}, Please find the attached document.',
            'is_default' => false,
            'enabled' => true,
            'sort_order' => 12,
        ])
        ->assertRedirect()
        ->assertSessionHas('success')
        ->assertSessionDoesntHaveErrors();
});
