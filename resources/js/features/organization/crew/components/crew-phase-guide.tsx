import { Info } from 'lucide-react';
import type { ReactElement } from 'react';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { crewPhaseGuideItems } from '@/features/organization/crew/lib/crew-phase-descriptions';

export function CrewPhaseGuide(): ReactElement {
    const items = crewPhaseGuideItems();

    return (
        <Popover>
            <PopoverTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="h-7 gap-1 px-2 text-xs font-medium text-muted-foreground"
                    aria-label="Open phase guide"
                >
                    <Info className="size-3.5" aria-hidden />
                    Phase guide
                </Button>
            </PopoverTrigger>
            <PopoverContent
                align="end"
                side="bottom"
                className="w-80 space-y-3 p-3"
            >
                <div>
                    <p className="text-sm font-semibold text-foreground">
                        Phase guide
                    </p>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        Standby means waiting between movements, typically in a
                        hotel or other accommodation.
                    </p>
                </div>
                <ul className="space-y-1.5">
                    {items.map((item) => (
                        <li
                            key={item.code}
                            className="flex gap-2 text-xs leading-5"
                        >
                            <span className="w-8 shrink-0 font-semibold text-foreground uppercase">
                                {item.code}
                            </span>
                            <span className="min-w-0 text-muted-foreground">
                                {item.compact}
                            </span>
                        </li>
                    ))}
                </ul>
            </PopoverContent>
        </Popover>
    );
}
