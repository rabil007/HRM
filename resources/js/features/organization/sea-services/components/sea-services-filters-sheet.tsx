import { AppSelect, AppSelectItem } from '@/components/app-select';
import { FiltersSheet } from '@/components/filters-sheet';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { RankOption } from '@/features/organization/employees/types';
import type {
    ClientOption,
    VesselOption,
    VesselTypeOption,
} from '@/pages/organization/employee-page.types';

export type SeaServiceSheetFilters = {
    vessel_id: string;
    vessel_type_id: string;
    position_id: string;
    client_id: string;
    start_date: string;
    end_date: string;
};

export function SeaServicesFiltersSheet({
    open,
    onOpenChange,
    vesselTypes,
    vessels,
    positions,
    clients,
    value,
    onChange,
    onReset,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    vesselTypes: VesselTypeOption[];
    vessels: VesselOption[];
    positions: RankOption[];
    clients: ClientOption[];
    value: SeaServiceSheetFilters;
    onChange: (next: SeaServiceSheetFilters) => void;
    onReset: () => void;
}) {
    const filteredVessels =
        value.vessel_type_id === ''
            ? vessels
            : vessels.filter(
                  (vessel) =>
                      String(vessel.vessel_type_id) === value.vessel_type_id,
              );

    return (
        <FiltersSheet open={open} onOpenChange={onOpenChange} onReset={onReset}>
            <div className="space-y-2">
                <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                    Vessel type
                </Label>
                <AppSelect
                    value={value.vessel_type_id}
                    onValueChange={(vesselTypeId) =>
                        onChange({
                            ...value,
                            vessel_type_id: vesselTypeId,
                            vessel_id: '',
                        })
                    }
                    variant="dark"
                    placeholder="All vessel types"
                    searchPlaceholder="Search vessel type..."
                >
                    <AppSelectItem value="">All vessel types</AppSelectItem>
                    {vesselTypes.map((vesselType) => (
                        <AppSelectItem
                            key={vesselType.id}
                            value={String(vesselType.id)}
                        >
                            {vesselType.name}
                        </AppSelectItem>
                    ))}
                </AppSelect>
            </div>

            <div className="space-y-2">
                <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                    Vessel
                </Label>
                <AppSelect
                    value={value.vessel_id}
                    onValueChange={(vesselId) =>
                        onChange({ ...value, vessel_id: vesselId })
                    }
                    variant="dark"
                    placeholder="All vessels"
                    searchPlaceholder="Search vessel..."
                >
                    <AppSelectItem value="">All vessels</AppSelectItem>
                    {filteredVessels.map((vessel) => (
                        <AppSelectItem
                            key={vessel.id}
                            value={String(vessel.id)}
                        >
                            {vessel.name}
                        </AppSelectItem>
                    ))}
                </AppSelect>
            </div>

            <div className="space-y-2">
                <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                    Position
                </Label>
                <AppSelect
                    value={value.position_id}
                    onValueChange={(positionId) =>
                        onChange({ ...value, position_id: positionId })
                    }
                    variant="dark"
                    placeholder="All positions"
                    searchPlaceholder="Search position..."
                >
                    <AppSelectItem value="">All positions</AppSelectItem>
                    {positions.map((position) => (
                        <AppSelectItem
                            key={position.id}
                            value={String(position.id)}
                        >
                            {position.name}
                        </AppSelectItem>
                    ))}
                </AppSelect>
            </div>

            <div className="space-y-2">
                <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                    Client
                </Label>
                <AppSelect
                    value={value.client_id}
                    onValueChange={(clientId) =>
                        onChange({ ...value, client_id: clientId })
                    }
                    variant="dark"
                    placeholder="All clients"
                    searchPlaceholder="Search client..."
                >
                    <AppSelectItem value="">All clients</AppSelectItem>
                    {clients.map((client) => (
                        <AppSelectItem
                            key={client.id}
                            value={String(client.id)}
                        >
                            {client.name}
                        </AppSelectItem>
                    ))}
                </AppSelect>
            </div>

            <div className="space-y-2">
                <Label
                    htmlFor="filter-start-date"
                    className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                >
                    Start date from
                </Label>
                <Input
                    id="filter-start-date"
                    type="date"
                    className="h-11 rounded-xl border-white/10 bg-white/5 transition-all focus-visible:ring-primary/40"
                    value={value.start_date}
                    onChange={(event) =>
                        onChange({ ...value, start_date: event.target.value })
                    }
                />
            </div>

            <div className="space-y-2">
                <Label
                    htmlFor="filter-end-date"
                    className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                >
                    End date to
                </Label>
                <Input
                    id="filter-end-date"
                    type="date"
                    className="h-11 rounded-xl border-white/10 bg-white/5 transition-all focus-visible:ring-primary/40"
                    value={value.end_date}
                    onChange={(event) =>
                        onChange({ ...value, end_date: event.target.value })
                    }
                />
            </div>
        </FiltersSheet>
    );
}
