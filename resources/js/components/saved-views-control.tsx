import { router, useForm } from '@inertiajs/react';
import { Bookmark } from 'lucide-react';
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
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
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
}: {
    pageKey: SavedViewPageKey;
    indexUrl: string;
    currentFilters: Record<string, unknown>;
    views?: SavedView[];
}) {
    const captured = useMemo(
        () => captureCurrentFilters(pageKey, currentFilters),
        [pageKey, currentFilters],
    );
    const [saveOpen, setSaveOpen] = useState(false);
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
                                <DropdownMenuSub key={view.id}>
                                    <DropdownMenuSubTrigger>
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
                                                <span className="sr-only">
                                                    Currently applied
                                                </span>
                                            ) : null}
                                        </span>
                                    </DropdownMenuSubTrigger>
                                    <DropdownMenuSubContent>
                                        <DropdownMenuItem
                                            onSelect={() => applyView(view)}
                                        >
                                            Apply
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            onSelect={() => openRename(view)}
                                        >
                                            Rename
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            onSelect={() =>
                                                setDefault(
                                                    view,
                                                    !view.is_default,
                                                )
                                            }
                                        >
                                            {view.is_default
                                                ? 'Clear default'
                                                : 'Set as default'}
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            variant="destructive"
                                            onSelect={() => openDelete(view)}
                                        >
                                            Delete
                                        </DropdownMenuItem>
                                    </DropdownMenuSubContent>
                                </DropdownMenuSub>
                            );
                        })
                    )}
                    <DropdownMenuSeparator />
                    <DropdownMenuItem onSelect={openSave}>
                        Save current view...
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

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
