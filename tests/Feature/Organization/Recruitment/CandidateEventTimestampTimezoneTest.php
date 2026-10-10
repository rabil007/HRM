<?php

use App\Enums\Recruitment\CandidateJoiningReadinessStatus;
use App\Enums\Recruitment\CandidateStage;
use App\Enums\Recruitment\RequirementLineStatus;
use App\Enums\Recruitment\RequirementStatus;
use App\Models\Client;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Position;
use App\Models\RecruitmentCandidate;
use App\Models\RecruitmentCandidateOffer;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementLine;
use App\Models\User;
use App\Support\Recruitment\Candidates\Actions\ConfirmCandidateJoined;
use App\Support\Recruitment\Candidates\Actions\UpdateCandidateInterview;
use App\Support\Recruitment\Candidates\CandidateOfferDateValidation;
use App\Support\Recruitment\Candidates\CandidatePresenter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    config(['app.timezone' => 'UTC']);
    date_default_timezone_set('UTC');

    $country = Country::query()->firstOrCreate(
        ['code' => 'US'],
        ['name' => 'United States', 'dial_code' => '+1', 'is_active' => true],
    );

    $currency = Currency::query()->firstOrCreate(
        ['code' => 'USD'],
        ['name' => 'US Dollar', 'symbol' => '$', 'is_active' => true],
    );

    // Company in America/New_York (UTC-5 in winter, UTC-4 in summer DST)
    $this->companyNY = Company::query()->create([
        'name' => 'New York Corp',
        'slug' => 'new-york-corp-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'America/New_York',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $this->client = Client::query()->create(['name' => 'NY Client', 'is_active' => true]);
    $this->position = Position::query()->create(['company_id' => $this->companyNY->id, 'title' => 'Analyst', 'status' => 'active']);

    $this->user = User::factory()->create(['company_id' => $this->companyNY->id, 'status' => 'active']);
    DB::table('company_user')->updateOrInsert(
        ['company_id' => $this->companyNY->id, 'user_id' => $this->user->id],
        ['status' => 'active', 'created_at' => now(), 'updated_at' => now()],
    );

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->companyNY->id);
    foreach (['recruitment.candidates.view', 'recruitment.candidates.update', 'recruitment.candidates.offer.update', 'recruitment.candidates.offer.decide', 'recruitment.candidates.joining.confirm'] as $pName) {
        $p = Permission::query()->firstOrCreate(['name' => $pName, 'guard_name' => 'web']);
        $this->user->givePermissionTo($p);
    }

    $this->requirement = RecruitmentRequirement::query()->create([
        'company_id' => $this->companyNY->id,
        'client_id' => $this->client->id,
        'requirement_number' => 'REQ-TZ-1',
        'status' => RequirementStatus::Open,
        'priority' => 'normal',
        'request_received_date' => '2026-10-01',
        'required_by_date' => '2026-11-01',
        'total_headcount' => 1,
        'assigned_to' => $this->user->id,
        'created_by' => $this->user->id,
    ]);

    $this->line = RecruitmentRequirementLine::query()->create([
        'company_id' => $this->companyNY->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'position_id' => $this->position->id,
        'required_headcount' => 1,
        'status' => RequirementLineStatus::Open,
    ]);
});

test('interview scheduled at normalizes from company timezone to UTC storage and round-trips for display', function (): void {
    // Current time in NY: 2026-10-15 14:00 (EDT, UTC-4) -> UTC is 2026-10-15 18:00
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-15 18:00:00', 'UTC'));

    $candidate = RecruitmentCandidate::query()->create([
        'company_id' => $this->companyNY->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'name' => 'TZ Candidate',
        'stage' => CandidateStage::Interview,
        'lock_version' => 1,
        'position_title_snapshot' => 'Analyst',
        'requirement_number_snapshot' => 'REQ-TZ-1',
    ]);

    $action = app(UpdateCandidateInterview::class);

    // Schedule interview at 2026-10-20 23:30 NY time (near midnight boundary)
    // In UTC, this is 2026-10-21 03:30 (next calendar day in UTC!)
    $action->handle(
        $this->user,
        $candidate,
        [
            'interview_scheduled_at' => '2026-10-20T23:30',
            'interview_mode' => 'in_person',
            'interview_location' => 'New York HQ',
            'lock_version' => 1,
        ],
    );

    $candidate->refresh();

    // Verify stored timestamp is normalized to UTC storage timezone (03:30 on the next day)
    expect($candidate->interview_scheduled_at->format('Y-m-d H:i:s'))->toBe('2026-10-21 03:30:00');

    // Verify presenter converts it back to company timezone (2026-10-20 23:30) for display
    $presented = CandidatePresenter::toShowArray($candidate, $this->user, 'America/New_York');
    expect($presented['interview_scheduled_at'])->toBe('20 Oct 2026 23:30');
});

