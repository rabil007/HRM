import { Link, router } from '@inertiajs/react';
import { Anchor, CalendarDays, ChevronLeft, ChevronRight, FileText, Printer, ScrollText, User, UserPlus } from 'lucide-react';
import type { ComponentType, ReactElement } from 'react';
import { show } from '@/actions/App/Http/Controllers/Organization/EmployeeController';
import { cn } from '@/lib/utils';
import type { EmployeeNavigation } from '@/pages/organization/employee-page.types';

type SmartButtonProps = {
    icon: ComponentType<{ className?: string }>;
    label: string;
    stat?: string | number | null;
    onClick?: () => void;
    href?: string;
    target?: string;
    active?: boolean;
    iconColor?: string;
    iconBg?: string;
};

function SmartButton({
    icon: Icon,
    label,
    stat,
    onClick,
    href,
    target,
    active = false,
    iconColor = 'text-muted-foreground',
    iconBg = 'bg-muted/40',
}: SmartButtonProps): ReactElement {
    const hasStat = stat !== undefined && stat !== null && stat !== '';

    const className = cn(
        'group flex items-center gap-2 rounded-xl px-3 py-1.5 text-sm font-medium transition-all duration-150',
        'hover:bg-muted/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
        active && 'bg-primary/10 text-primary',
    );

    const content = (
        <>
            <span
                className={cn(
                    'flex h-6 w-6 shrink-0 items-center justify-center rounded-md transition-transform duration-150 group-hover:scale-105',
                    iconBg,
                )}
            >
                <Icon className={cn('h-3 w-3', iconColor)} />
            </span>
            {hasStat ? (
                <span className="flex min-w-0 flex-col items-start leading-none">
                    <span className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground/80">
                        {label}
                    </span>
                    <span className="text-base font-bold tabular-nums tracking-tight text-foreground">
                        {stat}
                    </span>
                </span>
            ) : (
                <span className="font-semibold text-foreground/90 group-hover:text-foreground">
                    {label}
                </span>
            )}
        </>
    );

    if (href) {
        if (target) {
            return (
                <a
                    href={href}
                    target={target}
                    rel="noopener noreferrer"
                    className={className}
                >
                    {content}
                </a>
            );
        }

        return (
            <Link href={href} className={className}>
                {content}
            </Link>
        );
    }

    return (
        <button type="button" onClick={onClick} className={className}>
            {content}
        </button>
    );
}

function InlineNavigation({
    navigation,
    onNavigate,
}: {
    navigation: EmployeeNavigation;
    onNavigate?: (employeeId: number) => void;
}): ReactElement | null {
    if (navigation.total <= 0) {
        return null;
    }

    const visitEmployee = (employeeId: number) => {
        if (onNavigate) {
            onNavigate(employeeId);

            return;
        }

        router.visit(
            show.url({ employee: employeeId }, { query: navigation.list_query }),
            { preserveScroll: true },
        );
    };

    const hasPrev = navigation.previous_id !== null;
    const hasNext = navigation.next_id !== null;

    return (
        <div className="flex shrink-0 self-stretch overflow-hidden rounded-xl border border-border/60 dark:border-white/8">
            <button
                type="button"
                aria-label="Previous employee"
                title="Previous employee"
                disabled={!hasPrev}
                onClick={() => {
                    if (navigation.previous_id !== null) {
                        visitEmployee(navigation.previous_id);
                    }
                }}
                className="flex w-10 items-center justify-center text-muted-foreground transition-all duration-150 hover:bg-muted/60 hover:text-foreground disabled:cursor-not-allowed disabled:opacity-30"
            >
                <ChevronLeft className="h-4 w-4" />
            </button>

            <div className="flex min-w-[3.5rem] items-center justify-center border-x border-border/60 bg-muted/30 px-2.5 dark:border-white/8">
                <span className="text-xs font-bold tabular-nums text-foreground/80">
                    {navigation.position}
                    <span className="mx-0.5 font-medium text-muted-foreground">/</span>
                    {navigation.total}
                </span>
            </div>

            <button
                type="button"
                aria-label="Next employee"
                title="Next employee"
                disabled={!hasNext}
                onClick={() => {
                    if (navigation.next_id !== null) {
                        visitEmployee(navigation.next_id);
                    }
                }}
                className="flex w-10 items-center justify-center text-muted-foreground transition-all duration-150 hover:bg-muted/60 hover:text-foreground disabled:cursor-not-allowed disabled:opacity-30"
            >
                <ChevronRight className="h-4 w-4" />
            </button>
        </div>
    );
}

