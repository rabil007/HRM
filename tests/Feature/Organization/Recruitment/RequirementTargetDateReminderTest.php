<?php

use App\Enums\Recruitment\RequirementLineStatus;
use App\Enums\Recruitment\RequirementStatus;
use App\Enums\Recruitment\RequirementTargetDateReminderMilestone;
use App\Enums\Recruitment\RequirementTargetDateReminderStatus;
use App\Jobs\DeliverRequirementTargetDateReminderJob;
use App\Mail\RequirementTargetDateReminderMail;
use App\Models\Client;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Position;
use App\Models\Project;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementLine;
use App\Models\RecruitmentRequirementTargetDateReminder;
use App\Models\User;
use App\Support\Recruitment\DispatchRequirementTargetDateReminders;
use App\Support\Recruitment\RequirementTargetDateReminderDeliveryKey;
use Carbon\CarbonImmutable;
use Database\Seeders\EmailTemplatesSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    Mail::fake();
    (new EmailTemplatesSeeder)->run();

    $country = Country::query()->create([
        'code' => 'RTR',
        'name' => 'Reminder Country',
        'dial_code' => '+971',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'RTR',
        'name' => 'Reminder Currency',
        'symbol' => 'D',
        'is_active' => true,
    ]);

    $this->company = Company::query()->create([
        'name' => 'Reminder Co',
        'slug' => 'reminder-co-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $this->otherCompany = Company::query()->create([
        'name' => 'Other Reminder Co',
        'slug' => 'other-reminder-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'UTC',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $this->requester = User::factory()->create([
        'company_id' => $this->company->id,
        'email' => 'reminder.requester@example.com',
        'name' => 'Reminder Requester',
        'status' => 'active',
    ]);
    $this->submitter = User::factory()->create([
        'company_id' => $this->company->id,
        'email' => 'reminder.submitter@example.com',
        'name' => 'Reminder Submitter',
        'status' => 'active',
    ]);
    $this->recruiter = User::factory()->create([
        'company_id' => $this->company->id,
        'email' => 'reminder.recruiter@example.com',
        'name' => 'Reminder Recruiter',
        'status' => 'active',
    ]);

    foreach ([$this->requester, $this->submitter, $this->recruiter] as $user) {
        DB::table('company_user')->updateOrInsert(
            ['company_id' => $this->company->id, 'user_id' => $user->id],
            ['status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        );
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);
        foreach ([
            'recruitment.requirements.view',
            'recruitment.requirements.approve',
        ] as $perm) {
            $permission = Permission::query()->firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
            $user->givePermissionTo($permission);
        }
    }

    $this->client = Client::query()->create(['name' => 'Reminder Client', 'is_active' => true]);
    $this->project = Project::query()->create(['title' => 'Reminder Project', 'is_active' => true]);
    $this->project->clients()->sync([$this->client->id]);
    $this->position = Position::query()->create([
        'company_id' => $this->company->id,
        'title' => 'Engineer',
        'status' => 'active',
    ]);
});

function createReminderRequirement(object $context, array $overrides = []): RecruitmentRequirement
{
    $req = RecruitmentRequirement::query()->create(array_merge([
        'company_id' => $context->company->id,
        'requirement_number' => 'REQ-RM-'.random_int(100000, 999999),
        'client_id' => $context->client->id,
        'project_id' => $context->project->id,
        'request_received_date' => now()->subDays(5),
        'required_by_date' => now()->addDays(3)->toDateString(),
        'priority' => 'normal',
        'status' => RequirementStatus::Open,
        'assigned_to' => $context->recruiter->id,
        'created_by' => $context->requester->id,
        'submitted_by' => $context->submitter->id,
        'approved_at' => now()->subDays(2),
        'approved_by' => $context->recruiter->id,
        'opened_at' => now()->subDays(2),
        'updated_by' => $context->requester->id,
    ], $overrides));

    RecruitmentRequirementLine::query()->create([
        'company_id' => $context->company->id,
        'recruitment_requirement_id' => $req->id,
        'position_id' => $context->position->id,
        'required_headcount' => 2,
        'salary_min' => 5000,
        'salary_max' => 8000,
        'salary_currency_code' => 'AED',
        'status' => RequirementLineStatus::Open,
    ]);

    return $req->fresh(['lines.position', 'client', 'project', 'assignedRecruiter', 'creator', 'submitter']);
}

