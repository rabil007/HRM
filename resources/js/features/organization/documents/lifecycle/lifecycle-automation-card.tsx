import { router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { retry } from '@/routes/organization/documents/lifecycle-automation';
import type { DocumentLifecycleAutomationSummary } from './types';

type Props = {
    automation: DocumentLifecycleAutomationSummary;
    documentId: number;
    canRetry: boolean;
};

export function LifecycleAutomationCard({
    automation,
    documentId,
    canRetry,
}: Props) {
    return (
        <Card className="border-border/80 dark:border-white/10">
            <CardHeader className="pb-3">
                <CardTitle className="text-base">
                    Lifecycle automation
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-4 pt-0 text-sm">
                <div className="grid gap-2 sm:grid-cols-2">
                    <div>
                        <p className="text-muted-foreground">Status</p>
                        <p className="font-medium">{automation.status_label}</p>
                    </div>
                    <div>
                        <p className="text-muted-foreground">Stage</p>
                        <p className="font-medium">
                            {automation.stage_label ?? '—'}
                        </p>
                    </div>
                    <div className="sm:col-span-2">
                        <p className="text-muted-foreground">Behavior</p>
                        <p className="font-medium">
                            {automation.behavior_summary}
                        </p>
                    </div>
                </div>

                {automation.blocked_message ? (
                    <p className="rounded-md bg-destructive/10 px-3 py-2 text-destructive">
                        {automation.blocked_message}
                    </p>
                ) : null}

                {canRetry && automation.can_retry ? (
                    <Button
                        type="button"
                        size="sm"
                        onClick={() =>
                            router.post(
                                retry.url(documentId),
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        Retry
                    </Button>
                ) : null}
            </CardContent>
        </Card>
    );
}
