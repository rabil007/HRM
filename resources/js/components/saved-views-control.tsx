import { router, useForm } from '@inertiajs/react';
import { Bookmark, Check } from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    destroy as destroySavedView,
    store as storeSavedView,
    update as updateSavedView,
} from '@/actions/App/Http/Controllers/SavedViewController';
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
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import {
    applySavedViewFilters,
    captureCurrentFilters,
    savedViewFiltersMatch,
} from '@/lib/saved-views';
import type { SavedView, SavedViewPageKey } from '@/lib/saved-views';

export function SavedViewsControl({
    pageKey,
    indexUrl,
    currentFilters,
    views = [],
    onBeforeApply,
}: {
    pageKey: SavedViewPageKey;
    indexUrl: string;
    currentFilters: Record<string, unknown>;
    views?: SavedView[];
    onBeforeApply?: () => void;
}) {
    const captured = useMemo(
        () => captureCurrentFilters(pageKey, currentFilters),
        [pageKey, currentFilters],
    );
    const [saveOpen, setSaveOpen] = useState(false);
    const [manageOpen, setManageOpen] = useState(false);
    const [renameOpen, setRenameOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [activeView, setActiveView] = useState<SavedView | null>(null);

    const saveForm = useForm({
        page_key: pageKey,
        name: '',
        filters: {} as Record<string, string>,
        is_default: false,
    });
    const renameForm = useForm({
        name: '',
    });

    const openSave = () => {
        saveForm.setData({
            page_key: pageKey,
            name: '',
            filters: captured,
            is_default: false,
        });
        saveForm.clearErrors();
        setSaveOpen(true);
    };

    const applyView = (view: SavedView) => {
        onBeforeApply?.();
        router.get(indexUrl, applySavedViewFilters(pageKey, view.filters), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const submitSave = () => {
        saveForm.post(storeSavedView.url(), {
            preserveScroll: true,
            onSuccess: () => setSaveOpen(false),
        });
    };

    const openRename = (view: SavedView) => {
        setActiveView(view);
        renameForm.setData('name', view.name);
        renameForm.clearErrors();
        setRenameOpen(true);
    };

    const submitRename = () => {
        if (activeView === null) {
            return;
        }

        renameForm.put(updateSavedView.url(activeView.id), {
            preserveScroll: true,
            onSuccess: () => setRenameOpen(false),
        });
    };

    const setDefault = (view: SavedView, isDefault: boolean) => {
        router.put(
            updateSavedView.url(view.id),
            { is_default: isDefault },
            { preserveScroll: true },
        );
    };

    const openDelete = (view: SavedView) => {
        setActiveView(view);
        setDeleteOpen(true);
    };

    const confirmDelete = () => {
        if (activeView === null) {
            return;
        }

        router.delete(destroySavedView.url(activeView.id), {
            preserveScroll: true,
            onSuccess: () => setDeleteOpen(false),
        });
    };

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        type="button"
                        variant="secondary"
                        className="h-12 rounded-xl glass-card px-4 hover:bg-accent sm:px-5"
                        aria-label="Saved views"
                    >
                        <Bookmark className="mr-2 h-4 w-4" />
                        Views
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="min-w-56">
                    {views.length === 0 ? (
                        <DropdownMenuItem disabled>
                            No saved views yet
                        </DropdownMenuItem>
                    ) : (
                        views.map((view) => {
                            const isCurrent = savedViewFiltersMatch(
                                captured,
                                applySavedViewFilters(pageKey, view.filters),
                            );

                            return (
                                <DropdownMenuItem
                                    key={view.id}
                                    onSelect={() => applyView(view)}
                                >
                                    <span className="flex min-w-0 flex-1 items-center gap-2">
                                        <span className="truncate">
                                            {view.name}
                                        </span>
                                        {view.is_default ? (
                                            <span className="shrink-0 text-[10px] font-semibold tracking-wide text-primary uppercase">
                                                Default
                                            </span>
                                        ) : null}
                                        {isCurrent ? (
                                            <Check
                                                className="ml-auto h-4 w-4 shrink-0 text-primary"
                                                aria-label="Currently applied"
                                            />
                                        ) : null}
                                    </span>
                                </DropdownMenuItem>
                            );
                        })
                    )}
                    <DropdownMenuSeparator />
                    <DropdownMenuItem onSelect={openSave}>
                        Save current view...
                    </DropdownMenuItem>
                    {views.length > 0 ? (
                        <DropdownMenuItem onSelect={() => setManageOpen(true)}>
                            Manage views...
                        </DropdownMenuItem>
                    ) : null}
                </DropdownMenuContent>
            </DropdownMenu>

            <Dialog open={manageOpen} onOpenChange={setManageOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Manage views</DialogTitle>
                        <DialogDescription>
                            Rename, set a default, or delete a saved view. Tap a
                            view in the menu to apply it.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="max-h-80 space-y-2 overflow-y-auto">
                        {views.map((view) => (
                            <div
                                key={view.id}
                                className="flex flex-col gap-2 rounded-lg border border-border/60 p-3 sm:flex-row sm:items-center sm:justify-between"
                            >
                                <div className="min-w-0">
                                    <p className="truncate text-sm font-medium">
                                        {view.name}
                                    </p>
                                    {view.is_default ? (
                                        <p className="text-[11px] font-semibold tracking-wide text-primary uppercase">
                                            Default
                                        </p>
                                    ) : null}
                                </div>
                                <div className="flex flex-wrap gap-1">
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        className="h-10 min-h-10"
                                        onClick={() => {
                                            setManageOpen(false);
                                            openRename(view);
                                        }}
                                    >
                                        Rename
                                    </Button>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        className="h-10 min-h-10"
                                        onClick={() =>
                                            setDefault(view, !view.is_default)
                                        }
                                    >
                                        {view.is_default
                                            ? 'Clear default'
                                            : 'Set default'}
                                    </Button>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        className="h-10 min-h-10 text-destructive hover:text-destructive"
                                        onClick={() => {
                                            setManageOpen(false);
                                            openDelete(view);
                                        }}
                                    >
                                        Delete
                                    </Button>
                                </div>
                            </div>
                        ))}
                    </div>
                </DialogContent>
            </Dialog>

            <Dialog open={saveOpen} onOpenChange={setSaveOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Save current view</DialogTitle>
                        <DialogDescription>
                            Name this filter combination. It stays private to
                            you in this company.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="saved-view-name">Name</Label>
                            <Input
                                id="saved-view-name"
                                value={saveForm.data.name}
                                onChange={(event) =>
                                    saveForm.setData('name', event.target.value)
                                }
                                maxLength={60}
                                autoFocus
                            />
                            {saveForm.errors.name ? (
                                <p className="text-sm text-destructive">
                                    {saveForm.errors.name}
                                </p>
                            ) : null}
                            {saveForm.errors.filters ? (
                                <p className="text-sm text-destructive">
                                    {saveForm.errors.filters}
                                </p>
                            ) : null}
                        </div>
                        <label className="flex items-center justify-between gap-3 text-sm">
                            <span>Set as default for this page</span>
                            <Switch
                                checked={saveForm.data.is_default}
                                onCheckedChange={(checked) =>
                                    saveForm.setData('is_default', checked)
                                }
                            />
                        </label>
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setSaveOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            onClick={submitSave}
                            disabled={saveForm.processing}
                        >
                            Save view
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={renameOpen} onOpenChange={setRenameOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Rename saved view</DialogTitle>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor="saved-view-rename">Name</Label>
                        <Input
                            id="saved-view-rename"
                            value={renameForm.data.name}
                            onChange={(event) =>
                                renameForm.setData('name', event.target.value)
                            }
                            maxLength={60}
                            autoFocus
                        />
                        {renameForm.errors.name ? (
                            <p className="text-sm text-destructive">
                                {renameForm.errors.name}
                            </p>
                        ) : null}
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setRenameOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            onClick={submitRename}
                            disabled={renameForm.processing}
                        >
                            Save
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <AlertDialog open={deleteOpen} onOpenChange={setDeleteOpen}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete saved view?</AlertDialogTitle>
                        <AlertDialogDescription>
                            {activeView
                                ? `“${activeView.name}” will be removed from this page. This does not change list permissions.`
                                : 'This saved view will be removed from this page.'}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction onClick={confirmDelete}>
                            Delete
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}