function travelToCompanyLocalHour(Company $company, string $date, int $hour = 9): CarbonImmutable
{
    $now = CarbonImmutable::parse("{$date} {$hour}:15:00", $company->timezone)->utc();
    CarbonImmutable::setTestNow($now);
    Carbon::setTestNow($now);

    return $now;
}

afterEach(function () {
    CarbonImmutable::setTestNow();
    Carbon::setTestNow();
});

test('queues three-days-before and target-day reminders at company-local 09:00', function () {
    Queue::fake();

    $threeDays = createReminderRequirement($this, [
        'required_by_date' => '2026-10-10',
        'requirement_number' => 'REQ-RM-THREE',
    ]);
    $dueToday = createReminderRequirement($this, [
        'required_by_date' => '2026-10-07',
        'requirement_number' => 'REQ-RM-TODAY',
    ]);

    travelToCompanyLocalHour($this->company, '2026-10-07', 9);

    $result = app(DispatchRequirementTargetDateReminders::class)->forCompany($this->company->id);

    expect($result['queued'])->toBe(2);

    Queue::assertPushed(DeliverRequirementTargetDateReminderJob::class, 2);
    Queue::assertPushed(DeliverRequirementTargetDateReminderJob::class, function (DeliverRequirementTargetDateReminderJob $job) use ($threeDays) {
        return (int) $job->payload['requirement_id'] === $threeDays->id
            && $job->payload['milestone'] === RequirementTargetDateReminderMilestone::ThreeDaysBefore->value
            && $job->payload['target_date'] === '2026-10-10';
    });
    Queue::assertPushed(DeliverRequirementTargetDateReminderJob::class, function (DeliverRequirementTargetDateReminderJob $job) use ($dueToday) {
        return (int) $job->payload['requirement_id'] === $dueToday->id
            && $job->payload['milestone'] === RequirementTargetDateReminderMilestone::TargetDay->value
            && $job->payload['target_date'] === '2026-10-07';
    });
});

test('does not queue outside company-local dispatch hour unless forced', function () {
    Queue::fake();
    createReminderRequirement($this, ['required_by_date' => '2026-10-07']);
    travelToCompanyLocalHour($this->company, '2026-10-07', 8);

    $skipped = app(DispatchRequirementTargetDateReminders::class)->forCompany($this->company->id);
    expect($skipped['queued'])->toBe(0)->and($skipped['reason'] ?? null)->toBe('outside_local_dispatch_hour');

    $forced = app(DispatchRequirementTargetDateReminders::class)->forCompany($this->company->id, force: true);
    expect($forced['queued'])->toBe(1);
});

test('sends reminder to recruiter with requester and submitter cc without duplicates', function () {
    Queue::fake();
    $req = createReminderRequirement($this, [
        'required_by_date' => '2026-10-07',
        'submitted_by' => $this->submitter->id,
        'created_by' => $this->requester->id,
    ]);

    travelToCompanyLocalHour($this->company, '2026-10-07', 9);
    app(DispatchRequirementTargetDateReminders::class)->forCompany($this->company->id);

    /** @var DeliverRequirementTargetDateReminderJob|null $job */
    $job = null;
    Queue::assertPushed(DeliverRequirementTargetDateReminderJob::class, function (DeliverRequirementTargetDateReminderJob $pushed) use (&$job) {
        $job = $pushed;

        return true;
    });

    Mail::fake();
    $job->handle();

    Mail::assertSent(RequirementTargetDateReminderMail::class, function (RequirementTargetDateReminderMail $mail) use ($req) {
        $cc = collect($mail->cc)->pluck('address')->map(fn ($e) => strtolower((string) $e))->all();

        expect($mail->hasTo('reminder.recruiter@example.com'))->toBeTrue()
            ->and($cc)->toContain('reminder.requester@example.com')
            ->and($cc)->toContain('reminder.submitter@example.com')
            ->and(count(array_filter($cc, fn ($e) => $e === 'reminder.recruiter@example.com')))->toBe(0)
            ->and($mail->subjectLine)->toBe("Requirement {$req->requirement_number} is due today")
            ->and(collect($mail->details)->pluck('label')->all())->toContain('Target Date')
            ->and(collect($mail->details)->pluck('label')->all())->not->toContain('Required by')
            ->and(collect($mail->details)->firstWhere('label', 'Deadline')['value'])->toBe('Due today')
            ->and($mail->requirementUrl)->toContain("/organization/recruitment/requirements/{$req->id}");

        return true;
    });
});

