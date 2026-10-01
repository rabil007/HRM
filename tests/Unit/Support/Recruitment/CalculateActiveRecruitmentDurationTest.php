<?php

use App\Enums\Recruitment\RequirementStatus;
use App\Models\Client;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementStatusTransition;
use App\Models\User;
use App\Support\Recruitment\CalculateActiveRecruitmentDuration;
use Carbon\Carbon;

beforeEach(function () {
    $country = Country::query()->create([
        'code' => 'DR'.random_int(10, 99),
        'name' => 'Duration Country',
        'dial_code' => '+1',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'D'.random_int(10, 99),
        'name' => 'Duration Currency',
        'symbol' => '$',
        'is_active' => true,
    ]);

    $this->company = Company::query()->create([
        'name' => 'Duration Co',
        'slug' => 'duration-co-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'UTC',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $this->client = Client::query()->create([
        'name' => 'Duration Client',
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'company_id' => $this->company->id,
    ]);
});

function createTimedRequirement(object $context, Carbon $approvedAt): RecruitmentRequirement
{
    return RecruitmentRequirement::query()->create([
        'company_id' => $context->company->id,
        'requirement_number' => 'REQ-DUR-'.uniqid(),
        'client_id' => $context->client->id,
        'request_received_date' => $approvedAt->copy()->subDay(),
        'required_by_date' => $approvedAt->copy()->addDays(30),
        'priority' => 'normal',
        'status' => RequirementStatus::Open,
        'assigned_to' => $context->user->id,
        'created_by' => $context->user->id,
        'approved_at' => $approvedAt,
        'approved_by' => $context->user->id,
        'opened_at' => $approvedAt,
    ]);
}

function addTransition(
    RecruitmentRequirement $requirement,
    ?RequirementStatus $from,
    RequirementStatus $to,
    Carbon $at,
    int $userId,
): void {
    RecruitmentRequirementStatusTransition::query()->create([
        'company_id' => $requirement->company_id,
        'recruitment_requirement_id' => $requirement->id,
        'from_status' => $from?->value,
        'to_status' => $to->value,
        'performed_by' => $userId,
        'created_at' => $at,
        'updated_at' => $at,
    ]);
}

test('approve then complete counts only open interval', function () {
    $approvedAt = Carbon::parse('2026-01-01 10:00:00');
    $completedAt = Carbon::parse('2026-01-03 10:00:00');

    $req = createTimedRequirement($this, $approvedAt);
    addTransition($req, RequirementStatus::PendingApproval, RequirementStatus::Open, $approvedAt, $this->user->id);
    addTransition($req, RequirementStatus::Open, RequirementStatus::Completed, $completedAt, $this->user->id);
    $req->update([
        'status' => RequirementStatus::Completed,
        'completed_at' => $completedAt,
    ]);

    $duration = CalculateActiveRecruitmentDuration::for($req->fresh(), $completedAt);

    expect($duration['active_recruitment_seconds'])->toBe(2 * 86400)
        ->and($duration['on_hold_seconds'])->toBe(0)
        ->and($duration['closed_seconds'])->toBe(0)
        ->and($duration['recruitment_clock_state'])->toBe('completed');
});

test('approve hold resume complete excludes on hold time', function () {
    $approvedAt = Carbon::parse('2026-01-01 10:00:00');
    $holdAt = Carbon::parse('2026-01-01 11:00:00');
    $resumeAt = Carbon::parse('2026-01-01 13:00:00');
    $completedAt = Carbon::parse('2026-01-01 15:00:00');

    $req = createTimedRequirement($this, $approvedAt);
    addTransition($req, RequirementStatus::PendingApproval, RequirementStatus::Open, $approvedAt, $this->user->id);
    addTransition($req, RequirementStatus::Open, RequirementStatus::OnHold, $holdAt, $this->user->id);
    addTransition($req, RequirementStatus::OnHold, RequirementStatus::Open, $resumeAt, $this->user->id);
    addTransition($req, RequirementStatus::Open, RequirementStatus::Completed, $completedAt, $this->user->id);
    $req->update([
        'status' => RequirementStatus::Completed,
        'completed_at' => $completedAt,
    ]);

    $duration = CalculateActiveRecruitmentDuration::for($req->fresh(), $completedAt);

    expect($duration['on_hold_seconds'])->toBe(2 * 3600)
        ->and($duration['active_recruitment_seconds'])->toBe(3 * 3600)
        ->and($duration['recruitment_clock_state'])->toBe('completed');
});

