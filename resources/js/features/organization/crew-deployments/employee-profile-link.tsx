import { Link } from '@inertiajs/react';
import type { MouseEvent, ReactElement, ReactNode } from 'react';
import { buildEmployeeShowUrl } from '@/features/organization/employees/build-employee-show-url';
import { cn } from '@/lib/utils';

export function EmployeeProfileLink({
    employeeId,
    children,
    className,
    stopRowNavigation = false,
}: {
    employeeId: number;
    children: ReactNode;
    className?: string;
    stopRowNavigation?: boolean;
}): ReactElement {
    const handleClick = (event: MouseEvent<HTMLAnchorElement>): void => {
        if (stopRowNavigation) {
            event.stopPropagation();
        }
    };

    return (
        <Link
            href={buildEmployeeShowUrl(employeeId)}
            className={cn(
                'font-medium text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40',
                className,
            )}
            onClick={handleClick}
        >
            {children}
        </Link>
    );
}
