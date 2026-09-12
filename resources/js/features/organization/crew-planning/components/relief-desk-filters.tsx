import { Filter } from 'lucide-react';
import { useRef, useState } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { FiltersSheet } from '@/components/filters-sheet';
import { SearchBar } from '@/components/search-bar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import type {
    PlanningOption,
    ReliefDeskFilterOptions,
    ReliefDeskFilters,
} from '@/features/organization/crew-planning/types';

export function ReliefDeskFiltersBar({
    searchInput,
    onSearchChange,
    filters,
    vessels,
    ranks,
    filterOptions,
    onFilterChange,
    onReset,
}: {
    searchInput: string;
    onSearchChange: (value: string) => void;
    filters: ReliefDeskFilters;
    vessels: PlanningOption[];
    ranks: PlanningOption[];
    filterOptions: ReliefDeskFilterOptions;
    onFilterChange: (next: Partial<ReliefDeskFilters>) => void;
    onReset: () => void;
}) {
    const [open, setOpen] = useState(false);
    const [draft, setDraft] = useState(filters);
    const applyDraftOnClose = useRef(true);

    const snapshotVessels = filterOptions.vessels ?? [];
    const vesselsForClient = (
        snapshotVessels.length > 0 ? snapshotVessels : vessels
    ).filter((vessel) => {
        if (filters.client_id == null) {
            return true;
        }

        if ('client_ids' in vessel && Array.isArray(vessel.client_ids)) {
            return vessel.client_ids.includes(filters.client_id);
        }

        return true;
    });

    return (
        <>
            <SearchBar
                className="mb-4"
                value={searchInput}
                onChange={onSearchChange}
                placeholder="Search crew, assignment, vessel, or relief"
                right={
                    <>
                        <AppSelect
                            value={
                                filters.vessel_id != null
                                    ? String(filters.vessel_id)
                                    : ''
                            }
                            onValueChange={(value) =>
                                onFilterChange({
                                    vessel_id:
                                        value === '' ? null : Number(value),
                                })
                            }
                            variant="dark"
                            placeholder="All vessels"
                            className="w-[160px]"
                        >
                            <AppSelectItem value="">All vessels</AppSelectItem>
                            {vesselsForClient.map((vessel) => (
                                <AppSelectItem
                                    key={vessel.id}
                                    value={String(vessel.id)}
                                >
                                    {vessel.name}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
                        <AppSelect
                            value={
                                filters.rank_id != null
                                    ? String(filters.rank_id)
                                    : ''
                            }
                            onValueChange={(value) =>
                                onFilterChange({
                                    rank_id:
                                        value === '' ? null : Number(value),
                                })
                            }
                            variant="dark"
                            placeholder="All ranks"
                            className="w-[140px]"
                        >
                            <AppSelectItem value="">All ranks</AppSelectItem>
                            {ranks.map((rank) => (
                                <AppSelectItem
                                    key={rank.id}
                                    value={String(rank.id)}
                                >
                                    {rank.name}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
                        <Button
                            type="button"
                            variant="outline"
                            className="h-11"
                            onClick={() => {
                                setDraft(filters);
                                setOpen(true);
                            }}
                        >
                            <Filter className="h-4 w-4" />
                            Filters
                        </Button>
                    </>
                }
            />

            <FiltersSheet
                open={open}
                onOpenChange={(next) => {
                    if (!next && applyDraftOnClose.current) {
                        onFilterChange(draft);
                    }

                    applyDraftOnClose.current = true;
                    setOpen(next);
                }}
                onReset={() => {
                    applyDraftOnClose.current = false;
                    onReset();
                    setOpen(false);
                }}
                applyText="Apply filters"
            >
                <div className="space-y-2">
                    <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                        Client
                    </Label>
                    <AppSelect
                        value={
                            draft.client_id != null
                                ? String(draft.client_id)
                                : ''
                        }
                        onValueChange={(value) =>
                            setDraft({
                                ...draft,
                                client_id: value === '' ? null : Number(value),
                                vessel_id:
                                    value === ''
                                        ? draft.vessel_id
                                        : (() => {
                                              const nextClientId =
                                                  Number(value);
                                              const selected = (
                                                  filterOptions.vessels ?? []
                                              ).find(
                                                  (vessel) =>
                                                      vessel.id ===
                                                      draft.vessel_id,
                                              );

                                              if (
                                                  selected == null ||
                                                  !Array.isArray(
                                                      selected.client_ids,
                                                  ) ||
                                                  selected.client_ids.includes(
                                                      nextClientId,
                                                  )
                                              ) {
                                                  return draft.vessel_id;
                                              }

                                              return null;
                                          })(),
                            })
                        }
                        variant="dark"
                        placeholder="All clients"
                    >
                        <AppSelectItem value="">All clients</AppSelectItem>
                        {filterOptions.clients.map((client) => (
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
                    <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                        Relief status
                    </Label>
                    <AppSelect
                        value={draft.relief_status}
                        onValueChange={(relief_status) =>
                            setDraft({ ...draft, relief_status })
                        }
                        variant="dark"
                        placeholder="All statuses"
                    >
                        <AppSelectItem value="">All statuses</AppSelectItem>
                        {filterOptions.relief_statuses.map((option) => (
                            <AppSelectItem
                                key={option.value}
                                value={option.value}
                            >
                                {option.label}
                            </AppSelectItem>
                        ))}
                    </AppSelect>
                </div>

                <div className="space-y-2">
                    <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                        Relief risk
                    </Label>
                    <AppSelect
                        value={draft.relief_risk}
                        onValueChange={(relief_risk) =>
                            setDraft({ ...draft, relief_risk })
                        }
                        variant="dark"
                        placeholder="All risks"
                    >
                        <AppSelectItem value="">All risks</AppSelectItem>
                        {filterOptions.relief_risks.map((option) => (
                            <AppSelectItem
                                key={option.value}
                                value={option.value}
                            >
                                {option.label}
                            </AppSelectItem>
                        ))}
                    </AppSelect>
                </div>

                <div className="grid grid-cols-2 gap-3">
                    <div className="space-y-2">
                        <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                            Sign-off from
                        </Label>
                        <Input
                            type="date"
                            value={draft.planned_signoff_from}
                            onChange={(event) =>
                                setDraft({
                                    ...draft,
                                    planned_signoff_from: event.target.value,
                                })
                            }
                        />
                    </div>
                    <div className="space-y-2">
                        <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                            Sign-off to
                        </Label>
                        <Input
                            type="date"
                            value={draft.planned_signoff_to}
                            onChange={(event) =>
                                setDraft({
                                    ...draft,
                                    planned_signoff_to: event.target.value,
                                })
                            }
                        />
                    </div>
                </div>

                <div className="flex items-center justify-between gap-3 rounded-xl border border-border/60 px-3 py-2">
                    <div>
                        <p className="text-sm font-medium">Show all onboard</p>
                        <p className="text-xs text-muted-foreground">
                            Include Planned Sign-Offs beyond 30 days
                        </p>
                    </div>
                    <Switch
                        checked={draft.horizon === 'all'}
                        onCheckedChange={(checked) =>
                            setDraft({
                                ...draft,
                                horizon: checked ? 'all' : '30',
                            })
                        }
                    />
                </div>
            </FiltersSheet>
        </>
    );
}
