<?php

use App\Models\Client;
use App\Models\Project;
use App\Models\Vessel;
use App\Models\VesselType;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

test('client usage metadata does not expose cross-domain relationship details', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.clients.view',
        'settings.master-data.clients.delete',
    ]);

    $client = Client::query()->create([
        'name' => 'Restricted Usage Client '.Str::random(4),
        'is_active' => true,
    ]);

    Project::query()->create([
        'title' => 'Hidden Usage Project',
        'client_id' => $client->id,
        'is_active' => true,
    ]);

    $vesselType = VesselType::query()->create([
        'name' => 'Usage Test Vessel Type '.Str::random(4),
        'is_active' => true,
    ]);

    Vessel::query()->create([
        'company_id' => $company->id,
        'client_id' => $client->id,
        'vessel_type_id' => $vesselType->id,
        'name' => 'Hidden Usage Vessel',
        'is_active' => true,
    ]);

    $this->get(route('settings.master-data.clients.index', ['search' => $client->name]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/master-data/clients')
            ->where('clients.0.id', $client->id)
            ->where('clients.0.is_in_use', true)
            ->where('clients.0.can_delete', false)
            ->where('clients.0.usage_count', null)
            ->where('clients.0.usage_label', null)
            ->where('clients.0.projects_count', null)
            ->where('clients.0.vessels_count', null)
        );

    $this->get(route('settings.master-data.clients.show', $client))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/master-data/client-show')
            ->where('client.id', $client->id)
            ->where('client.is_in_use', true)
            ->where('client.can_delete', false)
            ->where('client.usage_count', null)
            ->where('client.usage_label', null)
            ->where('operations.projects', null)
            ->where('operations.vessels', null)
        );
});

test('client usage metadata remains generic even when related domains are visible', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.clients.view',
        'settings.master-data.clients.delete',
        'settings.master-data.projects.view',
        'crew_operations.vessels.view',
    ]);

    $client = Client::query()->create([
        'name' => 'Visible Operations Client '.Str::random(4),
        'is_active' => true,
    ]);

    Project::query()->create([
        'title' => 'Visible Usage Project',
        'client_id' => $client->id,
        'is_active' => true,
    ]);

    $this->get(route('settings.master-data.clients.index', ['search' => $client->name]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/master-data/clients')
            ->where('clients.0.id', $client->id)
            ->where('clients.0.projects_count', 1)
            ->where('clients.0.usage_count', null)
            ->where('clients.0.usage_label', null)
            ->where('clients.0.is_in_use', true)
            ->where('clients.0.can_delete', false)
        );

    $this->get(route('settings.master-data.clients.show', $client))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/master-data/client-show')
            ->where('operations.projects.total_count', 1)
            ->where('operations.projects.preview.0.title', 'Visible Usage Project')
            ->where('client.usage_count', null)
            ->where('client.usage_label', null)
            ->where('client.is_in_use', true)
            ->where('client.can_delete', false)
        );
});
