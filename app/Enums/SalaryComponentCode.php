<?php

namespace App\Enums;

enum SalaryComponentCode: string
{
    case Basic = 'BASIC';
    case Housing = 'HOUSING';
    case Transport = 'TRANSPORT';
    case Other = 'OTHER';
    case StandbyRate = 'STANDBY_RATE';
    case OnsiteRate = 'ONSITE_RATE';
    case SiteAllowance = 'SITE_ALLOWANCE';
    case SupplementaryAllowance = 'SUPPLEMENTARY_ALLOWANCE';
    case OtRate = 'OT_RATE';

    public function label(): string
    {
        return match ($this) {
            self::Basic => 'Basic salary',
            self::Housing => 'Housing allowance',
            self::Transport => 'Transport allowance',
            self::Other => 'Other allowances',
            self::StandbyRate => 'Standby rate',
            self::OnsiteRate => 'Onsite rate',
            self::SiteAllowance => 'Site allowance',
            self::SupplementaryAllowance => 'Supplementary allowance',
            self::OtRate => 'OT rate',
        };
    }

    public function defaultRateType(): SalaryComponentRateType
    {
        return $this->defaultRateTypeFor(PayrollCategory::Office);
    }

    public function defaultRateTypeFor(PayrollCategory $category): SalaryComponentRateType
    {
        return match ($this) {
            self::Basic => $category === PayrollCategory::Crew
                ? SalaryComponentRateType::Daily
                : SalaryComponentRateType::Monthly,
            self::Housing, self::Transport, self::Other => SalaryComponentRateType::Monthly,
            self::StandbyRate, self::OnsiteRate, self::SiteAllowance, self::SupplementaryAllowance => SalaryComponentRateType::Daily,
            self::OtRate => SalaryComponentRateType::Hourly,
        };
    }

    /**
     * @return list<self>
     */
    public static function forPayrollCategory(PayrollCategory $category): array
    {
        return match ($category) {
            PayrollCategory::Office => [
                self::Basic,
                self::Housing,
                self::Transport,
                self::Other,
            ],
            PayrollCategory::Crew => [
                self::Basic,
                self::SiteAllowance,
                self::SupplementaryAllowance,
            ],
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
