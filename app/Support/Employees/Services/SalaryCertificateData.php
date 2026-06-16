<?php

namespace App\Support\Employees\Services;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Services\Settings\SettingService;
use App\Support\Settings\SettingKey;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

final class SalaryCertificateData
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Employee $employee, int $companyId): array
    {
        $employee->load([
            'company:id,name,logo,currency_id',
            'company.currency:id,code,symbol',
            'position:id,title',
            'rank:id,name',
            'nationalityRef:id,name',
            'currentContract',
        ]);

        abort_unless((int) $employee->company_id === $companyId, 404);

        $settings = app(SettingService::class);
        $company = $employee->company ?? Company::query()->with('currency:id,code,symbol')->find($companyId);
        $contract = $employee->currentContract;
        $timezone = (string) ($company?->timezone ?? config('app.timezone', 'UTC'));
        $issuedOn = now($timezone);

        $companyName = (string) ($settings->get(SettingKey::CompanyName) ?: $company?->name ?: '');
        $hrEmail = (string) ($settings->get(SettingKey::SupportEmail) ?: $company?->email ?: '');

        $currencyCode = (string) ($company?->currency?->code ?? 'AED');
        $basicSalary = self::formatMoney($contract?->basic_salary);
        $totalSalary = self::formatMoney(self::totalSalaryAmount($contract));

        $startDate = $contract?->start_date ?? $employee->hire_date;

        return [
            'company_logo_url' => self::resolveCompanyLogoSrc($company, $settings),
            'signature_image_url' => self::resolveOptionalImageSrc(
                $settings,
                SettingKey::SalaryCertificateSignature,
                'images/salary-certificate/signature.png',
            ),
            'stamp_image_url' => self::resolveOptionalImageSrc(
                $settings,
                SettingKey::SalaryCertificateStamp,
                'images/salary-certificate/stamp.png',
            ),
            'issued_on' => $issuedOn->format('M d, Y'),
            'company_name' => $companyName,
            'employment_basis' => self::employmentBasisLabel($contract?->contract_type),
            'employee_name' => strtoupper((string) $employee->name),
            'emirates_id' => (string) ($employee->emirates_id ?? ''),
            'passport_number' => (string) ($employee->passport_number ?? ''),
            'nationality' => (string) ($employee->nationalityRef?->name ?? ''),
            'designation' => (string) ($employee->position?->title ?? $employee->rank?->name ?? ''),
            'start_date' => $startDate ? CarbonImmutable::parse($startDate)->format('M d, Y') : '',
            'basic_salary' => $basicSalary,
            'total_salary' => $totalSalary,
            'currency_code' => $currencyCode,
            'hr_email' => $hrEmail,
        ];
    }

    private static function employmentBasisLabel(?string $contractType): string
    {
        return match ($contractType) {
            'part_time' => 'Part-Time',
            'contract' => 'Contract',
            default => 'Full-Time',
        };
    }

    private static function totalSalaryAmount(?EmployeeContract $contract): float
    {
        if ($contract === null) {
            return 0.0;
        }

        return (float) ($contract->basic_salary ?? 0)
            + (float) ($contract->supplementary_allowance ?? 0)
            + (float) ($contract->site_allowance ?? 0);
    }

    private static function formatMoney(mixed $amount): string
    {
        return number_format((float) ($amount ?? 0), 2, '.', '');
    }

    private static function resolveCompanyLogoSrc(?Company $company, SettingService $settings): ?string
    {
        if (filled($company?->logo)) {
            $embedded = self::embedPublicDiskPath((string) $company->logo);

            if ($embedded !== null) {
                return $embedded;
            }
        }

        foreach ([
            SettingKey::MainLogo,
            SettingKey::EmailBrandingLogo,
            SettingKey::SidebarLogo,
            SettingKey::LoginLogo,
        ] as $key) {
            $path = $settings->get($key);

            if (filled($path)) {
                $embedded = self::embedPublicDiskPath((string) $path);

                if ($embedded !== null) {
                    return $embedded;
                }
            }
        }

        return null;
    }

    private static function resolveOptionalImageSrc(
        SettingService $settings,
        string $settingKey,
        string $publicRelativePath,
    ): ?string {
        $path = $settings->get($settingKey);

        if (filled($path)) {
            $embedded = self::embedPublicDiskPath((string) $path);

            if ($embedded !== null) {
                return $embedded;
            }
        }

        return self::embedFilePath(public_path($publicRelativePath));
    }

    private static function embedPublicDiskPath(string $path): ?string
    {
        $disk = Storage::disk('public');

        if (! $disk->exists($path)) {
            return null;
        }

        return self::embedFilePath($disk->path($path));
    }

    private static function embedFilePath(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        $mime = self::detectImageMimeType($contents, $path);

        if ($mime === null) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode($contents);
    }

    private static function detectImageMimeType(string $contents, string $reference): ?string
    {
        $extension = strtolower((string) pathinfo(parse_url($reference, PHP_URL_PATH) ?? $reference, PATHINFO_EXTENSION));

        if ($extension === 'svg' || str_contains(strtolower(substr($contents, 0, 256)), '<svg')) {
            return null;
        }

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);

            if ($finfo !== false) {
                $detected = finfo_buffer($finfo, $contents);
                finfo_close($finfo);

                if (is_string($detected) && str_starts_with($detected, 'image/')) {
                    return $detected;
                }
            }
        }

        return match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => null,
        };
    }
}
