import type { InertiaFormProps } from '@inertiajs/react';
import { Copy } from 'lucide-react';
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
import type { Role } from '../types';

export type RoleDuplicateFormData = {
    name: string;
};

function roleScopeLabel(role: Role): string {
    return role.employee_visibility_scope === 'selected_departments'
        ? 'Selected departments'
        : 'All departments';
}

export function suggestedDuplicateRoleName(role: Role): string {
    return `${role.name} Copy`;
}

export function RoleDuplicateDialog({
    open,
    onOpenChange,
    role,
    form,
    onSubmit,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    role: Role | null;
    form: InertiaFormProps<RoleDuplicateFormData>;
    onSubmit: () => void;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <div className="mb-1 flex h-10 w-10 items-center justify-center rounded-xl border border-primary/20 bg-primary/10 text-primary">
                        <Copy className="h-5 w-5" />
                    </div>
                    <DialogTitle>Duplicate Role</DialogTitle>
                    <DialogDescription>
                        Permissions and employee access scope will be copied.
                        Assigned users will not be copied.
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-4">
                    {role ? (
                        <div className="rounded-xl border border-border/70 bg-muted/30 px-3 py-2 text-sm font-medium text-muted-foreground dark:border-white/10 dark:bg-white/5">
                            {role.permissions.length} permissions •{' '}
                            {roleScopeLabel(role)}
                        </div>
                    ) : null}

                    <div className="space-y-2">
                        <Label
                            htmlFor="duplicate-role-name"
                            className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                        >
                            New role name
                        </Label>
                        <Input
                            id="duplicate-role-name"
                            value={form.data.name}
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                            placeholder={
                                role ? suggestedDuplicateRoleName(role) : ''
                            }
                            className="h-11 rounded-xl border-border bg-card transition-all focus-visible:ring-primary/40"
                            autoFocus
                        />
                        {form.errors.name ? (
                            <div className="text-xs font-medium text-destructive">
                                {form.errors.name}
                            </div>
                        ) : null}
                    </div>
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        className="rounded-xl px-5 text-muted-foreground"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        className="rounded-xl px-5 font-semibold"
                        onClick={onSubmit}
                        disabled={form.processing}
                    >
                        Duplicate Role
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
