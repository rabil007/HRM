<?php

namespace App\Support\Employees\Services;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeSeaService;
use App\Models\EmployeeTraining;
use App\Services\Settings\SettingService;
use App\Support\Employees\SummarizeSeaServiceExperience;
use App\Support\Settings\SettingKey;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

final class OffshoreCvData
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Employee $employee, int $companyId): array
    {
        $employee->load([
            'position:id,title',
            'rank:id,name',
            'companyVisaTypeRef:id,name',
            'visaTypeRef:id,name',
            'company:id,name,logo',
        ]);

        abort_unless((int) $employee->company_id === $companyId, 404);

        $settings = app(SettingService::class);
        $company = $employee->company ?? Company::query()->find($companyId);

        $trainings = EmployeeTraining::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employee->id)
            ->with(['course:id,name', 'country:id,name'])
            ->orderBy('sort_order')
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->get();

        $seaServices = EmployeeSeaService::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employee->id)
            ->with(['vesselType:id,name', 'rank:id,name', 'client:id,name'])
            ->orderBy('sort_order')
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get();

        $rankApplied = $employee->rank?->name ?? $employee->position?->title ?? '';

        $rankSeaServices = $employee->rank_id
            ? $seaServices->filter(fn (EmployeeSeaService $row) => (int) $row->rank_id === (int) $employee->rank_id)
            : ($rankApplied !== ''
                ? $seaServices->filter(fn (EmployeeSeaService $row) => strcasecmp((string) $row->rank?->name, $rankApplied) === 0)
                : collect());

        $experienceRankYmd = self::formatExperienceYmd($rankSeaServices);
        $experienceOffshoreYmd = self::formatExperienceYmd($seaServices);
        $experienceYears = self::yearsFromSeaServices($rankSeaServices);
        $fullName = trim((string) $employee->name);

        return [
            'company_logo_url' => self::resolveCompanyLogoSrc($company, $settings),
            'portrait_url' => self::resolvePortraitSrc($employee),
            'full_name' => strtoupper($fullName),
            'position' => $rankApplied,
            'visa_status' => (string) ($employee->companyVisaTypeRef?->name ?? $employee->visaTypeRef?->name ?? ''),
            'phone' => (string) ($employee->phone ?? ''),
            'email' => (string) ($employee->work_email ?? $employee->personal_email ?? ''),
            'professional_summary' => self::professionalSummary($fullName, $experienceRankYmd, $experienceYears),
            'trade_skills' => '—',
            'safety_competencies' => '—',
            'operations_competencies' => '—',
            'certifications' => $trainings->map(fn (EmployeeTraining $training) => [
                'certificate_name' => (string) ($training->course?->name ?? ''),
                'issuing_body' => (string) ($training->country?->name ?? ''),
                'expiry_date' => self::formatCvDate($training->expiry_date),
            ])->values()->all(),
            'offshore_projects' => $seaServices->map(fn (EmployeeSeaService $row) => [
                'vessel_name' => (string) $row->vessel_name,
                'vessel_type' => (string) ($row->vesselType?->name ?? ''),
                'rank' => (string) ($row->rank?->name ?? ''),
                'from' => self::formatCvDate($row->start_date),
                'to' => self::formatCvDate($row->end_date),
                'total_months' => (string) ($row->total_months ?? 0),
                'total_days' => (string) ($row->total_days ?? 0),
                'grt' => $row->grt !== null ? (string) $row->grt : '',
                'bhp' => $row->bhp !== null ? (string) $row->bhp : '',
                'company_name' => (string) ($row->client?->name ?? ''),
            ])->values()->all(),
            'experience_rank_ymd' => $experienceRankYmd,
            'experience_offshore_ymd' => $experienceOffshoreYmd,
            'generated_at' => now(),
        ];
    }

    private static function professionalSummary(string $fullName, string $experienceRankYmd, string $experienceYears): string
    {
        $experienceLabel = $experienceRankYmd !== '0Y/0M/0D' && $experienceRankYmd !== ''
            ? $experienceRankYmd
            : ($experienceYears !== '' ? "{$experienceYears} years" : '0Y/0M/0D');

        return sprintf(
            'Dedicated %s with %s of experience in the applied rank in the Arabian Gulf. Skilled in maintaining high safety and operational standards, ensuring project efficiency and compliance with ADNOC safety standards.',
            $fullName,
            $experienceLabel,
        );
    }

    /**
     * @param  Collection<int, EmployeeSeaService>  $services
     */
    private static function formatExperienceYmd(Collection $services): string
    {
        if ($services->isEmpty()) {
            return '0Y/0M/0D';
        }

        $rows = $services->map(fn (EmployeeSeaService $row) => [
            'total_months' => $row->total_months,
            'total_days' => $row->total_days,
            'start_date' => $row->start_date?->toDateString(),
            'end_date' => $row->end_date?->toDateString(),
        ])->all();

        return SummarizeSeaServiceExperience::formatYmd($rows);
    }

    /**
     * @param  Collection<int, EmployeeSeaService>  $services
     */
    private static function yearsFromSeaServices(Collection $services): string
    {
        if ($services->isEmpty()) {
            return '';
        }

        $rows = $services->map(fn (EmployeeSeaService $row) => [
            'total_months' => $row->total_months,
            'total_days' => $row->total_days,
            'start_date' => $row->start_date?->toDateString(),
            'end_date' => $row->end_date?->toDateString(),
        ])->all();

        $formatted = SummarizeSeaServiceExperience::formatYmd($rows);
        preg_match('/(\d+)Y/', $formatted, $matches);

        $years = (int) ($matches[1] ?? 0);

        if ($years > 0) {
            return (string) $years;
        }

        preg_match('/(\d+)M/', $formatted, $monthMatches);
        $months = (int) ($monthMatches[1] ?? 0);

        return $months > 0 ? (string) round($months / 12, 1) : '';
    }

    private static function formatCvDate(mixed $date): string
    {
        if (! $date) {
            return '';
        }

        return CarbonImmutable::parse($date)->format('d/m/Y');
    }

    private static function resolvePortraitSrc(Employee $employee): ?string
    {
        if (! filled($employee->image)) {
            return null;
        }

        return self::embedPublicDiskPath((string) $employee->image);
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
