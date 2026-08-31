import { Head, Link, router, useForm } from '@inertiajs/react';
import { Check, MoreHorizontal, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { ReactElement, ReactNode } from 'react';
import {
    destroy as destroyDocumentType,
    update as updateDocumentType,
} from '@/actions/App/Http/Controllers/Settings/MasterData/DocumentTypeController';
import { ConfirmDeleteDialog } from '@/components/confirm-delete-dialog';
import { DetailsHeader } from '@/components/details-header';
import { Main } from '@/components/layout/main';
import type { RecentActivityItem } from '@/components/recent-activity-card';
import { RecentActivityCard } from '@/components/recent-activity-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { DocumentTypeFormSheet } from '@/features/organization/documents/configuration/document-type-form-sheet';
import {
    documentTypeToRow,
    requirementToFormData,
} from '@/features/organization/documents/configuration/types';
import type {
    DepartmentOption,
    DocumentTypeDetail,
    PositionOption,
    ProjectOption,
    RankOption,
} from '@/features/organization/documents/configuration/types';
import { DocumentsBreadcrumbs } from '@/features/organization/documents/documents-breadcrumbs';
import { documents as documentsOverview } from '@/routes/organization';
import { configuration as documentsConfiguration } from '@/routes/organization/documents';

function OverviewField({
    label,
    value,
}: {
    label: string;
    value: ReactNode;
}): ReactElement {
    return (
        <div className="flex items-start justify-between gap-4 border-b border-border/50 px-1 py-3 last:border-b-0">
            <span className="text-[10px] font-bold tracking-[0.18em] text-muted-foreground/70 uppercase">
                {label}
            </span>
            <span className="max-w-[65%] text-right text-sm font-medium">
                {value}
            </span>
        </div>
    );
}

function TargetGroup({
    label,
    names,
}: {
    label: string;
    names: string[];
}): ReactElement | null {
    if (names.length === 0) {
        return null;
    }

    return (
        <div className="space-y-2">
            <div className="flex flex-wrap items-baseline gap-2">
                <h3 className="text-sm font-semibold text-foreground">
                    {label}
                </h3>
                <span className="text-xs text-muted-foreground">
                    {names.length} selected
                </span>
            </div>
            <ul className="flex flex-col gap-1.5 sm:flex-row sm:flex-wrap">
                {names.map((name) => (
                    <li key={`${label}-${name}`}>
                        <Badge variant="secondary" className="font-normal">
                            {name}
                        </Badge>
                    </li>
                ))}
            </ul>
        </div>
    );
}

export function DocumentTypeShowContent({
    documentType,
    can,
    departments = [],
    positions = [],
    ranks = [],
    projects = [],
    recentActivity,
    canViewAudit,
}: {
    documentType: DocumentTypeDetail;
    can: { update: boolean; delete: boolean };
    departments?: DepartmentOption[];
    positions?: PositionOption[];
    ranks?: RankOption[];
    projects?: ProjectOption[];
    recentActivity: RecentActivityItem[];
    canViewAudit: boolean;
}): ReactElement {
    const requirement = documentType.requirement;
    const listHref = documentsConfiguration.url();
    const [editOpen, setEditOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);

    const row = documentTypeToRow(documentType);
    const form = useForm(requirementToFormData(row, { redirectToShow: true }));

    const openEdit = () => {
        form.reset();
        form.clearErrors();
        form.setData(requirementToFormData(row, { redirectToShow: true }));
        setEditOpen(true);
    };

    const submit = () => {
        form.put(updateDocumentType.url(documentType.id), {
            preserveScroll: true,
            onSuccess: () => setEditOpen(false),
        });
    };

    const confirmDelete = () => {
        router.delete(destroyDocumentType.url(documentType.id), {
            onFinish: () => setDeleteOpen(false),
        });
    };

    const departmentNames = requirement.targets.departments.map(
        (item) => item.name,
    );
    const positionNames = requirement.targets.positions.map(
        (item) => item.title,
    );
    const rankNames = requirement.targets.ranks.map((item) => item.name);
    const projectNames = requirement.targets.projects.map((item) => item.title);

    return (
        <Main>
            <DocumentsBreadcrumbs
                items={[
                    {
                        title: 'Documents',
                        href: documentsOverview.url(),
                    },
                    { title: 'Document Types', href: listHref },
                    { title: documentType.title },
                ]}
            />

            <DetailsHeader
                kicker="Document Type"
                title={documentType.title}
                description={requirement.scope_summary}
                backHref={listHref}
                backLabel="Back to Document Types"
                actions={
                    <>
                        {can.update ? (
                            <Button
                                type="button"
                                variant="outline"
                                className="h-12 rounded-xl border-input bg-background/50 px-6 hover:bg-muted dark:border-white/5 dark:bg-white/5 dark:hover:bg-white/10"
                                onClick={openEdit}
                            >
                                Edit
                            </Button>
                        ) : null}
                        {can.delete ? (
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="icon"
                                        className="h-12 w-12 rounded-xl border-input bg-background/50 hover:bg-muted dark:border-white/5 dark:bg-white/5 dark:hover:bg-white/10"
                                        aria-label="More actions"
                                    >
                                        <MoreHorizontal className="h-4 w-4" />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem
                                        className="text-destructive focus:text-destructive"
                                        onClick={() => setDeleteOpen(true)}
                                    >
                                        <Trash2 className="mr-2 h-4 w-4" />
                                        Delete
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        ) : null}
                    </>
                }
            />

            <div className="mb-6 flex flex-wrap items-center gap-2">
                <Badge
                    variant={documentType.is_active ? 'success' : 'secondary'}
                >
                    {documentType.status_label}
                </Badge>
                <Badge
                    variant={requirement.is_required ? 'default' : 'secondary'}
                    className="font-normal"
                >
                    {requirement.requirement_label}
                </Badge>
            </div>

            <div className="grid gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    <Card className="overflow-hidden glass-card dark:border-white/5 dark:bg-white/5">
                        <CardHeader className="pb-2">
                            <CardTitle className="text-base font-semibold">
                                Overview
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="pt-0">
                            <OverviewField
                                label="Status"
                                value={documentType.status_label}
                            />
                            <OverviewField
                                label="Requirement"
                                value={requirement.requirement_label}
                            />
                            <OverviewField
                                label="Applies To"
                                value={
                                    requirement.is_required
                                        ? requirement.applies_to_label
                                        : '—'
                                }
                            />
                        </CardContent>
                    </Card>

                    <Card className="overflow-hidden glass-card dark:border-white/5 dark:bg-white/5">
                        <CardHeader className="pb-2">
                            <CardTitle className="text-base font-semibold">
                                Who needs this document?
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-5 pt-0">
                            <p className="text-sm text-muted-foreground">
                                {requirement.who_needs_copy}
                            </p>

                            {requirement.scope_kind === 'selected_groups' ? (
                                <div className="space-y-5">
                                    <TargetGroup
                                        label="Departments"
                                        names={departmentNames}
                                    />
                                    <TargetGroup
                                        label="Positions"
                                        names={positionNames}
                                    />
                                    <TargetGroup
                                        label="Ranks"
                                        names={rankNames}
                                    />
                                    <TargetGroup
                                        label="Projects"
                                        names={projectNames}
                                    />
                                </div>
                            ) : null}

                            {requirement.matching_rule_applies ? (
                                <div className="rounded-xl border border-primary/20 bg-primary/5 p-3.5 text-xs text-foreground/90">
                                    <p className="font-semibold text-primary">
                                        Matching rule
                                    </p>
                                    <p className="mt-1 leading-relaxed text-muted-foreground">
                                        Employees must match every selected
                                        category. Within each category, matching
                                        any selected value is enough. Categories
                                        with no selection do not restrict the
                                        requirement.
                                    </p>
                                </div>
                            ) : null}
                        </CardContent>
                    </Card>

                    <Card className="overflow-hidden glass-card dark:border-white/5 dark:bg-white/5">
                        <CardHeader className="pb-2">
                            <CardTitle className="text-base font-semibold">
                                Tracked document details
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4 pt-0">
                            {requirement.tracked_details.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No specific document details are configured
                                    for tracking.
                                </p>
                            ) : (
                                <ul className="space-y-2">
                                    {requirement.tracked_details.map(
                                        (detail) => (
                                            <li
                                                key={detail.key}
                                                className="flex items-center gap-2 text-sm font-medium"
                                            >
                                                <Check
                                                    className="h-4 w-4 shrink-0 text-emerald-600 dark:text-emerald-400"
                                                    aria-hidden
                                                />
                                                <span>{detail.label}</span>
                                            </li>
                                        ),
                                    )}
                                </ul>
                            )}
                            <p className="text-xs leading-relaxed text-muted-foreground">
                                These settings identify the details normally
                                tracked for this document type. They do not
                                currently make those fields mandatory during
                                upload.
                            </p>
                        </CardContent>
                    </Card>
                </div>

                <div className="space-y-6">
                    {documentType.compliance_links.length > 0 ? (
                        <Card className="overflow-hidden glass-card dark:border-white/5 dark:bg-white/5">
                            <CardHeader className="pb-2">
                                <CardTitle className="text-base font-semibold">
                                    Compliance
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-2 pt-0">
                                {documentType.compliance_links.map((link) => (
                                    <Button
                                        key={link.href}
                                        variant="outline"
                                        className="h-11 justify-start rounded-xl"
                                        asChild
                                    >
                                        <Link href={link.href}>
                                            {link.label}
                                        </Link>
                                    </Button>
                                ))}
                            </CardContent>
                        </Card>
                    ) : null}

                    {canViewAudit ? (
                        <RecentActivityCard
                            items={recentActivity}
                            description="Document type and requirement changes"
                        />
                    ) : null}
                </div>
            </div>

            <DocumentTypeFormSheet
                open={editOpen}
                onOpenChange={setEditOpen}
                current={row}
                form={form}
                canUpdate={can.update}
                departments={departments}
                positions={positions}
                ranks={ranks}
                projects={projects}
                onSubmit={submit}
            />

            <ConfirmDeleteDialog
                open={deleteOpen}
                onOpenChange={setDeleteOpen}
                title="Delete document type?"
                description="This action cannot be undone."
                confirmText="Delete"
                onConfirm={confirmDelete}
            />
        </Main>
    );
}

export default function DocumentTypeShowPage({
    document_type,
    can,
    departments = [],
    positions = [],
    ranks = [],
    projects = [],
    recent_activity,
    can_view_audit,
}: {
    document_type: DocumentTypeDetail;
    can: { update: boolean; delete: boolean };
    departments?: DepartmentOption[];
    positions?: PositionOption[];
    ranks?: RankOption[];
    projects?: ProjectOption[];
    recent_activity: RecentActivityItem[];
    can_view_audit: boolean;
}): ReactElement {
    return (
        <>
            <Head title={`${document_type.title} — Document Type`} />
            <DocumentTypeShowContent
                documentType={document_type}
                can={can}
                departments={departments}
                positions={positions}
                ranks={ranks}
                projects={projects}
                recentActivity={recent_activity}
                canViewAudit={can_view_audit}
            />
        </>
    );
}
