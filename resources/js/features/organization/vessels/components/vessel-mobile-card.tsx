import { MobileRecordCard } from '@/components/mobile-record-list';
import type { MobileRecordOverflowAction } from '@/components/mobile-record-list';
import { Badge } from '@/components/ui/badge';
import { vesselMobileCardModel } from '@/features/organization/vessels/lib/vessel-mobile-card';
import { cn } from '@/lib/utils';
import type { VesselPageCan, VesselRow } from '../types';

export function VesselMobileCard({
    vessel,
    showUrl,
    can,
    onEdit,
    onDelete,
}: {
    vessel: VesselRow;
    showUrl: string;
    can: Pick<VesselPageCan, 'update' | 'delete'>;
    onEdit?: (vessel: VesselRow) => void;
    onDelete?: (vessel: VesselRow) => void;
}) {
    const model = vesselMobileCardModel(vessel, can);
    const overflowActions: MobileRecordOverflowAction[] = [];

    if (model.showEdit && onEdit) {
        overflowActions.push({
            key: 'edit',
            label: 'Edit',
            onSelect: () => onEdit(vessel),
        });
    }

    if (model.showDelete && onDelete) {
        overflowActions.push({
            key: 'delete',
            label: 'Delete',
            destructive: true,
            onSelect: () => onDelete(vessel),
        });
    }

    return (
        <MobileRecordCard
            title={model.title}
            subtitle={model.subtitle}
            meta={[model.typeLine, model.identificationLine, model.manningLine]}
            status={
                <Badge
                    variant="outline"
                    className={cn(
                        'text-[10px] font-bold tracking-wider uppercase',
                        model.isActive
                            ? 'border-emerald-500/20 bg-emerald-500/15 text-emerald-700 dark:text-emerald-400'
                            : 'border-border/80 bg-muted/60 text-muted-foreground',
                    )}
                >
                    {model.statusLabel}
                </Badge>
            }
            attention={model.attention}
            href={showUrl}
            overflowActions={overflowActions}
        />
    );
}
