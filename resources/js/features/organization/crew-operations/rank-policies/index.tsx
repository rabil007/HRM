import { router, useForm } from '@inertiajs/react';
import { Pencil, ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDeleteDialog } from '@/components/confirm-delete-dialog';
import {
    OrganizationDataTable,
    DataTableHead,
    DataTableHeaderRow,
    dataTableBodyRowClass,
    dataTableCellClass,
    dataTableCellPrimaryClass,
} from '@/components/data-table';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    TableBody,
    TableCell,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { tourSourceLabel } from '@/features/organization/crew/lib/tour-of-duty';
import { RankPolicyFormSheet } from '@/features/organization/crew-operations/rank-policies/components/rank-policy-form-sheet';
import type {
    CrewRankPolicyFormData,
    CrewRankPolicyItem,
    CrewRankPolicyPagePermissions,
} from '@/features/organization/crew-operations/rank-policies/types';
import {
    destroy as destroyRankPolicy,
    upsert as upsertRankPolicy,
} from '@/routes/organization/crew-operations/rank-policies';

export function CrewRankPoliciesContent({
    policies,
    can,
}: {
    policies: CrewRankPolicyItem[];
    can: CrewRankPolicyPagePermissions;
}) {
    const [editingPolicy, setEditingPolicy] =
        useState<CrewRankPolicyItem | null>(null);
    const [clearPolicy, setClearPolicy] = useState<CrewRankPolicyItem | null>(
        null,
    );

    const form = useForm<CrewRankPolicyFormData>({
        rank_id: null,
        tour_of_duty_days: '',
    });

    const openEdit = (policy: CrewRankPolicyItem): void => {
        setEditingPolicy(policy);
        form.clearErrors();
        form.setData({
            rank_id: policy.rank_id,
            tour_of_duty_days:
                policy.company_tour_of_duty_days ??
                policy.resolved_tour_of_duty_days ??
                policy.global_tour_of_duty_days ??
                '',
        });
    };

    const closeSheet = (): void => {
        setEditingPolicy(null);
        form.reset();
        form.clearErrors();
    };

    const submitOverride = (): void => {
        if (!editingPolicy) {
            return;
        }

        form.put(upsertRankPolicy.url(), {
            preserveScroll: true,
            onSuccess: () => closeSheet(),
        });
    };

    const confirmClearOverride = (): void => {
        if (!clearPolicy?.policy_id) {
            return;
        }

        router.delete(destroyRankPolicy.url(clearPolicy.policy_id), {
            preserveScroll: true,
            onSuccess: () => setClearPolicy(null),
        });
    };

    return (
        <Main>
            <PageHeader
                kicker="Crew Operations"
                title="Rank Tour Policies"
                description="Set company-level Tour of Duty overrides per rank. Global defaults come from Settings → Master Data → Ranks."
            />

            <OrganizationDataTable minWidth="min-w-[920px]">
                <TableHeader>
                    <DataTableHeaderRow>
                        <DataTableHead>Rank</DataTableHead>
                        <DataTableHead>Global default</DataTableHead>
                        <DataTableHead>Company override</DataTableHead>
                        <DataTableHead>Resolved</DataTableHead>
                        <DataTableHead>Source</DataTableHead>
                        {can.update ? (
                            <DataTableHead className="text-right">
                                Actions
                            </DataTableHead>
                        ) : null}
                    </DataTableHeaderRow>
                </TableHeader>
                <TableBody>
                    {policies.length === 0 ? (
                        <TableRow>
                            <TableCell
                                colSpan={can.update ? 6 : 5}
                                className="py-10 text-center text-sm text-muted-foreground"
                            >
                                No active ranks found.
                            </TableCell>
                        </TableRow>
                    ) : (
                        policies.map((policy) => (
                            <TableRow
                                key={policy.rank_id}
                                className={dataTableBodyRowClass(false)}
                            >
                                <TableCell
                                    className={dataTableCellPrimaryClass()}
                                >
                                    <div className="flex items-center gap-2">
                                        <ShieldCheck
                                            className="size-4 text-muted-foreground"
                                            aria-hidden
                                        />
                                        <span className="font-semibold">
                                            {policy.rank_name}
                                        </span>
                                    </div>
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    {policy.global_tour_of_duty_days != null
                                        ? `${policy.global_tour_of_duty_days} days`
                                        : '—'}
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    {policy.company_tour_of_duty_days !=
                                    null ? (
                                        <Badge variant="secondary">
                                            {policy.company_tour_of_duty_days}{' '}
                                            days
                                        </Badge>
                                    ) : (
                                        <span className="text-muted-foreground">
                                            —
                                        </span>
                                    )}
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    {policy.resolved_tour_of_duty_days != null
                                        ? `${policy.resolved_tour_of_duty_days} days`
                                        : '—'}
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    {tourSourceLabel(
                                        policy.resolved_tour_of_duty_source,
                                    )}
                                </TableCell>
                                {can.update ? (
                                    <TableCell className={dataTableCellClass()}>
                                        <div className="flex justify-end gap-2">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={() => openEdit(policy)}
                                            >
                                                <Pencil className="mr-1.5 size-3.5" />
                                                {policy.company_tour_of_duty_days !=
                                                null
                                                    ? 'Edit'
                                                    : 'Set override'}
                                            </Button>
                                            {policy.policy_id != null ? (
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    className="text-muted-foreground"
                                                    onClick={() =>
                                                        setClearPolicy(policy)
                                                    }
                                                >
                                                    Clear
                                                </Button>
                                            ) : null}
                                        </div>
                                    </TableCell>
                                ) : null}
                            </TableRow>
                        ))
                    )}
                </TableBody>
            </OrganizationDataTable>

            {can.update ? (
                <RankPolicyFormSheet
                    open={editingPolicy != null}
                    onOpenChange={(open) => {
                        if (!open) {
                            closeSheet();
                        }
                    }}
                    policy={editingPolicy}
                    form={form}
                    onSubmit={submitOverride}
                />
            ) : null}

            <ConfirmDeleteDialog
                open={clearPolicy != null}
                onOpenChange={(open) => {
                    if (!open) {
                        setClearPolicy(null);
                    }
                }}
                title="Clear company override?"
                description={
                    clearPolicy
                        ? `Remove the company Tour of Duty override for ${clearPolicy.rank_name}. The global rank default will apply.`
                        : undefined
                }
                cancelText="Cancel"
                confirmText="Clear override"
                onConfirm={confirmClearOverride}
            />
        </Main>
    );
}
