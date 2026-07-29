<?php

namespace App\Support\Attendance\Data;

use App\Models\Department;
use App\Models\LeaveApprovalPolicy;

final class EffectiveLeaveApprovalPolicy
{
    public const SOURCE_DIRECT = 'direct';

    public const SOURCE_INHERITED = 'inherited';

    public const SOURCE_COMPANY_DEFAULT = 'company_default';

    public function __construct(
        public readonly LeaveApprovalPolicy $policy,
        public readonly string $source,
        public readonly ?Department $sourceDepartment = null,
    ) {}

    public function isDirect(): bool
    {
        return $this->source === self::SOURCE_DIRECT;
    }

    public function isInherited(): bool
    {
        return $this->source === self::SOURCE_INHERITED;
    }

    public function isCompanyDefault(): bool
    {
        return $this->source === self::SOURCE_COMPANY_DEFAULT;
    }
}
