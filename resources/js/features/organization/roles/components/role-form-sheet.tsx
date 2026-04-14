import type { InertiaFormProps } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Switch } from '@/components/ui/switch';
import type { Company, Role, RoleFormData } from '../types';

const defaultPermissions = [
    'companies.view',
    'companies.create',
    'companies.update',
    'companies.delete',
    'branches.view',
    'branches.create',
    'branches.update',
    'branches.delete',
    'departments.view',
    'departments.create',
    'departments.update',
    'departments.delete',
    'positions.view',
    'positions.create',
    'positions.update',
    'positions.delete',
    'roles.view',
    'roles.create',
    'roles.update',
    'roles.delete',
    'users.view',
    'users.create',
    'users.update',
    'users.delete',
];

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
    companies,
    form,
    onSubmit,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    role: Role | null;
    companies: Company[];
    form: InertiaFormProps<RoleFormData>;
    onSubmit: () => void;
}) {
    const permissions = normalizePermissions(form.data.permissions ?? []);

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
                            <Label htmlFor="company_id" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                Company
                            </Label>
                            <select
                                id="company_id"
                                className="w-full rounded-xl border border-white/10 bg-white/5 h-11 px-3 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-primary/40 transition-all"
                                value={form.data.company_id}
                                onChange={(e) => form.setData('company_id', e.target.value ? Number(e.target.value) : '')}
                                disabled={Boolean(role?.is_system)}
                            >
                                <option value="">Select company</option>
                                {companies.map((c) => (
                                    <option key={c.id} value={c.id}>
                                        {c.name}
                                    </option>
                                ))}
                            </select>
                            {form.errors.company_id ? <div className="text-xs font-medium text-destructive">{form.errors.company_id}</div> : null}
                        </div>

                        <div className="grid grid-cols-2 gap-4">
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
                                    disabled={Boolean(role?.is_system)}
                                />
                                {form.errors.name ? <div className="text-xs font-medium text-destructive">{form.errors.name}</div> : null}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="slug" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                    Slug
                                </Label>
                                <Input
                                    id="slug"
                                    placeholder="admin"
                                    className="rounded-xl border-white/10 bg-white/5 focus-visible:ring-primary/40 h-11 transition-all"
                                    value={form.data.slug}
                                    onChange={(e) => form.setData('slug', e.target.value)}
                                    disabled={Boolean(role?.is_system)}
                                />
                                {form.errors.slug ? <div className="text-xs font-medium text-destructive">{form.errors.slug}</div> : null}
                            </div>
                        </div>

                        <div className="flex items-center justify-between gap-3 rounded-xl border border-white/10 bg-white/5 px-4 py-3">
                            <div className="space-y-0.5">
                                <div className="text-sm font-bold">System role</div>
                                <div className="text-xs text-muted-foreground/80">System roles cannot be deleted.</div>
                            </div>
                            <Switch
                                checked={Boolean(form.data.is_system)}
                                onCheckedChange={(checked) => form.setData('is_system', checked)}
                                disabled={Boolean(role?.is_system)}
                            />
                        </div>
                        {form.errors.is_system ? <div className="text-xs font-medium text-destructive">{form.errors.is_system}</div> : null}

                        <div className="space-y-3">
                            <div className="flex items-center justify-between gap-3">
                                <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">Permissions</Label>
                                <Button
                                    type="button"
                                    variant="secondary"
                                    className="rounded-xl h-9 px-3 border border-white/5 bg-white/5 hover:bg-white/10"
                                    onClick={() => form.setData('permissions', defaultPermissions)}
                                    disabled={Boolean(role?.is_system)}
                                >
                                    Use defaults
                                </Button>
                            </div>

                            <div className="flex flex-wrap gap-2">
                                {permissions.length ? (
                                    permissions.map((p) => (
                                        <Badge
                                            key={p}
                                            variant="secondary"
                                            className="bg-white/5 text-muted-foreground border-white/10 text-[10px] uppercase font-bold tracking-wider cursor-pointer hover:bg-white/10"
                                            onClick={() => {
                                                if (role?.is_system) {
                                                    return;
                                                }

                                                form.setData('permissions', permissions.filter((x) => x !== p));
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
                                        if (role?.is_system) {
                                            return;
                                        }

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
                                    disabled={Boolean(role?.is_system)}
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
                        disabled={form.processing || Boolean(role?.is_system)}
                        title={role?.is_system ? 'System roles cannot be edited' : undefined}
                    >
                        {role ? 'Save' : 'Create'}
                    </Button>
                </div>
            </SheetContent>
        </Sheet>
    );
}

