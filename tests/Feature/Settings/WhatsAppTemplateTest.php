<?php

use App\Models\User;
use App\Models\WhatsAppTemplate;

test('owner can view whatsapp template library page', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['settings.integrations.whatsapp-templates.view']);

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

test('users without whatsapp template permission cannot view template library', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['settings.application.view']);

    $this->actingAs($user)
        ->get(route('application.whatsapp-templates.index'))
        ->assertForbidden();
});

test('whatsapp integration view alone does not grant template library access', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['settings.integrations.whatsapp.view']);

    $this->actingAs($user)
        ->get(route('application.whatsapp-templates.index'))
        ->assertForbidden();
});

test('whatsapp templates can be created and customized', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, [
        'settings.integrations.whatsapp-templates.view',
        'settings.integrations.whatsapp-templates.create',
    ]);

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

test('whatsapp template can be updated', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, [
        'settings.integrations.whatsapp-templates.view',
        'settings.integrations.whatsapp-templates.update',
    ]);

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
    setupCompanyWithSettingsPermissions($user, [
        'settings.integrations.whatsapp-templates.view',
        'settings.integrations.whatsapp-templates.delete',
    ]);

    $template = WhatsAppTemplate::query()->where('slug', 'document_delivery')->firstOrFail();

    $this->actingAs($user)
        ->delete(route('application.whatsapp-templates.destroy', $template))
        ->assertRedirect()
        ->assertSessionHasErrors('template');

    expect(WhatsAppTemplate::query()->whereKey($template->id)->exists())->toBeTrue();
});