test('offer sent_at, accepted_at, and actual joining timestamps round-trip across midnight and DST boundaries', function (): void {
    // America/New_York DST transition in Fall: first Sunday in November (Nov 1, 2026)
    // On Oct 30, 2026, NY is EDT (UTC-4)
    // On Nov 05, 2026, NY is EST (UTC-5)
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-05 20:00:00', 'UTC'));

    $candidate = RecruitmentCandidate::query()->create([
        'company_id' => $this->companyNY->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'name' => 'DST Candidate',
        'stage' => CandidateStage::Joining,
        'expected_joining_date' => '2026-11-04',
        'joining_readiness_status' => CandidateJoiningReadinessStatus::Ready,
        'lock_version' => 2,
        'position_title_snapshot' => 'Analyst',
        'requirement_number_snapshot' => 'REQ-TZ-1',
    ]);

    // Offer was prepared on Oct 30 (EDT, UTC-4), sent at 23:00 NY (= Oct 31 03:00 UTC)
    // and accepted on Nov 02 (after DST switch to EST UTC-5) at 10:00 NY (= Nov 02 15:00 UTC)
    $offer = RecruitmentCandidateOffer::query()->create([
        'company_id' => $this->companyNY->id,
        'recruitment_candidate_id' => $candidate->id,
        'revision_number' => 1,
        'is_current' => true,
        'status' => 'accepted',
        'offer_date' => '2026-10-30',
        'proposed_joining_date' => '2026-11-04',
        'salary_amount' => 75000,
        'salary_currency_code' => 'USD',
        'sent_at' => '2026-10-31 03:00:00', // UTC
        'accepted_at' => '2026-11-02 15:00:00', // UTC
        'lock_version' => 1,
    ]);

    // Validate actual joining date on Nov 04 (valid, on or after acceptance in company timezone)
    $confirmAction = app(ConfirmCandidateJoined::class);
    $confirmAction->handle(
        $this->user,
        $candidate,
        [
            'actual_joining_date' => '2026-11-04',
            'lock_version' => 2,
        ],
    );

    $candidate->refresh();
    expect($candidate->stage)->toBe(CandidateStage::Joined)
        ->and($candidate->actual_joining_date->toDateString())->toBe('2026-11-04')
        ->and($candidate->joined_at)->not->toBeNull();

    // Verify date validation helper correctly rejects actual joining date before offer acceptance date in company timezone
    expect(function () use ($candidate, $offer): void {
        CandidateOfferDateValidation::resolveAndValidateActualJoiningDate(
            $this->companyNY,
            $candidate,
            $offer,
            '2026-11-01', // Before acceptance on Nov 02 in NY timezone!
        );
    })->toThrow(ValidationException::class);
});

test('CandidateOfferDateValidation respects company timezone for relative event comparisons', function (): void {
    // Current time: UTC 2026-10-15 02:00:00
    // In America/New_York (UTC-4), it is still 2026-10-14 22:00:00 (yesterday!)
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-15 02:00:00', 'UTC'));

    $offer = new RecruitmentCandidateOffer([
        'company_id' => $this->companyNY->id,
        'offer_date' => '2026-10-14',
    ]);

    // An event sent at '2026-10-14 21:00' NY time:
    // When parsed in NY time, it is on 2026-10-14 (>= offer_date 2026-10-14) and <= now in NY (22:00).
    $sentAt = CandidateOfferDateValidation::resolveAndValidateSentAt(
        $this->companyNY,
        $offer,
        '2026-10-14 21:00:00',
    );

    // In UTC storage timezone, 21:00 EDT (+4h) = 2026-10-15 01:00:00 UTC!
    expect($sentAt->format('Y-m-d H:i:s'))->toBe('2026-10-15 01:00:00');

    // Future date relative to company timezone is rejected
    expect(function () use ($offer): void {
        CandidateOfferDateValidation::resolveAndValidateSentAt(
            $this->companyNY,
            $offer,
            '2026-10-14 23:00:00', // 23:00 NY is in the future relative to current 22:00 NY!
        );
    })->toThrow(ValidationException::class);
});

