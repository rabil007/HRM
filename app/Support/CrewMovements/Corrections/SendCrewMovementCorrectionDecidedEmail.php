<?php

namespace App\Support\CrewMovements\Corrections;

use App\Enums\CrewMovementCorrectionStatus;
use App\Mail\CrewMovementCorrectionDecidedMail;
use App\Models\CrewMovementCorrection;
use Illuminate\Support\Facades\Mail;

final class SendCrewMovementCorrectionDecidedEmail
{
    public function handle(CrewMovementCorrection $correction): void
    {
        $correction->loadMissing([
            'requester',
            'assignment.employee',
            'assignment.company',
            'phase',
        ]);

        $email = $correction->requester?->email;

        if ($email === null || $email === '') {
            return;
        }

        if (! in_array($correction->status, [
            CrewMovementCorrectionStatus::Approved,
            CrewMovementCorrectionStatus::Rejected,
        ], true)) {
            return;
        }

        $assignment = $correction->assignment;
        $status = $correction->status->label();

        Mail::to($email)->queue(new CrewMovementCorrectionDecidedMail(
            subjectLine: "Crew movement correction {$status}",
            organizationName: (string) ($assignment?->company?->name ?? config('app.name')),
            assignmentNo: (string) ($assignment?->assignment_no ?? ''),
            employeeName: (string) ($assignment?->employee?->name ?? ''),
            phaseLabel: (string) ($correction->phase?->phase_code->label() ?? ''),
            status: $status,
            reason: (string) $correction->reason,
            decisionNotes: $correction->decision_notes,
            correctionUrl: route('organization.crew-movement-corrections.show', $correction),
        ));
    }
}
