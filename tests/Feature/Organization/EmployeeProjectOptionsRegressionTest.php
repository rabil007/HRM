<?php

use App\Models\Client;
use App\Models\Employee;
use App\Models\Project;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('employee directory and profile project options use the client project pivot', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $employee = Employee::factory()->create([
        'status' => 'active',
    ]);

    $company = $employee->company;

    $client = Client::query()->create([
        'name' => 'Employee Project Options Client',
        'is_active' => true,
    ]);

    $project = Project::query()->create([
        'title' => 'Employee Project Options Project',
        'is_active' => true,
    ]);

    $project->clients()->sync([$client->id]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get('/organization/employees')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/employees')
            ->has('projects', 1)
            ->where('projects.0.id', $project->id)
            ->where('projects.0.client_ids.0', $client->id));

    $this->get("/organization/employees/{$employee->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/employee')
            ->has('projects', 1)
            ->where('projects.0.id', $project->id)
            ->where('projects.0.client_ids.0', $client->id));
});