export function EmployeeProfileActionBar({
    printCvUrl,
    printOffshoreCvUrl,
    printSalaryCertificateUrl,
    employeeNavigation,
    onNavigateEmployee,
    showDocumentsButton = false,
    documentCount,
    documentsBrowseUrl,
    showCreateUserButton = false,
    onCreateUser,
    linkedUser = null,
    showLinkedUserButton = false,
    showAttendanceCalendarButton = false,
    attendanceCalendarUrl,
}: {
    printCvUrl: string;
    printOffshoreCvUrl: string;
    printSalaryCertificateUrl: string;
    employeeNavigation?: EmployeeNavigation | null;
    onNavigateEmployee?: (employeeId: number) => void;
    showDocumentsButton?: boolean;
    documentCount?: number | null;
    documentsBrowseUrl?: string;
    showCreateUserButton?: boolean;
    onCreateUser?: () => void;
    linkedUser?: {
        id: number;
        name: string | null;
        email?: string | null;
    } | null;
    showLinkedUserButton?: boolean;
    showAttendanceCalendarButton?: boolean;
    attendanceCalendarUrl?: string;
}): ReactElement {
    return (
        <div className="flex items-stretch justify-between gap-0 overflow-hidden rounded-2xl border border-border/60 bg-card/80 shadow-sm backdrop-blur-sm dark:border-white/8 dark:bg-white/4">
            {/* Left — action buttons */}
            <div className="flex min-w-0 flex-1 items-center gap-1 overflow-x-auto px-2 py-1.5">
                <SmartButton
                    icon={Printer}
                    label="Print CV"
                    href={printCvUrl}
                    target="_blank"
                    iconColor="text-primary"
                    iconBg="bg-primary/10"
                />

                <SmartButton
                    icon={Anchor}
                    label="Print Offshore CV"
                    href={printOffshoreCvUrl}
                    target="_blank"
                    iconColor="text-sky-600 dark:text-sky-400"
                    iconBg="bg-sky-500/10"
                />

                <SmartButton
                    icon={ScrollText}
                    label="Print Salary Certificate"
                    href={printSalaryCertificateUrl}
                    target="_blank"
                    iconColor="text-amber-600 dark:text-amber-400"
                    iconBg="bg-amber-500/10"
                />

                {showAttendanceCalendarButton && attendanceCalendarUrl ? (
                    <>
                        <div className="h-5 w-px bg-border/60" />
                        <SmartButton
                            icon={CalendarDays}
                            label="Leave Calendar"
                            href={attendanceCalendarUrl}
                            iconColor="text-violet-600 dark:text-violet-400"
                            iconBg="bg-violet-500/10"
                        />
                    </>
                ) : null}

                {showDocumentsButton && documentsBrowseUrl ? (
                    <>
                        <div className="h-5 w-px bg-border/60" />
                        <SmartButton
                            icon={FileText}
                            label="Documents"
                            stat={
                                documentCount === null || documentCount === undefined
                                    ? null
                                    : documentCount
                            }
                            href={documentsBrowseUrl}
                            iconColor="text-sky-500"
                            iconBg="bg-sky-500/10"
                        />
                    </>
                ) : null}

                {showLinkedUserButton && linkedUser ? (
                    <>
                        <div className="h-5 w-px bg-border/60" />
                        <SmartButton
                            icon={User}
                            label={linkedUser.name?.trim() || 'User account'}
                            href={`/organization/users/${linkedUser.id}`}
                            iconColor="text-emerald-500"
                            iconBg="bg-emerald-500/10"
                        />
                    </>
                ) : null}

                {showCreateUserButton && onCreateUser ? (
                    <>
                        <div className="h-5 w-px bg-border/60" />
                        <SmartButton
                            icon={UserPlus}
                            label="Create User"
                            onClick={onCreateUser}
                            iconColor="text-emerald-500"
                            iconBg="bg-emerald-500/10"
                        />
                    </>
                ) : null}
            </div>

            {/* Right — employee navigation */}
            {employeeNavigation && employeeNavigation.total > 0 ? (
                <InlineNavigation
                    navigation={employeeNavigation}
                    onNavigate={onNavigateEmployee}
                />
            ) : null}
        </div>
    );
}
