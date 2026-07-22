import { Link, router } from '@inertiajs/react';
import {
    Anchor,
    CalendarDays,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    FileCheck2,
    FileSignature,
    FileText,
    Printer,
    ScrollText,
    User,
    UserPlus,
} from 'lucide-react';
import type { ComponentType, ReactElement, ReactNode } from 'react';
import { show } from '@/actions/App/Http/Controllers/Organization/EmployeeController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import type { EmployeeNavigation } from '@/pages/organization/employee-page.types';

type ActionLinkProps = {
    icon: ComponentType<{ className?: string }>;
    label: string;
    href?: string;
    onClick?: () => void;
    badge?: string | number | null;
    tone?: string;
};

function ActionLink({
    icon: Icon,
    label,
    href,
    onClick,
    badge,
    tone = 'text-muted-foreground',
}: ActionLinkProps): ReactElement {
    const className = cn(
        'inline-flex h-9 shrink-0 items-center gap-2 rounded-xl px-3 text-sm font-medium text-foreground/90 transition-colors',
        'hover:bg-muted/70 hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
    );

    const content = (
        <>
            <Icon className={cn('size-3.5 shrink-0', tone)} />
            <span className="whitespace-nowrap">{label}</span>
            {badge !== undefined && badge !== null && badge !== '' ? (
                <Badge
                    variant="secondary"
                    className="h-5 min-w-5 rounded-md px-1.5 text-[10px] font-semibold tabular-nums"
                >
                    {badge}
                </Badge>
            ) : null}
        </>
    );

    if (href) {
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

function ActionDivider(): ReactElement {
    return (
        <div
            aria-hidden
            className="mx-0.5 hidden h-6 w-px shrink-0 bg-border/70 sm:block dark:bg-white/10"
        />
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
            show.url(
                { employee: employeeId },
                { query: navigation.list_query },
            ),
            { preserveScroll: true },
        );
    };

    const hasPrev = navigation.previous_id !== null;
    const hasNext = navigation.next_id !== null;

    return (
        <div className="flex shrink-0 items-center gap-0 self-stretch border-l border-border/60 bg-muted/20 px-1.5 dark:border-white/8">
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
                className="flex size-9 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-muted/70 hover:text-foreground disabled:cursor-not-allowed disabled:opacity-30"
            >
                <ChevronLeft className="size-4" />
            </button>

            <span className="min-w-12 px-1 text-center text-xs font-semibold text-foreground/80 tabular-nums">
                {navigation.position}
                <span className="mx-0.5 font-medium text-muted-foreground">
                    /
                </span>
                {navigation.total}
            </span>

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
                className="flex size-9 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-muted/70 hover:text-foreground disabled:cursor-not-allowed disabled:opacity-30"
            >
                <ChevronRight className="size-4" />
            </button>
        </div>
    );
}

function PrintMenu({
    printCvUrl,
    printOffshoreCvUrl,
    printSalaryCertificateUrl,
    printSalaryDeclarationUrl,
}: {
    printCvUrl: string;
    printOffshoreCvUrl: string;
    printSalaryCertificateUrl: string;
    printSalaryDeclarationUrl: string;
}): ReactElement {
    const items: Array<{
        href: string;
        label: string;
        icon: ComponentType<{ className?: string }>;
        tone: string;
    }> = [
        {
            href: printCvUrl,
            label: 'CV',
            icon: Printer,
            tone: 'text-primary',
        },
        {
            href: printOffshoreCvUrl,
            label: 'Offshore CV',
            icon: Anchor,
            tone: 'text-sky-600 dark:text-sky-400',
        },
        {
            href: printSalaryCertificateUrl,
            label: 'Salary certificate',
            icon: ScrollText,
            tone: 'text-amber-600 dark:text-amber-400',
        },
        {
            href: printSalaryDeclarationUrl,
            label: 'Salary declaration',
            icon: FileCheck2,
            tone: 'text-rose-600 dark:text-rose-400',
        },
    ];

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="h-9 gap-2 rounded-xl border-border/70 bg-background/60 px-3 shadow-none dark:border-white/10 dark:bg-white/5"
                >
                    <Printer className="size-3.5 text-primary" />
                    Print
                    <ChevronDown className="size-3.5 text-muted-foreground" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="w-56">
                {items.map((item) => {
                    const Icon = item.icon;

                    return (
                        <DropdownMenuItem key={item.href} asChild>
                            <a
                                href={item.href}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="flex cursor-pointer items-center gap-2"
                            >
                                <Icon className={cn('size-4', item.tone)} />
                                {item.label}
                            </a>
                        </DropdownMenuItem>
                    );
                })}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

export function EmployeeProfileActionBar({
    printCvUrl,
    printOffshoreCvUrl,
    printSalaryCertificateUrl,
    printSalaryDeclarationUrl,
    employeeNavigation,
    onNavigateEmployee,
    showDocumentsButton = false,
    documentCount,
    documentsBrowseUrl,
    showContractsButton = false,
    contractCount,
    contractsBrowseUrl,
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
    printSalaryDeclarationUrl: string;
    employeeNavigation?: EmployeeNavigation | null;
    onNavigateEmployee?: (employeeId: number) => void;
    showDocumentsButton?: boolean;
    documentCount?: number | null;
    documentsBrowseUrl?: string;
    showContractsButton?: boolean;
    contractCount?: number | null;
    contractsBrowseUrl?: string;
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
    const recordLinks: ReactNode[] = [];

    if (showAttendanceCalendarButton && attendanceCalendarUrl) {
        recordLinks.push(
            <ActionLink
                key="leave-calendar"
                icon={CalendarDays}
                label="Leave calendar"
                href={attendanceCalendarUrl}
                tone="text-violet-600 dark:text-violet-400"
            />,
        );
    }

    if (showDocumentsButton && documentsBrowseUrl) {
        recordLinks.push(
            <ActionLink
                key="documents"
                icon={FileText}
                label="Documents"
                href={documentsBrowseUrl}
                badge={documentCount}
                tone="text-sky-600 dark:text-sky-400"
            />,
        );
    }

    if (showContractsButton && contractsBrowseUrl) {
        recordLinks.push(
            <ActionLink
                key="contracts"
                icon={FileSignature}
                label="Contracts"
                href={contractsBrowseUrl}
                badge={contractCount}
                tone="text-indigo-600 dark:text-indigo-400"
            />,
        );
    }

    const accountLinks: ReactNode[] = [];

    if (showLinkedUserButton && linkedUser) {
        accountLinks.push(
            <ActionLink
                key="linked-user"
                icon={User}
                label={linkedUser.name?.trim() || 'User account'}
                href={`/organization/users/${linkedUser.id}`}
                tone="text-emerald-600 dark:text-emerald-400"
            />,
        );
    }

    if (showCreateUserButton && onCreateUser) {
        accountLinks.push(
            <ActionLink
                key="create-user"
                icon={UserPlus}
                label="Create user"
                onClick={onCreateUser}
                tone="text-emerald-600 dark:text-emerald-400"
            />,
        );
    }

    return (
        <div className="flex items-stretch overflow-hidden rounded-2xl border border-border/60 bg-card/80 shadow-sm backdrop-blur-sm dark:border-white/8 dark:bg-white/4">
            <div className="flex min-w-0 flex-1 items-center gap-1 overflow-x-auto px-2.5 py-2 [scrollbar-width:thin]">
                <PrintMenu
                    printCvUrl={printCvUrl}
                    printOffshoreCvUrl={printOffshoreCvUrl}
                    printSalaryCertificateUrl={printSalaryCertificateUrl}
                    printSalaryDeclarationUrl={printSalaryDeclarationUrl}
                />

                {recordLinks.length > 0 ? (
                    <>
                        <ActionDivider />
                        {recordLinks}
                    </>
                ) : null}

                {accountLinks.length > 0 ? (
                    <>
                        <ActionDivider />
                        {accountLinks}
                    </>
                ) : null}
            </div>

            {employeeNavigation && employeeNavigation.total > 0 ? (
                <InlineNavigation
                    navigation={employeeNavigation}
                    onNavigate={onNavigateEmployee}
                />
            ) : null}
        </div>
    );
}
