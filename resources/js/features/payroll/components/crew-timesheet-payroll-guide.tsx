import { Info } from 'lucide-react';
import type { ReactElement } from 'react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import {
    CREW_TIMESHEET_PAYROLL_GUIDE_TITLE,
    CREW_TIMESHEET_PAYROLL_GUIDE_TRIGGER_LABEL,
    crewTimesheetPayrollGuideSections,
} from '../lib/crew-timesheet-payroll-guide-content';

export { CREW_TIMESHEET_PAYROLL_GUIDE_TRIGGER_LABEL };

type CrewTimesheetPayrollGuideProps = {
    /**
     * Compact Info + Guide trigger for the Timesheets section header.
     * Kept for an explicit call-site API (`variant="compact"`).
     */
    variant?: 'compact';
    className?: string;
};

/**
 * Lightweight Crew-only Timesheet guide trigger.
 * Opens the shared operational dialog — no salary amounts.
 */
export function CrewTimesheetPayrollGuide({
    className,
}: CrewTimesheetPayrollGuideProps): ReactElement {
    const [open, setOpen] = useState(false);
    const sections = crewTimesheetPayrollGuideSections();

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className={cn(
                        'h-7 shrink-0 gap-1 rounded-lg px-2 text-xs font-medium text-muted-foreground hover:text-foreground',
                        className,
                    )}
                    aria-label="Open how Crew Timesheet and Payroll works guide"
                >
                    <Info className="size-3.5" aria-hidden="true" />
                    {CREW_TIMESHEET_PAYROLL_GUIDE_TRIGGER_LABEL}
                </Button>
            </DialogTrigger>
            <DialogContent className="flex max-h-[90vh] flex-col gap-0 overflow-hidden p-0 sm:max-w-2xl">
                <DialogHeader className="border-b border-border/60 px-6 py-4 text-left">
                    <DialogTitle>
                        {CREW_TIMESHEET_PAYROLL_GUIDE_TITLE}
                    </DialogTitle>
                    <DialogDescription>
                        Operational walkthrough for Draft Crew payroll — no
                        salary amounts are shown here.
                    </DialogDescription>
                </DialogHeader>
                <div className="min-h-0 space-y-3 overflow-y-auto px-6 py-4">
                    {sections.map((section) => (
                        <article
                            key={section.title}
                            className="rounded-xl border border-border/70 bg-card/80 p-3"
                        >
                            <h3 className="text-sm font-semibold text-foreground">
                                {section.title}
                            </h3>
                            <p className="mt-1.5 text-sm text-muted-foreground">
                                {section.body}
                            </p>
                            {section.bullets ? (
                                <ul className="mt-2 list-disc space-y-1 pl-4 text-xs text-muted-foreground">
                                    {section.bullets.map((bullet) => (
                                        <li key={bullet}>{bullet}</li>
                                    ))}
                                </ul>
                            ) : null}
                            {section.callout ? (
                                <p className="mt-2 rounded-md border border-sky-500/25 bg-sky-500/5 px-2.5 py-2 text-[11px] text-sky-950 dark:text-sky-100">
                                    {section.callout}
                                </p>
                            ) : null}
                        </article>
                    ))}
                </div>
            </DialogContent>
        </Dialog>
    );
}
