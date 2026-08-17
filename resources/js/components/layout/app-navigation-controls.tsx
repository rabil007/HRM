import { ArrowLeft, ArrowRight } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useIsStandalonePwa } from '@/hooks/use-is-standalone-pwa';

function goBack(): void {
    if (typeof window === 'undefined' || window.history.length <= 1) {
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
        <div className="flex shrink-0 items-center gap-1">
            <Tooltip>
                <TooltipTrigger asChild>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-7"
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
                        variant="ghost"
                        size="icon"
                        className="size-7"
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
