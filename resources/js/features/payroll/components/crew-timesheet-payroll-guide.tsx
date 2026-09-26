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
import {
    CREW_TIMESHEET_PAYROLL_GUIDE_SUMMARY,
    CREW_TIMESHEET_PAYROLL_GUIDE_TITLE,
    crewTimesheetPayrollGuideSections,
} from '../lib/crew-timesheet-payroll-guide-content';

export function CrewTimesheetPayrollGuide(): ReactElement {
    const [open, setOpen] = useState(false);
    const sections = crewTimesheetPayrollGuideSections();

    return (
        <section className="rounded-2xl border border-border/60 bg-muted/20 p-4 shadow-sm sm:p-5 dark:border-white/10 dark:bg-white/5">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div className="min-w-0 space-y-1.5">
                    <div className="flex items-center gap-2">
                        <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl border border-border/60 bg-background/80 dark:border-white/10">
                            <Info
                                className="h-4 w-4 text-primary"
                                aria-hidden="true"
                            />
                        </span>
                        <h2 className="text-sm font-semibold tracking-tight text-foreground sm:text-base">
                            {CREW_TIMESHEET_PAYROLL_GUIDE_TITLE}
                        </h2>
                    </div>
                    <p className="text-sm text-muted-foreground">
                        {CREW_TIMESHEET_PAYROLL_GUIDE_SUMMARY}
                    </p>
                </div>

                <Dialog open={open} onOpenChange={setOpen}>
                    <DialogTrigger asChild>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="h-10 shrink-0 rounded-xl"
                            aria-label="View how Crew Timesheet and Payroll works"
                        >
                            View guide
                        </Button>
                    </DialogTrigger>
                    <DialogContent className="flex max-h-[90vh] flex-col gap-0 overflow-hidden p-0 sm:max-w-2xl">
                        <DialogHeader className="border-b border-border/60 px-6 py-4 text-left">
                            <DialogTitle>
                                {CREW_TIMESHEET_PAYROLL_GUIDE_TITLE}
                            </DialogTitle>
                            <DialogDescription>
                                Operational walkthrough for Draft Crew payroll —
                                no salary amounts are shown here.
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
            </div>
        </section>
    );
}
