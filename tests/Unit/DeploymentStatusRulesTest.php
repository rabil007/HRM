<?php

use App\Support\CrewDeployments\DeploymentStatus;
use App\Support\CrewDeployments\DeploymentStatusRules;

test('deployment status rules include all lifecycle statuses', function () {
    $rules = DeploymentStatusRules::forPage();

    $statusKeys = array_column($rules['statuses'], 'status');

    expect($statusKeys)->toContain(
        DeploymentStatus::ON_VESSEL,
        DeploymentStatus::JOIN_STANDBY,
        DeploymentStatus::LEAVE_STANDBY,
        DeploymentStatus::TRAVEL,
        DeploymentStatus::ARRIVED,
        DeploymentStatus::UNKNOWN,
        DeploymentStatus::DISEMBARKED,
    )
        ->and($rules['in_home']['title'])->toBe('In home')
        ->and($rules['intro'])->not->toBeEmpty()
        ->and($rules['needs_update_hints'])->not->toBeEmpty()
        ->and($rules['date_highlights']['fields'])->not->toBeEmpty();
});

test('each deployment status rule has required fields', function () {
    $rules = DeploymentStatusRules::forPage();

    foreach ($rules['statuses'] as $status) {
        expect($status)
            ->toHaveKeys(['status', 'label', 'summary', 'conditions', 'badge'])
            ->and($status['label'])->not->toBeEmpty()
            ->and($status['summary'])->not->toBeEmpty()
            ->and($status['conditions'])->not->toBeEmpty();
    }
});
