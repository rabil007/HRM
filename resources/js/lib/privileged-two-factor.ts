import type { TwoFactorStatus } from '@/types/auth';

export function needsPrivilegedTwoFactorEnrollment(
    twoFactor?: TwoFactorStatus | null,
): boolean {
    return Boolean(
        twoFactor?.required_for_privileged_actions && !twoFactor.enabled,
    );
}