test('on hold requirements remain eligible and messaging mentions On Hold', function () {
    Queue::fake();
    $req = createReminderRequirement($this, [
        'required_by_date' => '2026-10-07',
        'status' => RequirementStatus::OnHold,
    ]);

    travelToCompanyLocalHour($this->company, '2026-10-07', 9);
    app(DispatchRequirementTargetDateReminders::class)->forCompany($this->company->id);

    $job = null;
    Queue::assertPushed(DeliverRequirementTargetDateReminderJob::class, function (DeliverRequirementTargetDateReminderJob $pushed) use (&$job) {
        $job = $pushed;

        return true;
    });

    Mail::fake();
    $job->handle();

    Mail::assertSent(RequirementTargetDateReminderMail::class, function (RequirementTargetDateReminderMail $mail) {
        return str_contains($mail->intro, 'On Hold')
            && collect($mail->details)->firstWhere('label', 'Status')['value'] === 'On Hold';
    });

    expect($req->fresh()->status)->toBe(RequirementStatus::OnHold);
});

test('completed and cancelled requirements are excluded', function () {
    Queue::fake();
    createReminderRequirement($this, [
        'required_by_date' => '2026-10-07',
        'status' => RequirementStatus::Completed,
        'completed_at' => now(),
    ]);
    createReminderRequirement($this, [
        'required_by_date' => '2026-10-07',
        'status' => RequirementStatus::Cancelled,
        'cancelled_at' => now(),
        'cancellation_reason' => 'Stopped',
    ]);

    travelToCompanyLocalHour($this->company, '2026-10-07', 9);
    $result = app(DispatchRequirementTargetDateReminders::class)->forCompany($this->company->id);

    expect($result['queued'])->toBe(0);
    Queue::assertNothingPushed();
});

test('missing primary recipient skips without sending', function () {
    Queue::fake();
    createReminderRequirement($this, [
        'required_by_date' => '2026-10-07',
        'assigned_to' => null,
    ]);

    travelToCompanyLocalHour($this->company, '2026-10-07', 9);
    $result = app(DispatchRequirementTargetDateReminders::class)->forCompany($this->company->id);

    expect($result['queued'])->toBe(0);
    Queue::assertNothingPushed();
});

test('duplicate scheduler execution and queue retries stay idempotent', function () {
    Queue::fake();
    $req = createReminderRequirement($this, ['required_by_date' => '2026-10-07']);
    travelToCompanyLocalHour($this->company, '2026-10-07', 9);

    $dispatcher = app(DispatchRequirementTargetDateReminders::class);
    expect($dispatcher->forCompany($this->company->id)['queued'])->toBe(1);
    expect($dispatcher->forCompany($this->company->id)['queued'])->toBe(0);

    $job = null;
    Queue::assertPushed(DeliverRequirementTargetDateReminderJob::class, function (DeliverRequirementTargetDateReminderJob $pushed) use (&$job) {
        $job = $pushed;

        return true;
    });

    Mail::fake();
    $job->handle();
    Mail::assertSent(RequirementTargetDateReminderMail::class, 1);

    Mail::fake();
    $job->handle();
    Mail::assertNothingSent();

    expect(RecruitmentRequirementTargetDateReminder::query()
        ->where('recruitment_requirement_id', $req->id)
        ->where('status', RequirementTargetDateReminderStatus::Sent)
        ->count())->toBe(1);
});

