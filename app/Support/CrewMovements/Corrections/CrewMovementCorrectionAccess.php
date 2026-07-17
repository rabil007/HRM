<?php

namespace App\Support\CrewMovements\Corrections;

use App\Models\CrewMovementCorrection;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class CrewMovementCorrectionAccess
{
    public static function assertInCompany(CrewMovementCorrection $correction, int $companyId): void
    {
        if ((int) $correction->company_id !== $companyId) {
            throw new HttpException(404);
        }
    }

    public static function canSelfApprove(?User $user): bool
    {
        return $user?->can('crew_operations.corrections.override') ?? false;
    }

    public static function canApproveCorrection(?User $user, CrewMovementCorrection $correction): bool
    {
        if ($user === null || ! $user->can('crew_operations.corrections.approve')) {
            return false;
        }

        if ((int) $correction->requested_by === (int) $user->id) {
            return self::canSelfApprove($user);
        }

        return true;
    }
}
