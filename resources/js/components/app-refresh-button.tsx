import { RefreshCw } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { appRefreshController } from '@/lib/app-refresh/app-refresh-controller';
import type { AppRefreshSnapshot } from '@/lib/app-refresh/types';
import { cn } from '@/lib/utils';

export function AppRefreshButton() {
    const [snapshot, setSnapshot] = useState<AppRefreshSnapshot | null>(null);

    useEffect(() => appRefreshController.subscribe(setSnapshot), []);

    const refreshing = snapshot?.refreshing ?? false;
    const updateAvailable = snapshot?.updateAvailable ?? false;
    const updateDismissed = snapshot?.updateDismissed ?? false;

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <Button
                    type="button"
                    size="icon"
                    variant="ghost"
                    aria-label={
                        updateAvailable
                            ? updateDismissed
                                ? 'Update available. Refresh App to install.'
                                : 'Update available. Refresh App'
                            : 'Refresh App'
                    }
                    disabled={refreshing}
                    className="relative rounded-full"
                    onClick={() => {
                        void appRefreshController.manualRefresh();
                    }}
                >
                    <RefreshCw
                        aria-hidden="true"
                        className={cn(refreshing && 'animate-spin')}
                    />
                    {updateAvailable ? (
                        <span
                            aria-hidden="true"
                            className="absolute top-1.5 right-1.5 size-2 rounded-full bg-amber-500"
                        />
                    ) : null}
                </Button>
            </TooltipTrigger>
            <TooltipContent>
                {updateAvailable
                    ? updateDismissed
                        ? 'Update available — click to review'
                        : 'Update available — Refresh App'
                    : 'Refresh App'}
            </TooltipContent>
        </Tooltip>
    );
}
