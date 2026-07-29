<?php

namespace App\Support\Attendance\Data;

use App\Models\LeaveApprovalPolicy;

final class ResolvedLeaveApprovalChain
{
    /**
     * @param  list<ResolvedLeaveApprovalStep>  $steps
     */
    public function __construct(
        public readonly LeaveApprovalPolicy $policy,
        public readonly EffectiveLeaveApprovalPolicy $effectivePolicy,
        public readonly array $steps,
    ) {}

    public function isEmpty(): bool
    {
        return $this->steps === [];
    }

    public function firstStep(): ?ResolvedLeaveApprovalStep
    {
        return $this->steps[0] ?? null;
    }

    /**
     * @return list<ResolvedLeaveApprovalStep>
     */
    public function requiredSteps(): array
    {
        return array_values(array_filter(
            $this->steps,
            fn (ResolvedLeaveApprovalStep $step): bool => $step->isRequired,
        ));
    }
}
