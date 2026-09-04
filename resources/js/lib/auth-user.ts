import type { User } from '@/types';

export function resolveAuthUser(
    auth: { user?: User | null } | null | undefined,
): User | null {
    return auth?.user ?? null;
}
