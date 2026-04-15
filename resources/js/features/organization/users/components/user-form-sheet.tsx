import type { InertiaFormProps } from '@inertiajs/react';
import { useId, useMemo } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import type { User, UserFormData } from '../types';

export function UserFormSheet({
    open,
    onOpenChange,
    user,
    roles,
    form,
    onSubmit,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    user: User | null;
    roles: { id: number; name: string }[];
    form: InertiaFormProps<UserFormData>;
    onSubmit: () => void;
}) {
    const avatarId = useId();
    const avatarLabel = useMemo(() => {
        const name = form.data.avatar?.name?.trim();

        return name ? name : 'No file selected';
    }, [form.data.avatar]);

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent side="right" className="w-full sm:max-w-lg p-0 flex flex-col glass-card rounded-none">
                <SheetHeader className="p-8 pb-6 border-b border-border/60">
                    <SheetTitle className="text-xl font-bold tracking-tight">{user ? 'Edit User' : 'New User'}</SheetTitle>
                    <SheetDescription className="text-sm text-muted-foreground/80 mt-1">
                        {user ? 'Update user profile and access.' : 'Create a new user.'}
                    </SheetDescription>
                </SheetHeader>

                <div className="flex-1 overflow-y-auto p-8 space-y-8">
                    <div className="space-y-5">
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <Label htmlFor="status" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                    Status
                                </Label>
                                <select
                                    id="status"
                                    className="w-full rounded-xl border border-border bg-card h-11 px-3 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-primary/40 transition-all"
                                    value={form.data.status}
                                    onChange={(e) => form.setData('status', e.target.value as UserFormData['status'])}
                                >
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                    <option value="suspended">Suspended</option>
                                </select>
                                {form.errors.status ? <div className="text-xs font-medium text-destructive">{form.errors.status}</div> : null}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor={avatarId} className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                    Avatar (optional)
                                </Label>
                                <Input
                                    id={avatarId}
                                    type="file"
                                    accept="image/*"
                                    className="sr-only"
                                    onChange={(e) => {
                                        const file = e.currentTarget.files?.[0] ?? null;
                                        form.setData('avatar', file);
                                    }}
                                />
                                <div className="flex items-center gap-3">
                                    <Button
                                        asChild
                                        type="button"
                                        variant="secondary"
                                        className="glass-card rounded-xl h-11 px-4 hover:bg-accent"
                                    >
                                        <label htmlFor={avatarId}>Upload</label>
                                    </Button>
                                    <div className="min-w-0 flex-1 rounded-xl border border-border bg-card h-11 px-3 text-sm flex items-center text-muted-foreground/80">
                                        <span className="truncate">{avatarLabel}</span>
                                    </div>
                                </div>
                                {form.errors.avatar ? <div className="text-xs font-medium text-destructive">{form.errors.avatar}</div> : null}
                            </div>
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="role_id" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                Role (optional)
                            </Label>
                            <select
                                id="role_id"
                                className="w-full rounded-xl border border-border bg-card h-11 px-3 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-primary/40 transition-all"
                                value={form.data.role_id}
                                onChange={(e) => form.setData('role_id', e.target.value ? Number(e.target.value) : '')}
                            >
                                <option value="">No role</option>
                                {roles.map((r) => (
                                    <option key={r.id} value={r.id}>
                                        {r.name}
                                    </option>
                                ))}
                            </select>
                            {form.errors.role_id ? <div className="text-xs font-medium text-destructive">{form.errors.role_id}</div> : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="name" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                Name
                            </Label>
                            <Input
                                id="name"
                                placeholder="John Doe"
                                className="rounded-xl border-border bg-card focus-visible:ring-primary/40 h-11 transition-all"
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                            />
                            {form.errors.name ? <div className="text-xs font-medium text-destructive">{form.errors.name}</div> : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="email" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                Email
                            </Label>
                            <Input
                                id="email"
                                placeholder="user@company.com"
                                className="rounded-xl border-border bg-card focus-visible:ring-primary/40 h-11 transition-all"
                                value={form.data.email}
                                onChange={(e) => form.setData('email', e.target.value)}
                            />
                            {form.errors.email ? <div className="text-xs font-medium text-destructive">{form.errors.email}</div> : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="password" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                {user ? 'Password (leave empty to keep)' : 'Password'}
                            </Label>
                            <Input
                                id="password"
                                type="password"
                                className="rounded-xl border-border bg-card focus-visible:ring-primary/40 h-11 transition-all"
                                value={form.data.password}
                                onChange={(e) => form.setData('password', e.target.value)}
                            />
                            {form.errors.password ? <div className="text-xs font-medium text-destructive">{form.errors.password}</div> : null}
                        </div>
                    </div>
                </div>

                <div className="p-6 border-t border-border/60 bg-background/40 flex gap-3">
                    <Button type="button" variant="ghost" className="rounded-xl h-11 px-6 text-muted-foreground flex-1" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button className="rounded-xl h-11 px-6 flex-1 font-semibold" type="button" onClick={onSubmit} disabled={form.processing}>
                        {user ? 'Save' : 'Create'}
                    </Button>
                </div>
            </SheetContent>
        </Sheet>
    );
}

