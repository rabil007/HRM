import { useForm } from '@inertiajs/react';
import {
    ArrowRight,
    Building2,
    FolderKanban,
    Plus,
    Search,
} from 'lucide-react';
import { useState } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { cn } from '@/lib/utils';
import {
    attach as attachClientProject,
    store as storeClientProject,
} from '@/routes/settings/master-data/clients/projects';

export type AttachableProject = {
    id: number;
    title: string;
    is_active: boolean;
    is_already_assigned: boolean;
};

export type ClientProjectModalProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    client: {
        id: number;
        name: string;
        is_active: boolean;
    };
    attachableProjects: AttachableProject[];
    canAttach: boolean;
    canCreate: boolean;
};

export function ClientProjectModal({
    open,
    onOpenChange,
    client,
    attachableProjects,
    canAttach,
    canCreate,
}: ClientProjectModalProps) {
    const defaultMode: 'attach' | 'create' = canAttach ? 'attach' : 'create';
    const [selectedMode, setSelectedMode] = useState<
        'attach' | 'create' | null
    >(null);
    const mode = selectedMode ?? defaultMode;

    const attachForm = useForm({
        project_id: '' as number | '',
    });

    const createForm = useForm({
        title: '',
        is_active: true,
    });

    const handleOpenChange = (nextOpen: boolean) => {
        if (!nextOpen) {
            setSelectedMode(null);
            attachForm.reset();
            attachForm.clearErrors();
            createForm.reset();
            createForm.clearErrors();
        }

        onOpenChange(nextOpen);
    };

    const handleAttach = (e: React.FormEvent) => {
        e.preventDefault();

        if (!attachForm.data.project_id) {
            return;
        }

        attachForm.post(attachClientProject.url(client.id), {
            preserveScroll: true,
            onSuccess: () => {
                handleOpenChange(false);
                attachForm.reset();
            },
        });
    };

    const handleCreate = (e: React.FormEvent) => {
        e.preventDefault();

        createForm.post(storeClientProject.url(client.id), {
            preserveScroll: true,
            onSuccess: () => {
                handleOpenChange(false);
                createForm.reset();
            },
        });
    };

    const isDuplicateTitleError = Boolean(
        createForm.errors.title &&
        (createForm.errors.title.includes('already exists') ||
            createForm.errors.title.includes('Attach Existing')),
    );

    const handleSwitchToAttach = () => {
        const matchingProject = attachableProjects.find(
            (p) =>
                p.title.trim().toLowerCase() ===
                createForm.data.title.trim().toLowerCase(),
        );

        setSelectedMode('attach');

        if (matchingProject && !matchingProject.is_already_assigned) {
            attachForm.setData('project_id', matchingProject.id);
        }
    };

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogContent className="max-w-md gap-0 overflow-hidden p-0">
                <DialogHeader className="border-b border-border/60 bg-muted/20 p-6 pb-4">
                    <div className="flex items-center gap-2 text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                        <FolderKanban className="size-3.5 text-primary" />
                        <span>Client Operations</span>
                    </div>
                    <DialogTitle className="mt-1 text-lg font-bold text-foreground">
                        Add Project to {client.name}
                    </DialogTitle>
                    <DialogDescription className="text-xs text-muted-foreground">
                        Assign an existing global project or register a new one
                        for this client.
                    </DialogDescription>

                    {/* Mode Segmented Switcher */}
                    {canAttach && canCreate ? (
                        <div className="mt-4 flex rounded-xl border border-border/80 bg-muted/40 p-1">
                            <button
                                type="button"
                                onClick={() => setSelectedMode('attach')}
                                className={cn(
                                    'flex flex-1 items-center justify-center gap-1.5 rounded-lg py-1.5 text-xs font-semibold transition-all',
                                    mode === 'attach'
                                        ? 'bg-background text-foreground shadow-xs'
                                        : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                <Search className="size-3.5" />
                                Attach Existing
                            </button>
                            <button
                                type="button"
                                onClick={() => setSelectedMode('create')}
                                className={cn(
                                    'flex flex-1 items-center justify-center gap-1.5 rounded-lg py-1.5 text-xs font-semibold transition-all',
                                    mode === 'create'
                                        ? 'bg-background text-foreground shadow-xs'
                                        : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                <Plus className="size-3.5" />
                                Create New
                            </button>
                        </div>
                    ) : null}
                </DialogHeader>

                {/* Attach Existing Mode */}
                {mode === 'attach' ? (
                    <form onSubmit={handleAttach}>
                        <div className="space-y-4 p-6">
                            {/* Current Locked Client */}
                            <div className="space-y-1.5">
                                <Label className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                    Current Client
                                </Label>
                                <div className="flex h-10 items-center justify-between rounded-xl border border-border/80 bg-muted/30 px-3.5 text-xs font-medium text-foreground">
                                    <div className="flex items-center gap-2">
                                        <Building2 className="size-3.5 text-primary" />
                                        <span>{client.name}</span>
                                    </div>
                                    <span className="rounded-md border border-border/60 bg-background/80 px-2 py-0.5 text-[10px] font-semibold tracking-wider text-muted-foreground uppercase">
                                        Contextual
                                    </span>
                                </div>
                            </div>

                            {/* Project Select */}
                            <div className="space-y-1.5">
                                <Label
                                    htmlFor="attach_project_select"
                                    className="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                >
                                    Select Project{' '}
                                    <span className="text-destructive">*</span>
                                </Label>
                                <AppSelect
                                    value={
                                        attachForm.data.project_id === ''
                                            ? ''
                                            : String(attachForm.data.project_id)
                                    }
                                    onValueChange={(val) =>
                                        attachForm.setData(
                                            'project_id',
                                            val ? Number(val) : '',
                                        )
                                    }
                                    placeholder="Search active projects..."
                                    searchPlaceholder="Filter projects by title..."
                                    variant="dark"
                                    className="h-10 rounded-xl text-xs"
                                >
                                    {attachableProjects.map((p) => (
                                        <AppSelectItem
                                            key={p.id}
                                            value={String(p.id)}
                                            disabled={p.is_already_assigned}
                                        >
                                            <div className="flex w-full items-center justify-between gap-2">
                                                <span className="truncate">
                                                    {p.title}
                                                </span>
                                                {p.is_already_assigned ? (
                                                    <span className="text-[10px] text-muted-foreground">
                                                        (Already assigned)
                                                    </span>
                                                ) : null}
                                            </div>
                                        </AppSelectItem>
                                    ))}
                                </AppSelect>
                                {attachForm.errors.project_id ? (
                                    <p className="text-xs font-medium text-destructive">
                                        {attachForm.errors.project_id}
                                    </p>
                                ) : null}
                                <p className="text-[11px] text-muted-foreground">
                                    Only active projects can be attached.
                                    Already linked projects are disabled.
                                </p>
                            </div>
                        </div>

                        <DialogFooter className="border-t border-border/60 bg-muted/10 p-4">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => handleOpenChange(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                size="sm"
                                disabled={
                                    attachForm.processing ||
                                    !attachForm.data.project_id
                                }
                            >
                                {attachForm.processing
                                    ? 'Attaching...'
                                    : 'Attach Project'}
                            </Button>
                        </DialogFooter>
                    </form>
                ) : (
                    /* Create New Mode */
                    <form onSubmit={handleCreate}>
                        <div className="space-y-4 p-6">
                            {/* Current Locked Client */}
                            <div className="space-y-1.5">
                                <Label className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                    Client
                                </Label>
                                <div className="flex h-10 items-center justify-between rounded-xl border border-border/80 bg-muted/30 px-3.5 text-xs font-medium text-foreground">
                                    <div className="flex items-center gap-2">
                                        <Building2 className="size-3.5 text-primary" />
                                        <span>{client.name}</span>
                                    </div>
                                    <span className="rounded-md border border-border/60 bg-background/80 px-2 py-0.5 text-[10px] font-semibold tracking-wider text-muted-foreground uppercase">
                                        Locked
                                    </span>
                                </div>
                            </div>

                            {/* Project Title */}
                            <div className="space-y-1.5">
                                <Label
                                    htmlFor="create_project_title"
                                    className="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                >
                                    Project Name{' '}
                                    <span className="text-destructive">*</span>
                                </Label>
                                <Input
                                    id="create_project_title"
                                    value={createForm.data.title}
                                    onChange={(e) =>
                                        createForm.setData(
                                            'title',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="e.g. Upper Zakum Field Maintenance"
                                    className="h-10 rounded-xl text-xs"
                                    autoFocus
                                />
                                {createForm.errors.title ? (
                                    <div className="space-y-1.5">
                                        <p className="text-xs font-medium text-destructive">
                                            {createForm.errors.title}
                                        </p>
                                        {isDuplicateTitleError && canAttach ? (
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={handleSwitchToAttach}
                                                className="h-7 text-xs text-primary"
                                            >
                                                Switch to &ldquo;Attach
                                                Existing&rdquo;
                                                <ArrowRight className="ml-1 size-3" />
                                            </Button>
                                        ) : null}
                                    </div>
                                ) : null}
                            </div>

                            {/* Active Switch */}
                            <div className="flex items-center justify-between rounded-xl border border-border/80 bg-muted/20 p-3.5">
                                <div className="space-y-0.5">
                                    <Label
                                        htmlFor="create_project_active"
                                        className="text-xs font-medium"
                                    >
                                        Active status
                                    </Label>
                                    <p className="text-[11px] text-muted-foreground">
                                        Enable for current crew operations and
                                        recruitment.
                                    </p>
                                </div>
                                <Switch
                                    id="create_project_active"
                                    checked={createForm.data.is_active}
                                    onCheckedChange={(val) =>
                                        createForm.setData('is_active', val)
                                    }
                                />
                            </div>
                        </div>

                        <DialogFooter className="border-t border-border/60 bg-muted/10 p-4">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => handleOpenChange(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                size="sm"
                                disabled={
                                    createForm.processing ||
                                    createForm.data.title.trim() === ''
                                }
                            >
                                {createForm.processing
                                    ? 'Creating...'
                                    : 'Create Project'}
                            </Button>
                        </DialogFooter>
                    </form>
                )}
            </DialogContent>
        </Dialog>
    );
}
