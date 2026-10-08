<?php

namespace App\Jobs;

use App\Mail\RequirementOwnershipTransferMail;
use App\Models\Company;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementStatusTransition;
use App\Models\User;
use App\Support\Recruitment\RequirementNotificationRecipients;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class DeliverRequirementOwnershipTransferEmailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 60, 120];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public array $payload,
    ) {}

    public function handle(): void
    {
        $requirementId = (int) ($this->payload['requirement_id'] ?? 0);
        $companyId = (int) ($this->payload['company_id'] ?? 0);
        $transitionId = (int) ($this->payload['status_transition_id'] ?? 0);
        $recipientId = (int) ($this->payload['primary_recipient_user_id'] ?? 0);
        $role = (string) ($this->payload['role'] ?? '');

        $requirement = RecruitmentRequirement::query()
            ->whereKey($requirementId)
            ->where('company_id', $companyId)
            ->with(['client:id,name', 'project:id,title', 'company:id,name'])
            ->first();

        if ($requirement === null) {
            $this->skip('requirement_not_found');

            return;
        }

        $transition = RecruitmentRequirementStatusTransition::query()
            ->whereKey($transitionId)
            ->where('company_id', $companyId)
            ->where('recruitment_requirement_id', $requirementId)
            ->first();

        if ($transition === null) {
            $this->skip('status_transition_not_found');

            return;
        }

        $expectedStatus = (string) ($this->payload['expected_status'] ?? '');
        if ($expectedStatus !== '' && $requirement->status->value !== $expectedStatus) {
            $this->skip('status_changed');

            return;
        }

        if ($role === 'requester') {
            $expectedRequesterId = isset($this->payload['expected_requester_id'])
                ? (int) $this->payload['expected_requester_id']
                : null;
            if ($expectedRequesterId === null || (int) $requirement->created_by !== $expectedRequesterId) {
                $this->skip('requester_changed');

                return;
            }
        }

        if ($role === 'recruiter') {
            $expectedRecruiterId = isset($this->payload['expected_recruiter_id'])
                ? (int) $this->payload['expected_recruiter_id']
                : null;
            if ($expectedRecruiterId === null || (int) $requirement->assigned_to !== $expectedRecruiterId) {
                $this->skip('recruiter_changed');

                return;
            }
        }

        $primary = User::query()->find($recipientId);
        $resolved = RequirementNotificationRecipients::resolveUserIds(
            $requirement,
            $primary,
            [],
            primaryMustBeEligibleApprover: false,
        );

        if ($resolved['to_user_id'] === null) {
            $this->skip('primary_ineligible');

            return;
        }

        $toUser = User::query()->find($resolved['to_user_id']);
        $toEmail = RequirementNotificationRecipients::usableEmail($toUser);
        if ($toEmail === null) {
            $this->skip('primary_missing_usable_email');

            return;
        }

        $companyName = Company::query()->whereKey($companyId)->value('name')
            ?? (string) ($requirement->company?->name ?? 'OMS-HRM');
        $requirementNumber = (string) $requirement->requirement_number;
        $roleLabel = $role === 'requester' ? 'requester' : 'assigned recruiter';
        $requirementUrl = url("/organization/recruitment/requirements/{$requirement->id}");

        Mail::to($toEmail)->send(new RequirementOwnershipTransferMail(
            subjectLine: "You are now the {$roleLabel} for requirement {$requirementNumber}",
            organizationName: (string) $companyName,
            requirementNumber: $requirementNumber,
            heading: 'Requirement ownership transferred',
            details: [
                ['label' => 'Requirement', 'value' => $requirementNumber],
                ['label' => 'Your role', 'value' => ucfirst($roleLabel)],
                ['label' => 'Client', 'value' => (string) ($requirement->client?->name ?? '—')],
                ['label' => 'Status', 'value' => $requirement->status->label()],
            ],
            requirementUrl: $requirementUrl,
            introMessage: "You have been assigned as the {$roleLabel} for this recruitment requirement. Open the requirement to continue the workflow.",
        ));
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Requirement ownership transfer email job failed after retries.', [
            'requirement_id' => (int) ($this->payload['requirement_id'] ?? 0),
            'company_id' => (int) ($this->payload['company_id'] ?? 0),
            'status_transition_id' => $this->payload['status_transition_id'] ?? null,
            'exception_class' => $exception::class,
            'exception_message' => mb_substr($exception->getMessage(), 0, 500),
        ]);
    }

    private function skip(string $reason): void
    {
        Log::info('Requirement ownership transfer email skipped.', [
            'requirement_id' => (int) ($this->payload['requirement_id'] ?? 0),
            'company_id' => (int) ($this->payload['company_id'] ?? 0),
            'reason' => $reason,
        ]);
    }
}
