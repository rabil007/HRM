import type { InertiaFormProps } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import type { Role, RoleFormData } from '../types';

function normalizePermissions(value: string[]): string[] {
    return Array.from(
        new Set(
            value
                .map((p) => p.trim())
                .filter(Boolean),
        ),
    ).sort();
}

export function RoleFormSheet({
    open,
    onOpenChange,
    role,
    permissions,
    form,
    onSubmit,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    role: Role | null;
    permissions: { id: number; name: string }[];
    form: InertiaFormProps<RoleFormData>;
    onSubmit: () => void;
}) {
    const selectedPermissions = normalizePermissions(form.data.permissions ?? []);
    const availablePermissions = normalizePermissions(permissions.map((p) => p.name));

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent side="right" className="w-full sm:max-w-lg border-white/5 bg-black/60 backdrop-blur-3xl p-0 flex flex-col">
                <SheetHeader className="p-8 pb-6 border-b border-white/5">
                    <SheetTitle className="text-xl font-bold tracking-tight text-white">{role ? 'Edit Role' : 'New Role'}</SheetTitle>
                    <SheetDescription className="text-sm text-muted-foreground/80 mt-1">
                        {role ? 'Update role and permissions.' : 'Create a role and assign permissions.'}
                    </SheetDescription>
                </SheetHeader>

                <div className="flex-1 overflow-y-auto p-8 space-y-8">
                    <div className="space-y-5">
                        <div className="space-y-2">
                            <Label htmlFor="name" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                Name
                            </Label>
                            <Input
                                id="name"
                                placeholder="Admin"
                                className="rounded-xl border-white/10 bg-white/5 focus-visible:ring-primary/40 h-11 transition-all"
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                            />
                            {form.errors.name ? <div className="text-xs font-medium text-destructive">{form.errors.name}</div> : null}
                        </div>

                        <div className="space-y-3">
                            <div className="flex items-center justify-between gap-3">
                                <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">Permissions</Label>
                                <Button
                                    type="button"
                                    variant="secondary"
                                    className="rounded-xl h-9 px-3 border border-white/5 bg-white/5 hover:bg-white/10"
                                    onClick={() => form.setData('permissions', availablePermissions)}
                                >
                                    Use defaults
                                </Button>
                            </div>

                            <div className="flex flex-wrap gap-2">
                                {selectedPermissions.length ? (
                                    selectedPermissions.map((p) => (
                                        <Badge
                                            key={p}
                                            variant="secondary"
                                            className="bg-white/5 text-muted-foreground border-white/10 text-[10px] uppercase font-bold tracking-wider cursor-pointer hover:bg-white/10"
                                            onClick={() => {
                                                form.setData('permissions', selectedPermissions.filter((x) => x !== p));
                                            }}
                                            title="Click to remove"
                                        >
                                            {p}
                                        </Badge>
                                    ))
                                ) : (
                                    <div className="text-sm text-muted-foreground/80">No permissions selected.</div>
                                )}
                            </div>
                            {form.errors.permissions ? <div className="text-xs font-medium text-destructive">{form.errors.permissions}</div> : null}

                            <div className="space-y-2">
                                <Label htmlFor="add-permission" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                    Add permission
                                </Label>
                                <Input
                                    id="add-permission"
                                    placeholder="e.g. employees.view"
                                    className="rounded-xl border-white/10 bg-white/5 focus-visible:ring-primary/40 h-11 transition-all"
                                    onKeyDown={(e) => {
                                        if (e.key !== 'Enter') {
                                            return;
                                        }

                                        e.preventDefault();

                                        const value = (e.currentTarget.value ?? '').trim();

                                        if (!value) {
                                            return;
                                        }

                                        form.setData('permissions', normalizePermissions([...(form.data.permissions ?? []), value]));
                                        e.currentTarget.value = '';
                                    }}
                                />
                            </div>
                        </div>
                    </div>
                </div>

                <div className="p-6 border-t border-white/5 bg-black/20 flex gap-3">
                    <Button type="button" variant="ghost" className="rounded-xl h-11 px-6 text-muted-foreground flex-1" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button
                        className="rounded-xl h-11 px-6 flex-1 font-semibold"
                        type="button"
                        onClick={onSubmit}
                        disabled={form.processing}
                    >
                        {role ? 'Save' : 'Create'}
                    </Button>
                </div>
            </SheetContent>
        </Sheet>
    );
}

