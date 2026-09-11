import type { ReactElement } from 'react';
import { Badge } from '@/components/ui/badge';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    masterDataIsInUse,
    masterDataUsageTooltip,
} from '@/lib/master-data/usage';
import type { MasterDataUsageFlags } from '@/lib/master-data/usage';
import { cn } from '@/lib/utils';

export function MasterDataInUseBadge({
    item,
    className,
}: {
    item: MasterDataUsageFlags;
    className?: string;
}): ReactElement | null {
    if (!masterDataIsInUse(item)) {
        return null;
    }

    const tooltip = masterDataUsageTooltip(item);
    const badge = (
        <Badge
            variant="warning"
            className={cn(
                'shrink-0 px-1.5 py-0 text-[10px] font-semibold tracking-wide uppercase',
                className,
            )}
        >
            In use
        </Badge>
    );

    if (!tooltip) {
        return badge;
    }

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span className="inline-flex">{badge}</span>
            </TooltipTrigger>
            <TooltipContent>{tooltip}</TooltipContent>
        </Tooltip>
    );
}
