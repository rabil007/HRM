import { FileText, Printer } from 'lucide-react';
import type { ComponentType, ReactElement } from 'react';
import { EmployeeProfileNavigation } from '@/components/employee-profile-navigation';
import { cn } from '@/lib/utils';
import type {
    EmployeeNavigation,
    EmployeeTab,
} from '@/pages/organization/employee-page.types';

type SmartButtonProps = {
    icon: ComponentType<{ className?: string }>;
    label: string;
    stat?: string | number | null;
    onClick?: () => void;
    href?: string;
    target?: string;
    active?: boolean;
};

function SmartButton({
    icon: Icon,
    label,
    stat,
    onClick,
    href,
    target,
    active = false,
}: SmartButtonProps): ReactElement {
    const hasStat = stat !== undefined && stat !== null && stat !== '';

    const className = cn(
        'flex min-h-13 min-w-34 items-center gap-3 px-4 py-2 transition-colors',
        'hover:bg-muted/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset',
        active && 'bg-primary/10',
    );

    const content = (
        <>
            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-border/60 bg-muted/25">
                <Icon className="h-4 w-4 text-muted-foreground" />
            </span>
            {hasStat ? (
                <span className="flex min-w-0 flex-col items-start gap-0.5 leading-none">
                    <span className="text-[11px] font-medium text-muted-foreground">{label}</span>
                    <span className="text-lg font-semibold tabular-nums tracking-tight text-primary">
                        {stat}
                    </span>
                </span>
            ) : (
                <span className="text-sm font-semibold text-foreground">{label}</span>
            )}
        </>
    );

    if (href) {
        return (
            <a
                href={href}
                target={target}
                rel={target === '_blank' ? 'noopener noreferrer' : undefined}
                className={className}
            >
                {content}
            </a>
        );
    }

    return (
        <button type="button" onClick={onClick} className={className}>
            {content}
        </button>
    );
}

export function EmployeeProfileActionBar({
    printCvUrl,
    employeeNavigation,
    onNavigateEmployee,
    showDocumentsButton = false,
    documentCount,
    activeTab,
    onDocumentsSelect,
}: {
    printCvUrl: string;
    employeeNavigation?: EmployeeNavigation | null;
    onNavigateEmployee?: (employeeId: number) => void;
    showDocumentsButton?: boolean;
    documentCount?: number | null;
    activeTab?: EmployeeTab;
    onDocumentsSelect?: () => void;
}): ReactElement {
    return (
        <div className="overflow-hidden rounded-xl border border-border/80 bg-card/70 shadow-sm">
            <div className="flex h-13 min-h-13 min-w-0 items-center divide-x divide-border/80">
                <div className="flex min-w-0 flex-1 overflow-x-auto">
                    <SmartButton
                        icon={Printer}
                        label="Print CV"
                        href={printCvUrl}
                        target="_blank"
                    />
                    {showDocumentsButton ? (
                        <SmartButton
                            icon={FileText}
                            label="Documents"
                            stat={
                                documentCount === null || documentCount === undefined
                                    ? null
                                    : documentCount
                            }
                            active={activeTab === 'documents'}
                            onClick={onDocumentsSelect}
                        />
                    ) : null}
                </div>

                {employeeNavigation && employeeNavigation.total > 0 ? (
                    <EmployeeProfileNavigation
                        embedded
                        navigation={employeeNavigation}
                        onNavigate={onNavigateEmployee}
                    />
                ) : null}
            </div>
        </div>
    );
}
