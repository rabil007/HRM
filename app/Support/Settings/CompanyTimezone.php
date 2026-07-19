<?php

namespace App\Support\Settings;

use App\Models\Company;

final class CompanyTimezone
{
    public static function forCompany(int|Company|null $company): string
    {
        $model = self::resolveCompany($company);
        $timezone = is_string($model?->timezone) ? trim($model->timezone) : '';

        if ($timezone !== '' && in_array($timezone, timezone_identifiers_list(), true)) {
            return $timezone;
        }

        return ApplicationTimezone::identifier();
    }

    public static function forCompanyId(int $companyId): string
    {
        return self::forCompany($companyId);
    }

    private static function resolveCompany(int|Company|null $company): ?Company
    {
        if ($company instanceof Company) {
            return $company;
        }

        if ($company === null || $company < 1) {
            return null;
        }

        return Company::query()
            ->select(['id', 'timezone'])
            ->find($company);
    }
}
