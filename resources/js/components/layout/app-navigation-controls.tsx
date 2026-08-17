import { ArrowLeft, ArrowRight } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useIsStandalonePwa } from '@/hooks/use-is-standalone-pwa';

function goBack(): void {
    if (typeof window === 'undefined') {
        return;
    }

    // Fresh installed-PWA launches typically have a single history entry.
    // Skip back so we do not leave the app. After in-app navigation,
    // history.length is no longer a reliable availability signal.
    if (window.history.length <= 1) {
        return;
    }

    try {
        window.history.back();
    } catch {
        return;
    }
}

function goForward(): void {
    if (typeof window === 'undefined') {
        return;
    }

    // Browsers do not expose a reliable can-go-forward signal without
    // custom history bookkeeping. Keep the control usable; native
    // history.forward() is a no-op when there is no forward entry.
    try {
        window.history.forward();
    } catch {
        return;
    }
}

export function AppNavigationControls() {
    const isStandalonePwa = useIsStandalonePwa();

    if (!isStandalonePwa) {
        return null;
    }

    return (
        <div className="flex shrink-0 items-center gap-0.5 sm:gap-1">
            <Tooltip>
                <TooltipTrigger asChild>
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        className="h-7 w-7"
                        aria-label="Go back"
                        onClick={goBack}
                    >
                        <ArrowLeft aria-hidden="true" />
                    </Button>
                </TooltipTrigger>
                <TooltipContent side="bottom">Go back</TooltipContent>
            </Tooltip>
            <Tooltip>
                <TooltipTrigger asChild>
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        className="h-7 w-7"
                        aria-label="Go forward"
                        onClick={goForward}
                    >
                        <ArrowRight aria-hidden="true" />
                    </Button>
                </TooltipTrigger>
                <TooltipContent side="bottom">Go forward</TooltipContent>
            </Tooltip>
        </div>
    );
}
