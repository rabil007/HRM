<?php

use App\Enums\CrewMovementCorrectionStatus;
use App\Mail\CrewMovementCorrectionDecidedMail;
use App\Models\CrewMovementCorrection;
use App\Models\EmailTemplate;
use App\Models\User;
use App\Support\CrewMovements\Corrections\SendCrewMovementCorrectionDecidedEmail;
use App\Support\Email\EmailTemplatePreview;
use Database\Seeders\EmailTemplatesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    (new EmailTemplatesSeeder)->run();
});

/**
 * @return array{correction: CrewMovementCorrection, requester: User, decider: User}
 */
function makeDecidedCorrectionForEmail(): array
{
    $fixtures = makeCrewAssignmentFixtures();
    $requester = $fixtures['user'];
    $requester->update([
        'email' => 'correction-requester@example.com',
    ]);

    $decider = User::factory()->create([
        'email' => 'correction-decider@example.com',
        'company_id' => $fixtures['company']->id,
        'status' => 'active',
    ]);
    DB::table('company_user')->insert([
        'company_id' => $fixtures['company']->id,
        'user_id' => $decider->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $vessel = makeCrewMovementVessel('Correction Email Vessel');
    $assignment = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $vessel,
    );
    $phase = $assignment->currentPhase;

    $correction = CrewMovementCorrection::query()->create([
        'company_id' => $fixtures['company']->id,
        'crew_assignment_id' => $assignment->id,
        'crew_assignment_phase_id' => $phase->id,
        'status' => CrewMovementCorrectionStatus::Approved,
        'original_values' => ['actual_start_at' => $phase->actual_start_at?->toIso8601String()],
        'proposed_values' => ['actual_start_at' => $phase->actual_start_at?->copy()->addDay()->toIso8601String()],
        'applied_values' => ['actual_start_at' => $phase->actual_start_at?->copy()->addDay()->toIso8601String()],
        'reason' => 'Incorrect join date recorded.',
        'decision_notes' => 'Updated to match passport stamp.',
        'requested_by' => $requester->id,
        'decided_by' => $decider->id,
        'requested_at' => now()->subHour(),
        'decided_at' => now(),
    ]);

    return [
        'correction' => $correction->fresh(['requester', 'assignment.employee', 'assignment.company', 'phase']),
        'requester' => $requester,
        'decider' => $decider,
    ];
}

test('decided email uses editable email template subject and body', function () {
    ['correction' => $correction] = makeDecidedCorrectionForEmail();

    EmailTemplate::query()
        ->where('slug', SendCrewMovementCorrectionDecidedEmail::TEMPLATE_SLUG)
        ->update([
            'subject' => 'Correction {{status}} for {{assignment_no}}',
            'body_html' => 'Hello — {{employee_name}} correction was {{status}}.',
        ]);

    app(SendCrewMovementCorrectionDecidedEmail::class)->handle($correction);

    Mail::assertQueued(CrewMovementCorrectionDecidedMail::class, function (CrewMovementCorrectionDecidedMail $mail) use ($correction) {
        return $mail->hasTo('correction-requester@example.com')
            && str_contains($mail->subjectLine, 'Approved')
            && str_contains($mail->subjectLine, (string) $correction->assignment?->assignment_no)
            && $mail->introMessage === 'Hello — '.$correction->assignment?->employee?->name.' correction was Approved.';
    });
});

test('decided email is skipped when template is disabled', function () {
    ['correction' => $correction] = makeDecidedCorrectionForEmail();

    EmailTemplate::query()
        ->where('slug', SendCrewMovementCorrectionDecidedEmail::TEMPLATE_SLUG)
        ->update(['enabled' => false]);

    app(SendCrewMovementCorrectionDecidedEmail::class)->handle($correction);

    Mail::assertNothingQueued();
});

test('email template preview renders crew movement correction layout', function () {
    $template = EmailTemplate::query()
        ->where('slug', SendCrewMovementCorrectionDecidedEmail::TEMPLATE_SLUG)
        ->firstOrFail();

    $preview = app(EmailTemplatePreview::class)->render($template);

    expect($preview['subject'])->toContain('Approved')
        ->and($preview['html'])->toContain('email-detail-row')
        ->and($preview['html'])->toContain('CA-1001')
        ->and($preview['html'])->toContain('View correction');
});
