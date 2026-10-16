<?php

namespace App\Support\Settings;

use App\Services\Settings\SettingService;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Formats dates for human display using the platform Default Date Format setting.
 *
 * Storage, imports, and HTML date inputs remain ISO Y-m-d. Use this only for
 * Inertia *_formatted props, emails, PDFs, and exports.
 */
final class PlatformDateFormatter
{
    /**
     * @var list<string>
     */
    public const SUPPORTED_FORMATS = [
        'Y-m-d',
        'd/m/Y',
        'm/d/Y',
        'd-m-Y',
        'M d, Y',
    ];

    public function __construct(
        private readonly SettingService $settings,
    ) {}

    public function format(): string
    {
        $configured = (string) $this->settings->get(
            SettingKey::DateFormat,
            SettingKey::defaults()[SettingKey::DateFormat] ?? 'Y-m-d',
        );

        return in_array($configured, self::SUPPORTED_FORMATS, true)
            ? $configured
            : 'd-m-Y';
    }

    public function date(mixed $value, ?string $empty = '—'): ?string
    {
        $carbon = $this->toCarbon($value);

        if ($carbon === null) {
            return $empty;
        }

        return $this->formatCarbonDate($carbon, $this->format());
    }

    public function dateTime(mixed $value, ?string $empty = '—', bool $twelveHour = false): ?string
    {
        $carbon = $this->toCarbon($value);

        if ($carbon === null) {
            return $empty;
        }

        $date = $this->formatCarbonDate($carbon, $this->format());
        $time = $twelveHour
            ? $carbon->format('g:i A')
            : $carbon->format('H:i');

        return "{$date} {$time}";
    }

    /**
     * Format using an explicit PHP platform format token (for tests / overrides).
     */
    public function dateWithFormat(mixed $value, string $format, ?string $empty = '—'): ?string
    {
        $carbon = $this->toCarbon($value);

        if ($carbon === null) {
            return $empty;
        }

        $resolved = in_array($format, self::SUPPORTED_FORMATS, true) ? $format : 'd-m-Y';

        return $this->formatCarbonDate($carbon, $resolved);
    }

    private function formatCarbonDate(CarbonInterface $carbon, string $format): string
    {
        return match ($format) {
            'Y-m-d' => $carbon->format('Y-m-d'),
            'd/m/Y' => $carbon->format('d/m/Y'),
            'm/d/Y' => $carbon->format('m/d/Y'),
            'M d, Y' => $carbon->format('M d, Y'),
            default => $carbon->format('d-m-Y'),
        };
    }

    private function toCarbon(mixed $value): ?CarbonInterface
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }
}
