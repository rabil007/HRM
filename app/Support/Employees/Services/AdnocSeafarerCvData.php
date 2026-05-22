<?php

namespace App\Support\Employees\Services;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\EmployeeEducationQualification;
use App\Models\EmployeeLanguage;
use App\Models\EmployeeSeaService;
use App\Models\EmployeeTraining;
use App\Services\Settings\SettingService;
use App\Support\Employees\SummarizeSeaServiceExperience;
use App\Support\Settings\SettingKey;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class AdnocSeafarerCvData
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Employee $employee, int $companyId): array
    {
        $employee->load([
            'branch:id,name',
            'department:id,name',
            'position:id,title',
            'rank:id,name',
            'religionRef:id,name',
            'genderRef:id,name',
            'nationalityRef:id,name,code',
            'company:id,name,logo',
        ]);

        abort_unless((int) $employee->company_id === $companyId, 404);

        $settings = app(SettingService::class);
        $company = $employee->company ?? Company::query()->find($companyId);

        $documents = EmployeeDocument::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employee->id)
            ->with('documentType:id,title')
            ->get();

        $passportDoc = self::findDocumentByTitle($documents, ['passport']);
        $seamanDoc = self::findDocumentByTitle($documents, ['seaman', 'seafarer', 'seaman book', 'seaman\'s']);

        $educations = EmployeeEducationQualification::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employee->id)
            ->with(['country:id,name'])
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->get();

        $trainings = EmployeeTraining::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employee->id)
            ->with(['course:id,name', 'country:id,name'])
            ->orderBy('sort_order')
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->get();

        $languages = EmployeeLanguage::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employee->id)
            ->orderBy('sort_order')
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

        $cocTrainings = $trainings->filter(fn (EmployeeTraining $t) => self::matchesDpOrCoc($t->course?->name));
        $dpTrainings = $trainings->filter(fn (EmployeeTraining $t) => self::isDpCourse($t->course?->name));
        $stcwTrainings = $trainings->reject(
            fn (EmployeeTraining $t) => self::isDpCourse($t->course?->name) || self::matchesDpOrCoc($t->course?->name),
        );

        $offshoreRows = $seaServices->filter(fn (EmployeeSeaService $s) => $s->is_offshore)->values();
        $rankSeaServices = $seaServices->filter(
            fn (EmployeeSeaService $s) => $rankApplied !== '' && strcasecmp((string) $s->rank?->name, $rankApplied) === 0,
        );

        $branding = $settings->brandingUrls();
        $mailBranding = $settings->mailBranding();
        $logoUrl = self::resolveAdnocCvLogoSrc()
            ?? self::resolveAbsoluteUrl(
                $branding['main_logo_url']
                ?? $branding['email_branding_logo_url']
                ?? $mailBranding['logo_src']
                ?? null,
            );

        $dob = $employee->date_of_birth ? CarbonImmutable::parse($employee->date_of_birth) : null;

        return [
            'logo_url' => $logoUrl,
            'source_of_cv' => strtoupper((string) (
                $settings->get(SettingKey::AppName)
                ?? $company?->name
                ?? 'OMS'
            )),
            'agency_name' => strtoupper((string) ($company?->name ?? '')),
            'position_applied' => strtoupper($rankApplied),
            'full_name' => strtoupper((string) $employee->name),
            'dob_age' => $dob ? $dob->format('d/m/Y').'  '.$dob->age : '',
            'religion' => strtoupper((string) ($employee->religionRef?->name ?? '')),
            'nationality' => strtoupper((string) ($employee->nationalityRef?->name ?? '')),
            'passport_number' => strtoupper((string) ($employee->passport_number ?? $passportDoc?->document_number ?? '')),
            'passport_issue' => self::formatCvDate($passportDoc?->issue_date),
            'passport_expiry' => self::formatCvDate($passportDoc?->expiry_date),
            'place_of_birth' => strtoupper((string) ($employee->place_of_birth ?? '')),
            'weight_height' => '',
            'nearest_airport' => strtoupper((string) ($employee->nearest_airport ?? '')),
            'marital_status' => strtoupper((string) ($employee->marital_status ?? '')),
            'spouse_name' => strtoupper((string) ($employee->spouse_name ?? '')),
            'children_count' => '',
            'seaman_book_no' => strtoupper((string) ($seamanDoc?->document_number ?? '')),
            'seaman_issue' => self::formatCvDate($seamanDoc?->issue_date),
            'seaman_expiry' => self::formatCvDate($seamanDoc?->expiry_date),
            'permanent_address' => strtoupper((string) ($employee->address ?? '')),
            'phone_home_country' => (string) ($employee->phone_home_country ?? ''),
            'mobile_phone' => (string) ($employee->phone ?? ''),
            'residence_number' => '',
            'skype_id' => '',
            'email' => strtoupper((string) ($employee->work_email ?? $employee->personal_email ?? '')),
            'emergency_uae_label' => 'UAE',
            'emergency_uae' => self::formatEmergency($employee->emergency_contact, $employee->emergency_phone),
            'emergency_home_label' => 'HOME COUNTRY',
            'emergency_home' => '',
            'relative_name' => '',
            'relative_relationship' => '',
            'relative_department' => '',
            'educations' => $educations->map(fn (EmployeeEducationQualification $row) => [
                'degree' => strtoupper((string) $row->certificate),
                'major' => '',
                'issue_date' => self::formatCvDate($row->issue_date),
                'university' => strtoupper((string) $row->university),
                'country' => strtoupper((string) ($row->country?->name ?? '')),
            ])->all(),
            'coc_certificates' => $cocTrainings->map(fn (EmployeeTraining $row) => [
                'capacity' => strtoupper((string) $row->course?->name),
                'regulation' => '',
                'issue_date' => self::formatCvDate($row->issue_date),
                'expiry_date' => self::formatCvDate($row->expiry_date),
                'issuing_authority' => strtoupper((string) $row->institute_center),
                'country' => strtoupper((string) ($row->country?->name ?? '')),
                'limitations' => 'UNLIMITED',
            ])->values()->all(),
            'dp_certifications' => $dpTrainings->map(fn (EmployeeTraining $row) => [
                'certificate' => strtoupper((string) $row->course?->name),
                'certificate_number' => '',
                'issue_date' => self::formatCvDate($row->issue_date),
                'expiry_date' => self::formatCvDate($row->expiry_date),
                'issuing_authority' => strtoupper((string) $row->institute_center),
                'country' => strtoupper((string) ($row->country?->name ?? '')),
                'limitations' => '',
            ])->values()->all(),
            'stcw_courses' => $stcwTrainings->map(fn (EmployeeTraining $row) => [
                'name' => strtoupper((string) $row->course?->name),
                'issue_date' => self::formatCvDate($row->issue_date),
                'expiry_date' => self::formatCvDate($row->expiry_date),
                'institute' => strtoupper(trim(implode(' / ', array_filter([
                    $row->institute_center,
                    $row->country?->name,
                ])))),
            ])->all(),
            'languages' => $languages->map(fn (EmployeeLanguage $row) => [
                'name' => strtoupper((string) $row->language_name),
                'spoken' => self::languageLevel($row->is_spoken),
                'written' => self::languageLevel($row->is_written),
                'understood' => self::languageLevel($row->is_understood),
                'mother_tongue' => $row->is_mother_tongue ? strtoupper((string) $row->language_name) : '',
            ])->all(),
            'stcw_display_rows' => 8,
            'health_questions' => [
                [
                    'question' => 'Have you ever signed off from a ship due to medical reason?',
                    'yes' => false,
                    'no' => true,
                    'details' => '',
                ],
                [
                    'question' => 'Have you ever undergone any medical operation(s) in the past?',
                    'yes' => false,
                    'no' => true,
                    'details' => '',
                ],
                [
                    'question' => 'Have you consulted a doctor during past 12 months for an illness / accident?',
                    'yes' => false,
                    'no' => true,
                    'details' => '',
                ],
                [
                    'question' => 'Do you have any health or disability problem now?',
                    'yes' => false,
                    'no' => true,
                    'details' => '',
                ],
            ],
            'general_questions' => [
                [
                    'question' => 'Have you ever been the subject of a court of enquiry or involved in a maritime accident',
                    'yes' => false,
                    'no' => true,
                    'details' => '',
                ],
                [
                    'question' => 'Have you ever had a professional license suspended or revoked',
                    'yes' => false,
                    'no' => true,
                    'details' => '',
                ],
            ],
            'sea_services' => $seaServices->map(fn (EmployeeSeaService $row) => [
                'vessel_name' => (string) $row->vessel_name,
                'vessel_type' => (string) ($row->vesselType?->name ?? ''),
                'rank' => (string) ($row->rank?->name ?? ''),
                'from' => self::formatCvDate($row->start_date),
                'to' => self::formatCvDate($row->end_date),
                'months' => (string) ($row->total_months ?? 0),
                'days' => (string) ($row->total_days ?? 0),
                'grt' => $row->grt !== null ? (string) $row->grt : '',
                'bhp' => $row->bhp !== null ? (string) $row->bhp : '',
                'company' => (string) ($row->client?->name ?? ''),
            ])->all(),
            'experience_rank_years' => self::yearsLabel($rankSeaServices),
            'experience_offshore_years' => self::yearsFromSeaServices($offshoreRows),
            'experience_dp_hours' => '',
            'references' => [],
            'declaration_date' => now()->timezone(config('app.timezone'))->format('d-m-Y'),
            'generated_at' => now(),
        ];
    }

    private static function resolveAdnocCvLogoSrc(): ?string
    {
        $path = public_path('adnoc-logo.png');

        if (! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode($contents);
    }

    private static function resolveAbsoluteUrl(?string $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        if (str_starts_with($url, '//')) {
            return 'https:'.$url;
        }

        if (str_starts_with($url, '/')) {
            return url($url);
        }

        return $url;
    }

    /**
     * @param  iterable<int, EmployeeDocument>  $documents
     * @param  list<string>  $patterns
     */
    private static function findDocumentByTitle(iterable $documents, array $patterns): ?EmployeeDocument
    {
        foreach ($documents as $document) {
            $title = Str::lower((string) ($document->documentType?->title ?? $document->document_type ?? ''));

            foreach ($patterns as $pattern) {
                if (str_contains($title, Str::lower($pattern))) {
                    return $document;
                }
            }
        }

        return null;
    }

    private static function formatCvDate(mixed $date): string
    {
        if (! $date) {
            return '';
        }

        return CarbonImmutable::parse($date)->format('d/m/Y');
    }

    private static function formatEmergency(?string $contact, ?string $phone): string
    {
        return strtoupper(trim(implode(' ', array_filter([$contact, $phone]))));
    }

    private static function languageLevel(bool $flag): string
    {
        return $flag ? 'V.GOOD' : '';
    }

    private static function matchesDpOrCoc(?string $name): bool
    {
        $name = Str::lower((string) $name);

        return str_contains($name, 'master')
            || str_contains($name, 'coc')
            || str_contains($name, 'competency')
            || str_contains($name, 'officer');
    }

    private static function isDpCourse(?string $name): bool
    {
        $name = Str::lower((string) $name);

        return str_contains($name, 'dp');
    }

    /**
     * @param  Collection<int, EmployeeSeaService>  $services
     */
    private static function yearsFromSeaServices($services): string
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

    /**
     * @param  Collection<int, EmployeeSeaService>  $services
     */
    private static function yearsLabel($services): string
    {
        return self::yearsFromSeaServices($services);
    }
}
