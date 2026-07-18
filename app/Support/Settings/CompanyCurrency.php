<?php

namespace App\Support\Settings;

use App\Models\Company;
use App\Services\Settings\SettingService;

final class CompanyCurrency
{
    /**
     * @return array{code: string, symbol: string|null}
     */
    public static function forCompany(int|Company|null $company): array
    {
        $model = self::resolveCompany($company);

        $code = filled($model?->currency?->code)
            ? (string) $model->currency->code
            : null;

        $symbol = filled($model?->currency?->symbol)
            ? (string) $model->currency->symbol
            : null;

        if ($code === null) {
            $legacy = app(SettingService::class)->get(SettingKey::Currency);
            $code = filled($legacy) ? (string) $legacy : 'AED';
        }

        return [
            'code' => $code,
            'symbol' => $symbol,
        ];
    }

    public static function codeForCompany(int|Company|null $company): string
    {
        return self::forCompany($company)['code'];
    }

    private static function resolveCompany(int|Company|null $company): ?Company
    {
        if ($company instanceof Company) {
            if ($company->relationLoaded('currency')) {
                return $company;
            }

            $company->loadMissing('currency:id,code,symbol');

            return $company;
        }

        if ($company === null || $company < 1) {
            return null;
        }

        return Company::query()
            ->with('currency:id,code,symbol')
            ->select(['id', 'currency_id'])
            ->find($company);
    }
}