test('approve complete reopen complete excludes closed interval', function () {
    $approvedAt = Carbon::parse('2026-01-01 10:00:00');
    $completedAt = Carbon::parse('2026-01-03 10:00:00');
    $reopenedAt = Carbon::parse('2026-01-11 10:00:00');
    $completedAgainAt = Carbon::parse('2026-01-13 10:00:00');

    $req = createTimedRequirement($this, $approvedAt);
    addTransition($req, RequirementStatus::PendingApproval, RequirementStatus::Open, $approvedAt, $this->user->id);
    addTransition($req, RequirementStatus::Open, RequirementStatus::Completed, $completedAt, $this->user->id);
    addTransition($req, RequirementStatus::Completed, RequirementStatus::Open, $reopenedAt, $this->user->id);
    addTransition($req, RequirementStatus::Open, RequirementStatus::Completed, $completedAgainAt, $this->user->id);
    $req->update([
        'status' => RequirementStatus::Completed,
        'completed_at' => $completedAgainAt,
    ]);

    $duration = CalculateActiveRecruitmentDuration::for($req->fresh(), $completedAgainAt);

    expect($duration['active_recruitment_seconds'])->toBe(4 * 86400)
        ->and($duration['closed_seconds'])->toBe(8 * 86400)
        ->and($duration['recruitment_clock_state'])->toBe('completed')
        ->and($duration['approved_at'])->not->toBeNull();
});

test('approve cancel reopen resumes clock without counting cancelled period', function () {
    $approvedAt = Carbon::parse('2026-02-01 08:00:00');
    $cancelledAt = Carbon::parse('2026-02-02 08:00:00');
    $reopenedAt = Carbon::parse('2026-02-05 08:00:00');
    $now = Carbon::parse('2026-02-06 08:00:00');

    $req = createTimedRequirement($this, $approvedAt);
    addTransition($req, RequirementStatus::PendingApproval, RequirementStatus::Open, $approvedAt, $this->user->id);
    addTransition($req, RequirementStatus::Open, RequirementStatus::Cancelled, $cancelledAt, $this->user->id);
    addTransition($req, RequirementStatus::Cancelled, RequirementStatus::Open, $reopenedAt, $this->user->id);
    $req->update([
        'status' => RequirementStatus::Open,
        'cancelled_at' => null,
        'cancellation_reason' => null,
    ]);

    $duration = CalculateActiveRecruitmentDuration::for($req->fresh(), $now);

    expect($duration['active_recruitment_seconds'])->toBe(2 * 86400)
        ->and($duration['closed_seconds'])->toBe(3 * 86400)
        ->and($duration['recruitment_clock_state'])->toBe('running')
        ->and($duration['active_recruitment_seconds'])->toBeGreaterThanOrEqual(0);
});

test('multiple reopen cycles accumulate only active segments', function () {
    $t0 = Carbon::parse('2026-03-01 00:00:00');
    $t1 = $t0->copy()->addDays(1); // complete
    $t2 = $t0->copy()->addDays(2); // reopen
    $t3 = $t0->copy()->addDays(3); // complete
    $t4 = $t0->copy()->addDays(5); // reopen
    $t5 = $t0->copy()->addDays(6); // complete

    $req = createTimedRequirement($this, $t0);
    addTransition($req, null, RequirementStatus::Open, $t0, $this->user->id);
    addTransition($req, RequirementStatus::Open, RequirementStatus::Completed, $t1, $this->user->id);
    addTransition($req, RequirementStatus::Completed, RequirementStatus::Open, $t2, $this->user->id);
    addTransition($req, RequirementStatus::Open, RequirementStatus::Completed, $t3, $this->user->id);
    addTransition($req, RequirementStatus::Completed, RequirementStatus::Open, $t4, $this->user->id);
    addTransition($req, RequirementStatus::Open, RequirementStatus::Completed, $t5, $this->user->id);
    $req->update([
        'status' => RequirementStatus::Completed,
        'completed_at' => $t5,
    ]);

    $duration = CalculateActiveRecruitmentDuration::for($req->fresh(), $t5);

    // Active: 1 + 1 + 1 = 3 days; closed: 1 + 2 = 3 days
    expect($duration['active_recruitment_seconds'])->toBe(3 * 86400)
        ->and($duration['closed_seconds'])->toBe(3 * 86400);
});