test('CandidateOfferDateValidation extracts pure Y-m-d calendar dates without timezone shifting across differing app and company timezones', function (): void {
    // Differing timezone scenario:
    // Application timezone is UTC. Company timezone is America/New_York (UTC-4).
    $tz = 'America/New_York';

    // 1. Strings with various formats: standard Y-m-d, ISO string, and full datetime string
    $extracted1 = CandidateOfferDateValidation::extractDateOnlyString('2026-10-15');
    $extracted2 = CandidateOfferDateValidation::extractDateOnlyString('2026-10-15T00:00:00.000000Z');
    $extracted3 = CandidateOfferDateValidation::extractDateOnlyString('2026-10-15 00:00:00');
    $carbonUtc = CarbonImmutable::parse('2026-10-15 00:00:00', 'UTC');
    $extracted4 = CandidateOfferDateValidation::extractDateOnlyString($carbonUtc);

    expect($extracted1)->toBe('2026-10-15')
        ->and($extracted2)->toBe('2026-10-15')
        ->and($extracted3)->toBe('2026-10-15')
        ->and($extracted4)->toBe('2026-10-15');

    // 2. Passing Carbon instance from Eloquent date cast (created in app.timezone UTC)
    // When parsed in company timezone, it MUST remain 2026-10-15, never shifting to 2026-10-14!
    $parsed = CandidateOfferDateValidation::parseDateOnly($carbonUtc, $tz, 'offer_date');
    expect($parsed?->toDateString())->toBe('2026-10-15')
        ->and($parsed?->timezoneName)->toBe($tz)
        ->and($parsed?->format('H:i:s'))->toBe('00:00:00');

    // Test with opposite direction timezone: Pacific/Auckland (UTC+13)
    $parsedAuckland = CandidateOfferDateValidation::parseDateOnly($carbonUtc, 'Pacific/Auckland', 'offer_date');
    expect($parsedAuckland?->toDateString())->toBe('2026-10-15')
        ->and($parsedAuckland?->timezoneName)->toBe('Pacific/Auckland');
});

