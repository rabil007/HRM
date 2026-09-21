<?php

use App\Enums\CrewOperationalAlertSeverity;
use App\Enums\CrewOperationalAlertStatus;
use App\Enums\CrewOperationalAlertType;
use App\Models\CrewOperationalAlert;
use App\Models\CrewOperationalAlertRecipient;

test('malformed employee_id in alert context fails closed for restricted users', function () {
    ['user' => $user, 'company' => $company, 'marineDept' => $marineDept] = makeEmployeeVisibilityFixtures();
    restrictUserToDepartments($user, $company, [$marineDept->id]);

    $now = now();
    $alert = CrewOperationalAlert::query()->create([
        'company_id' => $company->id,
        'type' => CrewOperationalAlertType::SignoffOverdue,
        'severity' => CrewOperationalAlertSeverity::Warning,
        'status' => CrewOperationalAlertStatus::Active,
        'dedupe_key' => 'malformed-1',
        'title' => 'Malformed Alert',
        'message' => 'Something weird happened',
        'fingerprint' => 'malformed-1',
        'context' => [
            'employee_id' => 'not-a-valid-id',
        ],
        'detected_at' => $now,
        'first_detected_at' => $now,
        'last_detected_at' => $now,
    ]);

    CrewOperationalAlertRecipient::query()->create([
        'company_id' => $company->id,
        'crew_operational_alert_id' => $alert->id,
        'user_id' => $user->id,
    ]);

    $response = $this->actingAs($user)->getJson(route('notifications.feed'));

    $response->assertOk();
    expect($response->json('items'))->toBeEmpty()
        ->and($response->json('unread_count'))->toBe(0);
});

test('null employee_id key in alert context fails closed for restricted users', function () {
    ['user' => $user, 'company' => $company, 'marineDept' => $marineDept] = makeEmployeeVisibilityFixtures();
    restrictUserToDepartments($user, $company, [$marineDept->id]);

    $now = now();
    $alert = CrewOperationalAlert::query()->create([
        'company_id' => $company->id,
        'type' => CrewOperationalAlertType::SignoffOverdue,
        'severity' => CrewOperationalAlertSeverity::Warning,
        'status' => CrewOperationalAlertStatus::Active,
        'dedupe_key' => 'null-emp-1',
        'title' => 'Null Employee Alert',
        'message' => 'Null employee key',
        'fingerprint' => 'null-emp-1',
        'context' => [
            'employee_id' => null,
        ],
        'detected_at' => $now,
        'first_detected_at' => $now,
        'last_detected_at' => $now,
    ]);

    CrewOperationalAlertRecipient::query()->create([
        'company_id' => $company->id,
        'crew_operational_alert_id' => $alert->id,
        'user_id' => $user->id,
    ]);

    $response = $this->actingAs($user)->getJson(route('notifications.feed'));

    $response->assertOk();
    expect($response->json('items'))->toBeEmpty()
        ->and($response->json('unread_count'))->toBe(0);
});

test('visible crew alerts are not crowded out by preceding hidden alerts', function () {
    ['user' => $user, 'company' => $company, 'marineDept' => $marineDept, 'marineEmployee' => $marine, 'officeEmployee' => $office] = makeEmployeeVisibilityFixtures();
    restrictUserToDepartments($user, $company, [$marineDept->id]);

    // Create 2 older alerts for visible Marine employee
    for ($i = 1; $i <= 2; $i++) {
        $alert = CrewOperationalAlert::query()->create([
            'company_id' => $company->id,
            'type' => CrewOperationalAlertType::SignoffOverdue,
            'severity' => CrewOperationalAlertSeverity::Info,
            'status' => CrewOperationalAlertStatus::Active,
            'dedupe_key' => "marine-{$i}",
            'title' => "Marine Alert {$i}",
            'message' => "Message {$i}",
            'fingerprint' => "marine-{$i}",
            'context' => [
                'employee_id' => $marine->id,
            ],
            'detected_at' => now()->subHours(10 - $i),
            'first_detected_at' => now()->subHours(10 - $i),
            'last_detected_at' => now()->subHours(10 - $i),
        ]);

        CrewOperationalAlertRecipient::query()->create([
            'company_id' => $company->id,
            'crew_operational_alert_id' => $alert->id,
            'user_id' => $user->id,
            'created_at' => now()->subHours(10 - $i),
        ]);
    }

    // Create 22 newer alerts for hidden Office employee
    for ($i = 1; $i <= 22; $i++) {
        $alert = CrewOperationalAlert::query()->create([
            'company_id' => $company->id,
            'type' => CrewOperationalAlertType::SignoffOverdue,
            'severity' => CrewOperationalAlertSeverity::Warning,
            'status' => CrewOperationalAlertStatus::Active,
            'dedupe_key' => "office-{$i}",
            'title' => "Office Alert {$i}",
            'message' => "Message {$i}",
            'fingerprint' => "office-{$i}",
            'context' => [
                'employee_id' => $office->id,
            ],
            'detected_at' => now()->subMinutes(30 - $i),
            'first_detected_at' => now()->subMinutes(30 - $i),
            'last_detected_at' => now()->subMinutes(30 - $i),
        ]);

        CrewOperationalAlertRecipient::query()->create([
            'company_id' => $company->id,
            'crew_operational_alert_id' => $alert->id,
            'user_id' => $user->id,
            'created_at' => now()->subMinutes(30 - $i),
        ]);
    }

    $response = $this->actingAs($user)->getJson(route('notifications.feed'));

    $response->assertOk();

    // The user should see the 2 Marine alerts despite 22 newer hidden Office alerts
    $items = collect($response->json('items'))->where('source', 'crew_operational_alert');
    expect($items)->toHaveCount(2)
        ->and($response->json('unread_count'))->toBe(2);
});

