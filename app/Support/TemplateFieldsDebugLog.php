<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

final class TemplateFieldsDebugLog
{
    public static function enabled(): bool
    {
        return filter_var(env('TEMPLATE_FIELDS_DEBUG', false), FILTER_VALIDATE_BOOL);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function write(string $context, array $data): void
    {
        if (! self::enabled()) {
            return;
        }

        Log::info('[OMS-HRM:template-fields] '.$context, $data);
    }
}
