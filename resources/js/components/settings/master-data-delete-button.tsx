import type { ReactElement } from 'react';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    masterDataCanDelete,
    masterDataUsageTooltip,
} from '@/lib/master-data/usage';
import type { MasterDataUsageFlags } from '@/lib/master-data/usage';

export function MasterDataDeleteButton({
    item,
    hasDeletePermission,
    onDelete,
}: {
    item: MasterDataUsageFlags;
    hasDeletePermission: boolean;
    onDelete: () => void;
}): ReactElement | null {
    if (!hasDeletePermission) {
        return null;
    }

    const canDelete = masterDataCanDelete(item, hasDeletePermission);

    if (canDelete) {
        return (
            <Button variant="destructive" size="sm" onClick={onDelete}>
                Delete
            </Button>
        );
    }

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span className="inline-flex">
                    <Button variant="destructive" size="sm" disabled>
                        Delete
                    </Button>
                </span>
            </TooltipTrigger>
            <TooltipContent>
                {masterDataUsageTooltip(item) ??
                    'This record is currently in use. Delete is unavailable.'}
            </TooltipContent>
        </Tooltip>
    );
}
