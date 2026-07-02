import type { ReactElement } from 'react';
import {
    getEmployeeAvatarGradient,
    getEmployeeInitials,
    resolveEmployeeImageUrl,
} from '@/features/organization/employees/lib/employee-avatar';
import { cn } from '@/lib/utils';

const sizeClasses = {
    sm: {
        box: 'size-10 rounded-xl text-sm',
        initials: 'text-sm font-bold',
    },
    card: {
        box: 'h-full w-full min-h-[7rem] text-3xl',
        initials: 'text-3xl font-bold',
    },
    md: {
        box: 'size-28 rounded-2xl md:size-32 text-3xl',
        initials: 'text-3xl font-black md:text-4xl',
    },
    lg: {
        box: 'size-32 rounded-2xl lg:size-36 text-4xl',
        initials: 'text-4xl font-black lg:text-5xl',
    },
} as const;

type EmployeeAvatarSize = keyof typeof sizeClasses;

type EmployeeAvatarProps = {
    name: string;
    image?: string | null;
    /** Use for optimistic uploads; overrides `image` when set. */
    src?: string | null;
    /** When set, gradient is derived from this string instead of `name` (e.g. stable draft seed). */
    gradientSeed?: string;
    size?: EmployeeAvatarSize;
    className?: string;
    imageClassName?: string;
};

/**
 * Unified employee portrait: same gradient fallback and photo framing everywhere.
 */
export function EmployeeAvatar({
    name,
    image,
    src,
    gradientSeed,
    size = 'md',
    className,
    imageClassName,
}: EmployeeAvatarProps): ReactElement {
    const imageSrc = src ?? resolveEmployeeImageUrl(image);
    const initials = getEmployeeInitials(name);
    const gradient = getEmployeeAvatarGradient(gradientSeed ?? name);
    const sizing = sizeClasses[size];

    return (
        <div
            className={cn(
                'relative flex shrink-0 items-center justify-center overflow-hidden bg-gradient-to-br text-white/90',
                gradient,
                sizing.box,
                className,
            )}
        >
            {imageSrc ? (
                <img
                    src={imageSrc}
                    alt=""
                    role="presentation"
                    className={cn(
                        'absolute inset-0 h-full w-full object-cover object-center',
                        imageClassName,
                    )}
                />
            ) : (
                <span
                    className={cn('leading-none select-none', sizing.initials)}
                >
                    {initials}
                </span>
            )}
        </div>
    );
}
