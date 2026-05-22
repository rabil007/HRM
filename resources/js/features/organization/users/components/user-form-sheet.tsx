import type { InertiaFormProps } from '@inertiajs/react';
import { ImageDown } from 'lucide-react';
import { useId, useMemo } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
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
    const employeePhoto = user?.linked_employee?.image_url ?? null;
    const canUseEmployeePhoto = Boolean(user && employeePhoto);

    const avatarLabel = useMemo(() => {
        if (form.data.use_employee_avatar && employeePhoto) {
            return `Employee photo (${user?.linked_employee?.name ?? 'linked'})`;
        }

        const name = form.data.avatar?.name?.trim();

        return name ? name : 'No file selected';
    }, [employeePhoto, form.data.avatar, form.data.use_employee_avatar, user?.linked_employee?.name]);

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
                                <AppSelect
                                    value={form.data.status}
                                    onValueChange={(v) => form.setData('status', v as UserFormData['status'])}
                                    variant="card"
                                >
                                    <AppSelectItem value="active">Active</AppSelectItem>
                                    <AppSelectItem value="inactive">Inactive</AppSelectItem>
                                    <AppSelectItem value="suspended">Suspended</AppSelectItem>
                                </AppSelect>
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
                                        form.setData((data) => ({
                                            ...data,
                                            avatar: file,
                                            use_employee_avatar: false,
                                        }));
                                    }}
                                />
                                <div className="flex flex-col gap-2">
                                    <div className="flex items-center gap-3">
                                        <Button
                                            asChild
                                            type="button"
                                            variant="secondary"
                                            className="glass-card rounded-xl h-11 px-4 hover:bg-accent"
                                        >
                                            <label htmlFor={avatarId}>Upload</label>
                                        </Button>
                                        {canUseEmployeePhoto ? (
                                            <Button
                                                type="button"
                                                variant={form.data.use_employee_avatar ? 'default' : 'outline'}
                                                className="h-11 shrink-0 rounded-xl px-4"
                                                onClick={() => {
                                                    form.setData((data) => ({
                                                        ...data,
                                                        avatar: null,
                                                        use_employee_avatar: !data.use_employee_avatar,
                                                    }));
                                                }}
                                            >
                                                <ImageDown className="size-4" />
                                                <span className="hidden sm:inline">Use employee photo</span>
                                            </Button>
                                        ) : null}
                                        <div className="min-w-0 flex-1 rounded-xl border border-border bg-card h-11 px-3 text-sm flex items-center text-muted-foreground/80">
                                            <span className="truncate">{avatarLabel}</span>
                                        </div>
                                    </div>
                                    {form.data.use_employee_avatar && employeePhoto ? (
                                        <div className="flex items-center gap-3 rounded-xl border border-border/80 bg-muted/20 p-2">
                                            <img
                                                src={employeePhoto}
                                                alt={user?.linked_employee?.name ?? 'Employee'}
                                                className="size-12 rounded-lg object-cover"
                                            />
                                            <p className="text-xs text-muted-foreground">
                                                Photo from linked employee{' '}
                                                <span className="font-medium text-foreground">{user?.linked_employee?.name}</span>
                                                {user?.linked_employee?.employee_no
                                                    ? ` (${user.linked_employee.employee_no})`
                                                    : null}{' '}
                                                will be copied when you save.
                                            </p>
                                        </div>
                                    ) : null}
                                </div>
                                {form.errors.avatar ? <div className="text-xs font-medium text-destructive">{form.errors.avatar}</div> : null}
                            </div>
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="role_id" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                Role (optional)
                            </Label>
                            <AppSelect
                                value={String(form.data.role_id ?? '')}
                                onValueChange={(v) => form.setData('role_id', v ? Number(v) : '')}
                                variant="card"
                                placeholder="No role"
                            >
                                <AppSelectItem value="">No role</AppSelectItem>
                                {roles.map((r) => (
                                    <AppSelectItem key={r.id} value={String(r.id)}>
                                        {r.name}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
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

