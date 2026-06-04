<?php

namespace App\Support\EmployeeDocuments;

use App\Models\EmailTemplate;
use App\Support\Settings\ApplicationTimezone;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Schema;

class DocumentExpiryAlertSchedule
{
    public static function dispatchAt(): string
    {
        $default = (string) config('documents.expiry_alert_dispatch_at', '08:00');

        try {
            $slug = (string) config('documents.expiry_alert_template_slug', 'document_expiry_alert');

            if (! Schema::hasTable('email_templates')) {
                return $default;
            }

            $value = EmailTemplate::query()
                ->where('slug', $slug)
                ->value('dispatch_at');

            if (! is_string($value) || ! self::isValidTime($value)) {
                return $default;
            }

            return $value;
        } catch (\Throwable) {
            return $default;
        }
    }

    public static function timezone(): string
    {
        return ApplicationTimezone::identifier();
    }

    public static function now(): CarbonInterface
    {
        return Carbon::now(self::timezone());
    }

    public static function shouldRunNow(?CarbonInterface $now = null): bool
    {
        $now = ($now ?? self::now())->timezone(self::timezone());

        return $now->format('H:i') === self::dispatchAt();
    }

    public static function isValidTime(string $value): bool
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }
}
