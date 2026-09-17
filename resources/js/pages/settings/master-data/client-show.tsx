import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowRight,
    ArrowUpRight,
    Building2,
    Calendar,
    FolderKanban,
    Pencil,
    Plus,
    Ship,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import { DetailsHeader } from '@/components/details-header';
import { Main } from '@/components/layout/main';
import { RecentActivityCard } from '@/components/recent-activity-card';
import type { RecentActivityItem } from '@/components/recent-activity-card';
import { MasterDataInUseBadge } from '@/components/settings/master-data-in-use-badge';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDisplayDate } from '@/lib/format-date';
import type { MasterDataUsageFlags } from '@/lib/master-data/usage';
import { cn } from '@/lib/utils';

type ClientDetails = {
    id: number;
    name: string;
    is_active: boolean;
    created_at: string | null;
    updated_at: string | null;
} & MasterDataUsageFlags;

type ProjectPreview = {
    id: number;
    title: string;
    is_active: boolean;
    created_at: string | null;
};

type VesselPreview = {
    id: number;
    name: string;
    vessel_type_id: number | null;
    vessel_type_name: string | null;
    is_active: boolean;
    imo_no: string | null;
    call_sign: string | null;
    official_no: string | null;
    created_at: string | null;
};

type ClientOperations = {
    projects: {
        total_count: number;
        active_count: number;
        preview: ProjectPreview[];
    };
    vessels: {
        total_count: number;
        active_count: number;
        preview: VesselPreview[];
    };
};

type ClientShowPermissions = {
    update: boolean;
    delete: boolean;
    view_projects: boolean;
    create_project: boolean;
    view_vessels: boolean;
    create_vessel: boolean;
    view_audit: boolean;
};

