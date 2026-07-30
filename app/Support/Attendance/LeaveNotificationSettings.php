<?php

namespace App\Support\Attendance;

use App\Models\CompanyLeaveApprovalSetting;

/**
 * Read-only resolver for company leave-request email notification preferences.
 *
 * Does not create a settings row. Missing rows behave as though every switch is enabled.
 */
final class LeaveNotificationSettings
{
    public function __construct(
        private readonly CompanyLeaveApprovalSetting $settings,
    ) {}

    public static function forCompany(int $companyId): self
    {
        return new self(CompanyLeaveApprovalSetting::findForCompany($companyId));
    }

    public function emailNotificationsEnabled(): bool
    {
        return $this->boolOrDefault($this->settings->email_notifications_enabled);
    }

    public function shouldNotifyOnSubmission(): bool
    {
        return $this->emailNotificationsEnabled()
            && $this->boolOrDefault($this->settings->notify_on_submission);
    }

    public function shouldNotifyOnUpdate(): bool
    {
        return $this->emailNotificationsEnabled()
            && $this->boolOrDefault($this->settings->notify_on_update);
    }

    public function shouldNotifyNextApprover(): bool
    {
        return $this->emailNotificationsEnabled()
            && $this->boolOrDefault($this->settings->notify_next_approver);
    }

    public function shouldNotifyOnFinalDecision(): bool
    {
        return $this->emailNotificationsEnabled()
            && $this->boolOrDefault($this->settings->notify_on_final_decision);
    }

    /**
     * Copy/CC the deciding approver on the final decision email.
     * Only meaningful when final-decision notifications are enabled.
     */
    public function shouldCopyDecidingApprover(): bool
    {
        return $this->shouldNotifyOnFinalDecision()
            && $this->boolOrDefault($this->settings->copy_deciding_approver);
    }

    private function boolOrDefault(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        return (bool) $value;
    }
}