test('joining countdown in candidate presenter avoids fractional-day truncation and calculates exact calendar days for today, tomorrow, yesterday, and DST', function (): void {
    $tz = 'America/New_York';

    $candidate = RecruitmentCandidate::query()->create([
        'company_id' => $this->companyNY->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'name' => 'Countdown Candidate',
        'stage' => CandidateStage::Joining,
        'expected_joining_date' => '2026-10-15',
        'lock_version' => 1,
        'position_title_snapshot' => 'Analyst',
        'requirement_number_snapshot' => 'REQ-TZ-1',
    ]);

    // Test near midnight boundary late at night: 23:45 in NY time
    // In UTC, this is next day: 2026-10-16 03:45 UTC
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-16 03:45:00', 'UTC'));

    // Today in NY is 2026-10-15: candidate expected joining is 2026-10-15 -> Joining today (diff 0)
    $presented = CandidatePresenter::toShowArray($candidate, $this->user, $tz);
    expect($presented['joining']['schedule_urgency'])->toBe('today')
        ->and($presented['joining']['schedule_days_diff'])->toBe(0)
        ->and($presented['joining']['schedule_label'])->toBe('Joining today');

    // Tomorrow: expected joining is 2026-10-16 -> Joining in 1 day (diff 1)
    $candidate->update(['expected_joining_date' => '2026-10-16']);
    $presentedTomorrow = CandidatePresenter::toShowArray($candidate->fresh(), $this->user, $tz);
    expect($presentedTomorrow['joining']['schedule_urgency'])->toBe('upcoming')
        ->and($presentedTomorrow['joining']['schedule_days_diff'])->toBe(1)
        ->and($presentedTomorrow['joining']['schedule_label'])->toBe('Joining in 1 day');

    // Yesterday (overdue): expected joining was 2026-10-14 -> Overdue by 1 day (diff 1)
    $candidate->update(['expected_joining_date' => '2026-10-14']);
    $presentedYesterday = CandidatePresenter::toShowArray($candidate->fresh(), $this->user, $tz);
    expect($presentedYesterday['joining']['schedule_urgency'])->toBe('overdue')
        ->and($presentedYesterday['joining']['schedule_days_diff'])->toBe(1)
        ->and($presentedYesterday['joining']['schedule_label'])->toBe('Overdue by 1 day');

    // Near midnight boundary early in the morning: 00:15 in NY time (04:15 UTC)
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-15 04:15:00', 'UTC'));
    $candidate->update(['expected_joining_date' => '2026-10-15']);
    $presentedEarly = CandidatePresenter::toShowArray($candidate->fresh(), $this->user, $tz);
    expect($presentedEarly['joining']['schedule_urgency'])->toBe('today')
        ->and($presentedEarly['joining']['schedule_days_diff'])->toBe(0)
        ->and($presentedEarly['joining']['schedule_label'])->toBe('Joining today');

    // Test across DST boundary: America/New_York switches from EDT to EST on Nov 1, 2026 (25-hour day)
    // Current time: Oct 31, 2026 12:00 NY (= 16:00 UTC)
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-31 16:00:00', 'UTC'));
    $candidate->update(['expected_joining_date' => '2026-11-02']); // 2 calendar days later
    $presentedDst = CandidatePresenter::toShowArray($candidate->fresh(), $this->user, $tz);
    // Even though 49 hours elapse (25h on Nov 1), round() ensures exactly 2 days without fractional truncation
    expect($presentedDst['joining']['schedule_urgency'])->toBe('upcoming')
        ->and($presentedDst['joining']['schedule_days_diff'])->toBe(2)
        ->and($presentedDst['joining']['schedule_label'])->toBe('Joining in 2 days');
});

test('same-day historical offer sending and acceptance validates accurately without error', function (): void {
    // Current time is now 2026-10-20. Testing historical record entry for 2026-10-10.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC'));

    $candidate = RecruitmentCandidate::query()->create([
        'company_id' => $this->companyNY->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'name' => 'Historical Candidate',
        'stage' => CandidateStage::Joining,
        'expected_joining_date' => '2026-10-10',
        'lock_version' => 1,
        'position_title_snapshot' => 'Analyst',
        'requirement_number_snapshot' => 'REQ-TZ-1',
    ]);

    $offer = new RecruitmentCandidateOffer([
        'company_id' => $this->companyNY->id,
        'offer_date' => '2026-10-10',
    ]);

    // Sent at 00:01:00 on the same day in NY (just past midnight)
    $sentAt = CandidateOfferDateValidation::resolveAndValidateSentAt(
        $this->companyNY,
        $offer,
        '2026-10-10 00:01:00',
    );
    expect($sentAt)->not->toBeNull()
        ->and($sentAt->setTimezone('America/New_York')->toDateString())->toBe('2026-10-10');

    $offer->sent_at = $sentAt;

    // Accepted at 23:59:00 on the same day in NY (just before midnight)
    $acceptedAt = CandidateOfferDateValidation::resolveAndValidateAcceptedAt(
        $this->companyNY,
        $offer,
        '2026-10-10 23:59:00',
    );
    expect($acceptedAt)->not->toBeNull()
        ->and($acceptedAt->setTimezone('America/New_York')->toDateString())->toBe('2026-10-10');

    $offer->accepted_at = $acceptedAt;

    // Actual joining date on 2026-10-10 (same day)
    $actualJoiningResult = CandidateOfferDateValidation::resolveAndValidateActualJoiningDate(
        $this->companyNY,
        $candidate,
        $offer,
        '2026-10-10',
    );
    expect($actualJoiningResult['actual_joining_date'])->toBe('2026-10-10');
});
