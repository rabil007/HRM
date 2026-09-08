<?php

namespace App\Support\Queue;

use App\Services\Settings\SettingService;
use App\Support\Settings\SettingKey;
use Throwable;

final class JobRunRetention
{
    public const MIN_DAYS = 1;

    public const MAX_DAYS = 3650;

    public const DEFAULT_COMPLETED_DAYS = 30;

    public const DEFAULT_FAILED_DAYS = 90;

    public const DEFAULT_RUNNING_DAYS = 90;

    public const DEFAULT_DELETED_DAYS = 30;

    public function completedDays(): int
    {
        return $this->days(SettingKey::JobRunCompletedRetentionDays, self::DEFAULT_COMPLETED_DAYS);
    }

    public function failedDays(): int
    {
        return $this->days(SettingKey::JobRunFailedRetentionDays, self::DEFAULT_FAILED_DAYS);
    }

    public function runningDays(): int
    {
        return $this->days(SettingKey::JobRunRunningRetentionDays, self::DEFAULT_RUNNING_DAYS);
    }

    public function deletedDays(): int
    {
        return $this->days(SettingKey::JobRunDeletedRetentionDays, self::DEFAULT_DELETED_DAYS);
    }

    /**
     * @return array<string, string>
     */
    public function storedValues(): array
    {
        return [
            SettingKey::JobRunCompletedRetentionDays => (string) $this->completedDays(),
            SettingKey::JobRunFailedRetentionDays => (string) $this->failedDays(),
            SettingKey::JobRunRunningRetentionDays => (string) $this->runningDays(),
            SettingKey::JobRunDeletedRetentionDays => (string) $this->deletedDays(),
        ];
    }

    private function days(string $key, int $fallback): int
    {
        try {
            $value = app(SettingService::class)->get($key, (string) $fallback);
        } catch (Throwable) {
            $value = (string) $fallback;
        }

        if ($value === null || ! is_numeric($value)) {
            return $this->clamp($fallback);
        }

        return $this->clamp((int) $value);
    }

    private function clamp(int $days): int
    {
        return min(self::MAX_DAYS, max(self::MIN_DAYS, $days));
    }
}