test('concurrent claim protection prevents duplicate scheduler queues and sends', function () {
    Queue::fake();
    $req = createReminderRequirement($this, ['required_by_date' => '2026-10-07']);
    travelToCompanyLocalHour($this->company, '2026-10-07', 9);

    $dispatcher = app(DispatchRequirementTargetDateReminders::class);
    expect($dispatcher->forCompany($this->company->id)['queued'])->toBe(1);
    expect($dispatcher->forCompany($this->company->id)['queued'])->toBe(0);

    $deliveryKey = RequirementTargetDateReminderDeliveryKey::forUser($this->recruiter->id);
    $reminder = RecruitmentRequirementTargetDateReminder::query()
        ->where('recruitment_requirement_id', $req->id)
        ->firstOrFail();

    expect($reminder->status)->toBe(RequirementTargetDateReminderStatus::Processing)
        ->and($reminder->delivery_key)->toBe($deliveryKey);

    $job = null;
    Queue::assertPushed(DeliverRequirementTargetDateReminderJob::class, function (DeliverRequirementTargetDateReminderJob $pushed) use (&$job) {
        $job = $pushed;

        return true;
    });

    Mail::fake();
    $job->handle();
    Mail::assertSent(RequirementTargetDateReminderMail::class, 1);

    Mail::fake();
    $job->handle();
    Mail::assertNothingSent();
});

test('target date extension allows new reminders and stale jobs skip', function () {
    Queue::fake();
    $req = createReminderRequirement($this, ['required_by_date' => '2026-10-07']);
    travelToCompanyLocalHour($this->company, '2026-10-07', 9);

    app(DispatchRequirementTargetDateReminders::class)->forCompany($this->company->id);

    $staleJob = null;
    Queue::assertPushed(DeliverRequirementTargetDateReminderJob::class, function (DeliverRequirementTargetDateReminderJob $pushed) use (&$staleJob) {
        $staleJob = $pushed;

        return true;
    });

    $req->update(['required_by_date' => '2026-10-20']);

    Mail::fake();
    $staleJob->handle();
    Mail::assertNothingSent();
    expect(RecruitmentRequirementTargetDateReminder::query()->whereKey($staleJob->payload['reminder_id'])->value('skip_reason'))
        ->toBe('stale_target_date');

    Queue::fake();
    travelToCompanyLocalHour($this->company, '2026-10-17', 9);
    $req->update(['required_by_date' => '2026-10-20']);
    expect(app(DispatchRequirementTargetDateReminders::class)->forCompany($this->company->id)['queued'])->toBe(1);
});

test('filled requirement suppresses queued stale reminder', function () {
    $req = createReminderRequirement($this, ['required_by_date' => '2026-10-07']);
    $deliveryKey = RequirementTargetDateReminderDeliveryKey::forUser($this->recruiter->id);
    $reminder = RecruitmentRequirementTargetDateReminder::query()->create([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $req->id,
        'target_date' => '2026-10-07',
        'milestone' => RequirementTargetDateReminderMilestone::TargetDay,
        'delivery_key' => $deliveryKey,
        'status' => RequirementTargetDateReminderStatus::Pending,
        'primary_recipient_user_id' => $this->recruiter->id,
        'cc_user_ids' => [],
    ]);

    $req->update([
        'status' => RequirementStatus::Completed,
        'completed_at' => now(),
    ]);

    Mail::fake();
    (new DeliverRequirementTargetDateReminderJob([
        'reminder_id' => $reminder->id,
        'company_id' => $this->company->id,
        'requirement_id' => $req->id,
        'target_date' => '2026-10-07',
        'milestone' => RequirementTargetDateReminderMilestone::TargetDay->value,
        'delivery_key' => $deliveryKey,
        'evaluation_date' => '2026-10-07',
        'primary_recipient_user_id' => $this->recruiter->id,
        'cc_user_ids' => [],
    ]))->handle();

    Mail::assertNothingSent();
    expect($reminder->fresh()->status)->toBe(RequirementTargetDateReminderStatus::Skipped)
        ->and($reminder->fresh()->skip_reason)->toBe('status_not_eligible');
});

