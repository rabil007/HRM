import type { InertiaFormProps } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import type { DepartmentOption, Position, PositionFormData } from '../types';

export function PositionFormSheet({
    open,
    onOpenChange,
    position,
    departments,
    form,
    onSubmit,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    position: Position | null;
    departments: DepartmentOption[];
    form: InertiaFormProps<PositionFormData>;
    onSubmit: () => void;
}) {
    const availableDepartments = departments ?? [];

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent side="right" className="w-full sm:max-w-md border-white/5 bg-black/60 backdrop-blur-3xl p-0 flex flex-col">
                <SheetHeader className="p-8 pb-6 border-b border-white/5">
                    <SheetTitle className="text-xl font-bold tracking-tight text-white">{position ? 'Edit Position' : 'New Position'}</SheetTitle>
                    <SheetDescription className="text-sm text-muted-foreground/80 mt-1">
                        {position ? 'Update position details.' : 'Add a new position.'}
                    </SheetDescription>
                </SheetHeader>

                <div className="flex-1 overflow-y-auto p-8 space-y-8">
                    <div className="space-y-5">
                        <div className="space-y-2">
                            <Label htmlFor="department_id" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                Department (optional)
                            </Label>
                            <select
                                id="department_id"
                                className="w-full rounded-xl border border-white/10 bg-white/5 h-11 px-3 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-primary/40 transition-all"
                                value={form.data.department_id}
                                onChange={(e) => form.setData('department_id', e.target.value ? Number(e.target.value) : '')}
                            >
                                <option value="">All departments</option>
                                {availableDepartments.map((d) => (
                                    <option key={d.id} value={d.id}>
                                        {d.name}
                                    </option>
                                ))}
                            </select>
                            {form.errors.department_id ? <div className="text-xs font-medium text-destructive">{form.errors.department_id}</div> : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="title" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                Title
                            </Label>
                            <Input
                                id="title"
                                placeholder="Software Engineer"
                                className="rounded-xl border-white/10 bg-white/5 focus-visible:ring-primary/40 h-11 transition-all"
                                value={form.data.title}
                                onChange={(e) => form.setData('title', e.target.value)}
                            />
                            {form.errors.title ? <div className="text-xs font-medium text-destructive">{form.errors.title}</div> : null}
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <Label htmlFor="status" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                    Status
                                </Label>
                                <select
                                    id="status"
                                    className="w-full rounded-xl border border-white/10 bg-white/5 h-11 px-3 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-primary/40 transition-all"
                                    value={form.data.status}
                                    onChange={(e) => form.setData('status', e.target.value as 'active' | 'inactive')}
                                >
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                                {form.errors.status ? <div className="text-xs font-medium text-destructive">{form.errors.status}</div> : null}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="grade" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                    Grade (optional)
                                </Label>
                                <Input
                                    id="grade"
                                    placeholder="G5"
                                    className="rounded-xl border-white/10 bg-white/5 focus-visible:ring-primary/40 h-11 transition-all"
                                    value={form.data.grade}
                                    onChange={(e) => form.setData('grade', e.target.value)}
                                />
                                {form.errors.grade ? <div className="text-xs font-medium text-destructive">{form.errors.grade}</div> : null}
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <Label htmlFor="min_salary" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                    Min salary
                                </Label>
                                <Input
                                    id="min_salary"
                                    inputMode="decimal"
                                    placeholder="0"
                                    className="rounded-xl border-white/10 bg-white/5 focus-visible:ring-primary/40 h-11 transition-all"
                                    value={form.data.min_salary}
                                    onChange={(e) => form.setData('min_salary', e.target.value)}
                                />
                                {form.errors.min_salary ? <div className="text-xs font-medium text-destructive">{form.errors.min_salary}</div> : null}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="max_salary" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                    Max salary
                                </Label>
                                <Input
                                    id="max_salary"
                                    inputMode="decimal"
                                    placeholder="0"
                                    className="rounded-xl border-white/10 bg-white/5 focus-visible:ring-primary/40 h-11 transition-all"
                                    value={form.data.max_salary}
                                    onChange={(e) => form.setData('max_salary', e.target.value)}
                                />
                                {form.errors.max_salary ? <div className="text-xs font-medium text-destructive">{form.errors.max_salary}</div> : null}
                            </div>
                        </div>
                    </div>
                </div>

                <div className="p-6 border-t border-white/5 bg-black/20 flex gap-3">
                    <Button type="button" variant="ghost" className="rounded-xl h-11 px-6 text-muted-foreground flex-1" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button className="rounded-xl h-11 px-6 flex-1 font-semibold" type="button" onClick={onSubmit} disabled={form.processing}>
                        {position ? 'Save' : 'Create'}
                    </Button>
                </div>
            </SheetContent>
        </Sheet>
    );
}