export default function ClientShow({
    client,
    operations,
    can,
    recent_activity = [],
    can_view_audit = false,
}: {
    client: ClientDetails;
    operations: ClientOperations;
    can: ClientShowPermissions;
    recent_activity?: RecentActivityItem[];
    can_view_audit?: boolean;
}) {
    const [editOpen, setEditOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);

    const form = useForm({
        name: client.name,
        is_active: client.is_active,
    });

    const openEditSheet = () => {
        form.setData({
            name: client.name,
            is_active: client.is_active,
        });
        form.clearErrors();
        setEditOpen(true);
    };

    const handleUpdate = () => {
        form.put(`/settings/master-data/clients/${client.id}`, {
            preserveScroll: true,
            onSuccess: () => setEditOpen(false),
        });
    };

    const handleDelete = () => {
        router.delete(`/settings/master-data/clients/${client.id}`, {
            preserveScroll: false,
            onFinish: () => setDeleteOpen(false),
        });
    };

    const projectsCount = operations.projects.total_count;
    const activeProjectsCount = operations.projects.active_count;
    const vesselsCount = operations.vessels.total_count;
    const activeVesselsCount = operations.vessels.active_count;

    return (
        <Main>
            <Head title={`Client • ${client.name}`} />

            <DetailsHeader
                kicker="Master Data • Operations Hub"
                title={
                    <div className="flex flex-wrap items-center gap-3">
                        <span>{client.name}</span>
                        <Badge
                            variant="outline"
                            className={cn(
                                'text-xs font-semibold',
                                client.is_active
                                    ? 'border-emerald-500/20 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                                    : 'border-muted bg-muted/30 text-muted-foreground',
                            )}
                        >
                            {client.is_active ? 'Active' : 'Inactive'}
                        </Badge>
                        <MasterDataInUseBadge item={client} />
                    </div>
                }
                description="Comprehensive view of client operations, linked project portfolio, and assigned fleet."
                backHref="/settings/master-data/clients"
                backLabel="Back to clients"
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        {can.update ? (
                            <Button
                                variant="outline"
                                className="gap-1.5"
                                onClick={openEditSheet}
                            >
                                <Pencil className="size-4" />
                                Edit client
                            </Button>
                        ) : null}

                        {can.delete && client.can_delete ? (
                            <Button
                                variant="outline"
                                className="gap-1.5 text-destructive hover:bg-destructive/10 hover:text-destructive"
                                onClick={() => setDeleteOpen(true)}
                            >
                                <Trash2 className="size-4" />
                                Delete
                            </Button>
                        ) : null}
                    </div>
                }
            />

            <div className="space-y-6">
                {/* Lightweight Relationship Indicator */}
                <div className="flex flex-wrap items-center gap-2 rounded-xl border border-border/70 bg-muted/20 px-4 py-3 text-xs text-muted-foreground">
                    <span className="font-semibold tracking-wider text-foreground uppercase">
                        Operational Map
                    </span>
                    <span className="text-muted-foreground/30">•</span>
                    <span className="flex items-center gap-1.5 font-medium text-foreground">
                        <Building2 className="size-3.5 text-primary" />
                        {client.name}
                    </span>
                    <ArrowRight className="size-3.5 text-muted-foreground/40" />
                    <span className="flex items-center gap-1.5 font-medium text-foreground">
                        <FolderKanban className="size-3.5 text-primary" />
                        {projectsCount}{' '}
                        {projectsCount === 1 ? 'Project' : 'Projects'}
                    </span>
                    <ArrowRight className="size-3.5 text-muted-foreground/40" />
                    <span className="flex items-center gap-1.5 font-medium text-foreground">
                        <Ship className="size-3.5 text-primary" />
                        {vesselsCount}{' '}
                        {vesselsCount === 1 ? 'Vessel' : 'Vessels'}
                        <span className="text-[10px] text-muted-foreground">
                            (active company)
                        </span>
                    </span>
                </div>

                {/* KPI Summary Cards */}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Card className="rounded-xl border border-border/70 bg-card/60 shadow-xs">
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-semibold tracking-wider text-muted-foreground uppercase">
                                Projects
                            </CardTitle>
                            <div className="flex size-9 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                <FolderKanban className="size-4" />
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            <div className="flex items-baseline gap-2">
                                <span className="text-3xl font-extrabold tracking-tight text-foreground">
                                    {projectsCount}
                                </span>
                                <span className="text-xs font-medium text-muted-foreground">
                                    {activeProjectsCount} active
                                </span>
                            </div>
                            <p className="text-xs text-muted-foreground">
                                Operational projects registered under this
                                client.
                            </p>
                            {can.view_projects ? (
                                <div className="pt-2">
                                    <Button
                                        variant="link"
                                        size="sm"
                                        className="h-auto p-0 text-xs font-semibold text-primary"
                                        asChild
                                    >
                                        <Link
                                            href={`/settings/master-data/projects?client_id=${client.id}`}
                                        >
                                            View projects module
                                            <ArrowUpRight className="ml-1 size-3" />
                                        </Link>
                                    </Button>
                                </div>
                            ) : null}
                        </CardContent>
                    </Card>

                    <Card className="rounded-xl border border-border/70 bg-card/60 shadow-xs">
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-semibold tracking-wider text-muted-foreground uppercase">
                                Fleet / Vessels
                            </CardTitle>
                            <div className="flex size-9 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                <Ship className="size-4" />
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            <div className="flex items-baseline gap-2">
                                <span className="text-3xl font-extrabold tracking-tight text-foreground">
                                    {vesselsCount}
                                </span>
                                <span className="text-xs font-medium text-muted-foreground">
                                    {activeVesselsCount} active in company
                                </span>
                            </div>
                            <p className="text-xs text-muted-foreground">
                                Vessels associated with this client in the
                                active company.
                            </p>
                            {can.view_vessels ? (
                                <div className="pt-2">
                                    <Button
                                        variant="link"
                                        size="sm"
                                        className="h-auto p-0 text-xs font-semibold text-primary"
                                        asChild
                                    >
                                        <Link
                                            href={`/organization/vessels?client_id=${client.id}`}
                                        >
                                            View vessels module
                                            <ArrowUpRight className="ml-1 size-3" />
                                        </Link>
                                    </Button>
                                </div>
                            ) : null}
                        </CardContent>
                    </Card>
                </div>

                {/* Split Operational Overview: Projects & Vessels */}
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    {/* Projects Section */}
                    <Card className="flex flex-col rounded-xl border border-border/70 bg-card shadow-xs">
                        <CardHeader className="flex flex-row items-center justify-between border-b border-border/60 pb-3">
                            <div className="flex items-center gap-2">
                                <FolderKanban className="size-4 text-primary" />
                                <CardTitle className="text-base font-bold text-foreground">
                                    Projects
                                </CardTitle>
                                <Badge
                                    variant="secondary"
                                    className="ml-1 text-xs"
                                >
                                    {projectsCount}
                                </Badge>
                            </div>
                            {can.view_projects && projectsCount > 0 ? (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="h-7 text-xs font-semibold text-primary"
                                    asChild
                                >
                                    <Link
                                        href={`/settings/master-data/projects?client_id=${client.id}`}
                                    >
                                        View all
                                        <ArrowRight className="ml-1 size-3" />
                                    </Link>
                                </Button>
                            ) : null}
                        </CardHeader>
                        <CardContent className="flex-1 p-0">
                            {operations.projects.preview.length > 0 ? (
                                <div className="overflow-x-auto">
                                    <Table>
                                        <TableHeader className="bg-muted/30">
                                            <TableRow className="border-border/60 hover:bg-transparent">
                                                <TableHead className="text-xs font-semibold uppercase">
                                                    Project
                                                </TableHead>
                                                <TableHead className="w-24 text-xs font-semibold uppercase">
                                                    Status
                                                </TableHead>
                                                <TableHead className="w-28 text-right text-xs font-semibold uppercase">
                                                    Created
                                                </TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {operations.projects.preview.map(
                                                (project) => (
                                                    <TableRow
                                                        key={project.id}
                                                        className="border-border/60 hover:bg-muted/20"
                                                    >
                                                        <TableCell className="text-sm font-medium">
                                                            {can.view_projects ? (
                                                                <Link
                                                                    href={`/settings/master-data/projects?client_id=${client.id}&search=${encodeURIComponent(project.title)}`}
                                                                    className="text-foreground transition-colors hover:text-primary hover:underline"
                                                                >
                                                                    {
                                                                        project.title
                                                                    }
                                                                </Link>
                                                            ) : (
                                                                <span className="text-foreground">
                                                                    {
                                                                        project.title
                                                                    }
                                                                </span>
                                                            )}
                                                        </TableCell>
                                                        <TableCell>
                                                            <Badge
                                                                variant="outline"
                                                                className={cn(
                                                                    'text-[10px] font-semibold',
                                                                    project.is_active
                                                                        ? 'border-emerald-500/20 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                                                                        : 'border-muted bg-muted/40 text-muted-foreground',
                                                                )}
                                                            >
                                                                {project.is_active
                                                                    ? 'Active'
                                                                    : 'Inactive'}
                                                            </Badge>
                                                        </TableCell>
                                                        <TableCell className="text-right text-xs text-muted-foreground">
                                                            {project.created_at
                                                                ? formatDisplayDate(
                                                                      project.created_at,
                                                                  )
                                                                : '—'}
                                                        </TableCell>
                                                    </TableRow>
                                                ),
                                            )}
                                        </TableBody>
                                    </Table>
                                </div>
                            ) : (
                                <div className="flex flex-col items-center justify-center p-8 text-center">
                                    <div className="mb-3 flex size-12 items-center justify-center rounded-xl bg-muted/40 text-muted-foreground">
                                        <FolderKanban className="size-6 text-muted-foreground/60" />
                                    </div>
                                    <h4 className="text-sm font-semibold text-foreground">
                                        No projects linked yet
                                    </h4>
                                    <p className="mt-1 max-w-xs text-xs text-muted-foreground">
                                        Projects created for this client will
                                        appear here.
                                    </p>
                                    {can.create_project ? (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            className="mt-4 gap-1.5 text-xs"
                                            asChild
                                        >
                                            <Link href="/settings/master-data/projects">
                                                <Plus className="size-3.5" />
                                                Manage Projects
                                            </Link>
                                        </Button>
                                    ) : null}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Vessels Section */}
                    <Card className="flex flex-col rounded-xl border border-border/70 bg-card shadow-xs">
                        <CardHeader className="flex flex-row items-center justify-between border-b border-border/60 pb-3">
                            <div className="flex items-center gap-2">
                                <Ship className="size-4 text-primary" />
                                <CardTitle className="text-base font-bold text-foreground">
                                    Vessels
                                </CardTitle>
                                <Badge
                                    variant="secondary"
                                    className="ml-1 text-xs"
                                >
                                    {vesselsCount}
                                </Badge>
                            </div>
                            {can.view_vessels && vesselsCount > 0 ? (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="h-7 text-xs font-semibold text-primary"
                                    asChild
                                >
                                    <Link
                                        href={`/organization/vessels?client_id=${client.id}`}
                                    >
                                        View all
                                        <ArrowRight className="ml-1 size-3" />
                                    </Link>
                                </Button>
                            ) : null}
                        </CardHeader>
                        <CardContent className="flex-1 p-0">
                            {operations.vessels.preview.length > 0 ? (
                                <div className="overflow-x-auto">
                                    <Table>
                                        <TableHeader className="bg-muted/30">
                                            <TableRow className="border-border/60 hover:bg-transparent">
                                                <TableHead className="text-xs font-semibold uppercase">
                                                    Vessel
                                                </TableHead>
                                                <TableHead className="text-xs font-semibold uppercase">
                                                    Type
                                                </TableHead>
                                                <TableHead className="text-xs font-semibold uppercase">
                                                    IMO / Call Sign
                                                </TableHead>
                                                <TableHead className="w-20 text-right text-xs font-semibold uppercase">
                                                    Status
                                                </TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {operations.vessels.preview.map(
                                                (vessel) => (
                                                    <TableRow
                                                        key={vessel.id}
                                                        className="border-border/60 hover:bg-muted/20"
                                                    >
                                                        <TableCell className="text-sm font-medium">
                                                            {can.view_vessels ? (
                                                                <Link
                                                                    href={`/organization/vessels/${vessel.id}`}
                                                                    className="text-foreground transition-colors hover:text-primary hover:underline"
                                                                >
                                                                    {
                                                                        vessel.name
                                                                    }
                                                                </Link>
                                                            ) : (
                                                                <span className="text-foreground">
                                                                    {
                                                                        vessel.name
                                                                    }
                                                                </span>
                                                            )}
                                                        </TableCell>
                                                        <TableCell className="text-xs text-muted-foreground">
                                                            {vessel.vessel_type_name ??
                                                                '—'}
                                                        </TableCell>
                                                        <TableCell className="text-xs text-muted-foreground">
                                                            {vessel.imo_no ||
                                                                vessel.call_sign ||
                                                                vessel.official_no ||
                                                                '—'}
                                                        </TableCell>
                                                        <TableCell className="text-right">
                                                            <Badge
                                                                variant="outline"
                                                                className={cn(
                                                                    'text-[10px] font-semibold',
                                                                    vessel.is_active
                                                                        ? 'border-emerald-500/20 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                                                                        : 'border-muted bg-muted/40 text-muted-foreground',
                                                                )}
                                                            >
                                                                {vessel.is_active
                                                                    ? 'Active'
                                                                    : 'Inactive'}
                                                            </Badge>
                                                        </TableCell>
                                                    </TableRow>
                                                ),
                                            )}
                                        </TableBody>
                                    </Table>
                                </div>
                            ) : (
                                <div className="flex flex-col items-center justify-center p-8 text-center">
                                    <div className="mb-3 flex size-12 items-center justify-center rounded-xl bg-muted/40 text-muted-foreground">
                                        <Ship className="size-6 text-muted-foreground/60" />
                                    </div>
                                    <h4 className="text-sm font-semibold text-foreground">
                                        No vessels linked yet
                                    </h4>
                                    <p className="mt-1 max-w-xs text-xs text-muted-foreground">
                                        Vessels associated with this client in
                                        the active company will appear here.
                                    </p>
                                    {can.view_vessels ? (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            className="mt-4 gap-1.5 text-xs"
                                            asChild
                                        >
                                            <Link href="/organization/vessels">
                                                <Plus className="size-3.5" />
                                                Manage Vessels
                                            </Link>
                                        </Button>
                                    ) : null}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Record Metadata & Details Card */}
                <Card className="rounded-xl border border-border/70 bg-card/60 shadow-xs">
                    <CardHeader className="pb-3">
                        <CardTitle className="text-sm font-bold text-foreground">
                            Record Information
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div className="space-y-1">
                            <span className="text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
                                Client Name
                            </span>
                            <p className="text-sm font-medium text-foreground">
                                {client.name}
                            </p>
                        </div>
                        <div className="space-y-1">
                            <span className="text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
                                Created On
                            </span>
                            <p className="flex items-center gap-1.5 text-sm text-foreground">
                                <Calendar className="size-3.5 text-muted-foreground" />
                                {client.created_at
                                    ? formatDisplayDate(client.created_at)
                                    : '—'}
                            </p>
                        </div>
                        <div className="space-y-1">
                            <span className="text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
                                Master Data Usage
                            </span>
                            <div className="pt-0.5">
                                <MasterDataInUseBadge item={client} />
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Recent Activity / Audit Trail */}
                {can_view_audit ? (
                    <RecentActivityCard
                        items={recent_activity}
                        description="Audit trail and change history for this client."
                    />
                ) : null}
            </div>

            {/* Edit Client Sheet */}
            <Sheet open={editOpen} onOpenChange={setEditOpen}>
                <SheetContent className="overflow-y-auto sm:max-w-md">
                    <SheetHeader>
                        <SheetTitle>Edit client</SheetTitle>
                        <SheetDescription>
                            Update the client name and active status.
                        </SheetDescription>
                    </SheetHeader>

                    <div className="space-y-5 py-6">
                        <div className="space-y-2">
                            <Label htmlFor="edit_client_name">
                                Client name
                            </Label>
                            <Input
                                id="edit_client_name"
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                                placeholder="Enter client name"
                            />
                            {form.errors.name ? (
                                <p className="text-xs text-destructive">
                                    {form.errors.name}
                                </p>
                            ) : null}
                        </div>

                        <div className="flex items-center justify-between rounded-xl border border-border/80 bg-muted/20 p-4">
                            <div className="space-y-0.5">
                                <Label
                                    htmlFor="edit_client_active"
                                    className="text-sm font-medium"
                                >
                                    Active status
                                </Label>
                                <p className="text-xs text-muted-foreground">
                                    Allow this client to be assigned to projects
                                    and vessels.
                                </p>
                            </div>
                            <Switch
                                id="edit_client_active"
                                checked={form.data.is_active}
                                onCheckedChange={(val) =>
                                    form.setData('is_active', val)
                                }
                            />
                        </div>

                        <div className="flex justify-end gap-2 pt-4">
                            <Button
                                variant="outline"
                                type="button"
                                onClick={() => setEditOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="button"
                                disabled={form.processing}
                                onClick={handleUpdate}
                            >
                                Save changes
                            </Button>
                        </div>
                    </div>
                </SheetContent>
            </Sheet>

            {/* Confirm Delete Dialog */}
            <AlertDialog open={deleteOpen} onOpenChange={setDeleteOpen}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete client</AlertDialogTitle>
                        <AlertDialogDescription>
                            Are you sure you want to delete &ldquo;{client.name}
                            &rdquo;? This action cannot be undone.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={handleDelete}
                            className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                        >
                            Delete
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </Main>
    );
}