test('cross-company isolation prevents other company reminders', function () {
    Queue::fake();

    $foreignPosition = Position::query()->create([
        'company_id' => $this->otherCompany->id,
        'title' => 'Foreign Engineer',
        'status' => 'active',
    ]);
    $foreignRecruiter = User::factory()->create([
        'company_id' => $this->otherCompany->id,
        'email' => 'foreign.recruiter@example.com',
        'status' => 'active',
    ]);
    DB::table('company_user')->updateOrInsert(
        ['company_id' => $this->otherCompany->id, 'user_id' => $foreignRecruiter->id],
        ['status' => 'active', 'created_at' => now(), 'updated_at' => now()],
    );

    $foreignReq = RecruitmentRequirement::query()->create([
        'company_id' => $this->otherCompany->id,
        'requirement_number' => 'REQ-RM-FOREIGN',
        'client_id' => $this->client->id,
        'project_id' => $this->project->id,
        'request_received_date' => now()->subDays(5),
        'required_by_date' => '2026-10-07',
        'priority' => 'normal',
        'status' => RequirementStatus::Open,
        'assigned_to' => $foreignRecruiter->id,
        'created_by' => $foreignRecruiter->id,
        'approved_at' => now()->subDay(),
        'opened_at' => now()->subDay(),
    ]);
    RecruitmentRequirementLine::query()->create([
        'company_id' => $this->otherCompany->id,
        'recruitment_requirement_id' => $foreignReq->id,
        'position_id' => $foreignPosition->id,
        'required_headcount' => 1,
        'salary_min' => 1000,
        'salary_max' => 2000,
        'salary_currency_code' => 'AED',
        'status' => RequirementLineStatus::Open,
    ]);

    travelToCompanyLocalHour($this->company, '2026-10-07', 9);
    $result = app(DispatchRequirementTargetDateReminders::class)->forCompany($this->company->id);

    expect($result['queued'])->toBe(0);
    Queue::assertNothingPushed();
});

test('artisan command and schedule registration are available', function () {
    travelToCompanyLocalHour($this->company, '2026-10-07', 9);
    createReminderRequirement($this, ['required_by_date' => '2026-10-07']);

    Queue::fake();
    Artisan::call('recruitment:dispatch-target-date-reminders', ['--force' => true, '--company' => $this->company->id]);

    Queue::assertPushed(DeliverRequirementTargetDateReminderJob::class, 1);

    Artisan::call('schedule:list');
    expect(Artisan::output())->toContain('recruitment:dispatch-target-date-reminders');
});

test('failed reminder rows remain retryable without creating duplicates', function () {
    $req = createReminderRequirement($this, ['required_by_date' => '2026-10-07']);
    $deliveryKey = RequirementTargetDateReminderDeliveryKey::forUser($this->recruiter->id);
    $reminder = RecruitmentRequirementTargetDateReminder::query()->create([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $req->id,
        'target_date' => '2026-10-07',
        'milestone' => RequirementTargetDateReminderMilestone::TargetDay,
        'delivery_key' => $deliveryKey,
        'status' => RequirementTargetDateReminderStatus::Failed,
        'skip_reason' => 'send_failed',
        'primary_recipient_user_id' => $this->recruiter->id,
        'cc_user_ids' => [],
    ]);

    Mail::fake();
    (new DeliverRequirementTargetDateReminderJob([
        'reminder_id' => $reminder->id,
        'company_id' => $this->company->id,
        'requirement_id' => $req->id,
        'target_date' => '2026-10-07',
        'milestone' => RequirementTargetDateReminderMilestone::TargetDay->value,
        'delivery_key' => $deliveryKey,
        'evaluation_date' => '2026-10-07',
        'primary_recipient_user_id' => $this->recruiter->id,
        'cc_user_ids' => [],
    ]))->handle();

    Mail::assertSent(RequirementTargetDateReminderMail::class, 1);
    expect($reminder->fresh()->status)->toBe(RequirementTargetDateReminderStatus::Sent)
        ->and(RecruitmentRequirementTargetDateReminder::query()->where('recruitment_requirement_id', $req->id)->count())->toBe(1);
});