test('visible crew alert beyond five hundred newer hidden alerts is still returned', function () {
    ['user' => $user, 'company' => $company, 'marineDept' => $marineDept, 'marineEmployee' => $marine, 'officeEmployee' => $office] = makeEmployeeVisibilityFixtures();
    restrictUserToDepartments($user, $company, [$marineDept->id]);

    for ($i = 1; $i <= 501; $i++) {
        $alert = CrewOperationalAlert::query()->create([
            'company_id' => $company->id,
            'type' => CrewOperationalAlertType::SignoffOverdue,
            'severity' => CrewOperationalAlertSeverity::Warning,
            'status' => CrewOperationalAlertStatus::Active,
            'dedupe_key' => "hidden-office-{$i}",
            'title' => "Hidden Office Alert {$i}",
            'message' => "Hidden {$i}",
            'fingerprint' => "hidden-office-{$i}",
            'context' => ['employee_id' => $office->id],
            'detected_at' => now()->subMinutes(600 - $i),
            'first_detected_at' => now()->subMinutes(600 - $i),
            'last_detected_at' => now()->subMinutes(600 - $i),
        ]);

        CrewOperationalAlertRecipient::query()->create([
            'company_id' => $company->id,
            'crew_operational_alert_id' => $alert->id,
            'user_id' => $user->id,
        ]);
    }

    $visibleAlert = CrewOperationalAlert::query()->create([
        'company_id' => $company->id,
        'type' => CrewOperationalAlertType::SignoffOverdue,
        'severity' => CrewOperationalAlertSeverity::Info,
        'status' => CrewOperationalAlertStatus::Active,
        'dedupe_key' => 'visible-marine-older',
        'title' => 'Visible Marine Alert',
        'message' => 'Older visible alert',
        'fingerprint' => 'visible-marine-older',
        'context' => ['employee_id' => $marine->id],
        'detected_at' => now()->subDays(2),
        'first_detected_at' => now()->subDays(2),
        'last_detected_at' => now()->subDays(2),
    ]);

    CrewOperationalAlertRecipient::query()->create([
        'company_id' => $company->id,
        'crew_operational_alert_id' => $visibleAlert->id,
        'user_id' => $user->id,
    ]);

    $response = $this->actingAs($user)->getJson(route('notifications.feed'));

    $response->assertOk();

    $items = collect($response->json('items'))->where('source', 'crew_operational_alert');
    expect($items)->toHaveCount(1)
        ->and($items->first()['title'])->toBe('Visible Marine Alert')
        ->and($response->json('unread_count'))->toBe(1);
});

test('restricted unread count stays exact across chunked visible hidden and malformed alerts', function () {
    ['user' => $user, 'company' => $company, 'marineDept' => $marineDept, 'marineEmployee' => $marine, 'officeEmployee' => $office] = makeEmployeeVisibilityFixtures();
    restrictUserToDepartments($user, $company, [$marineDept->id]);

    $createRecipient = function (array $context, string $dedupe) use ($user, $company): void {
        $now = now();
        $alert = CrewOperationalAlert::query()->create([
            'company_id' => $company->id,
            'type' => CrewOperationalAlertType::SignoffOverdue,
            'severity' => CrewOperationalAlertSeverity::Warning,
            'status' => CrewOperationalAlertStatus::Active,
            'dedupe_key' => $dedupe,
            'title' => $dedupe,
            'message' => $dedupe,
            'fingerprint' => $dedupe,
            'context' => $context,
            'detected_at' => $now,
            'first_detected_at' => $now,
            'last_detected_at' => $now,
        ]);

        CrewOperationalAlertRecipient::query()->create([
            'company_id' => $company->id,
            'crew_operational_alert_id' => $alert->id,
            'user_id' => $user->id,
        ]);
    };

    for ($i = 1; $i <= 60; $i++) {
        $createRecipient(['employee_id' => $office->id], "hidden-{$i}");
    }

    for ($i = 1; $i <= 3; $i++) {
        $createRecipient(['employee_id' => $marine->id], "visible-{$i}");
    }

    $createRecipient([], 'company-level');
    $createRecipient(['employee_id' => 'bad'], 'malformed');

    $response = $this->actingAs($user)->getJson(route('notifications.feed'));

    $response->assertOk();
    expect($response->json('unread_count'))->toBe(4);
});
