<?php

namespace App\Support\CrewMovements\Corrections;

use App\Enums\CrewMovementCorrectionStatus;
use App\Mail\CrewMovementCorrectionDecidedMail;
use App\Models\CrewMovementCorrection;
use App\Models\EmailTemplate;
use Illuminate\Support\Facades\Mail;

final class SendCrewMovementCorrectionDecidedEmail
{
    public const TEMPLATE_SLUG = 'crew_movement_correction_decided';

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

        if ($correction->requested_by !== null && (int) $correction->requested_by === (int) $correction->decided_by) {
            return;
        }

        if (! in_array($correction->status, [
            CrewMovementCorrectionStatus::Approved,
            CrewMovementCorrectionStatus::Rejected,
        ], true)) {
            return;
        }

        $template = EmailTemplate::query()
            ->where('slug', self::TEMPLATE_SLUG)
            ->where('enabled', true)
            ->first();

        if ($template === null) {
            return;
        }

        $assignment = $correction->assignment;
        $status = $correction->status->label();
        $organizationName = (string) ($assignment?->company?->name ?? config('app.name'));
        $assignmentNo = (string) ($assignment?->assignment_no ?? '');
        $employeeName = (string) ($assignment?->employee?->name ?? '');
        $phaseLabel = (string) ($correction->phase?->phase_code->label() ?? '');
        $reason = (string) $correction->reason;
        $decisionNotes = $correction->decision_notes;
        $correctionUrl = route('organization.crew-movement-corrections.show', $correction);

        $placeholders = [
            '{{status}}' => $status,
            '{{assignment_no}}' => $assignmentNo,
            '{{employee_name}}' => $employeeName,
            '{{phase_label}}' => $phaseLabel,
            '{{company_name}}' => $organizationName,
            '{{reason}}' => $reason !== '' ? $reason : '—',
            '{{decision_notes}}' => filled($decisionNotes) ? (string) $decisionNotes : '—',
            '{{correction_url}}' => $correctionUrl,
        ];

        $subject = strtr($template->subject, $placeholders);
        $introMessage = trim(strtr($template->body_html, $placeholders));

        Mail::to($email)->queue(new CrewMovementCorrectionDecidedMail(
            subjectLine: $subject,
            organizationName: $organizationName,
            assignmentNo: $assignmentNo,
            employeeName: $employeeName,
            phaseLabel: $phaseLabel,
            status: $status,
            reason: $reason,
            decisionNotes: $decisionNotes,
            correctionUrl: $correctionUrl,
            introMessage: $introMessage !== '' ? $introMessage : null,
            includeCompanyFooter: (bool) $template->include_company_footer,
        ));
    }
}
