import { router } from '@inertiajs/react';
import { Edit2, Eye, IdCard, Mail, Phone, Trash2, User2 } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Switch } from '@/components/ui/switch';
import type { Employee } from '../types';

function statusBadgeClass(status: Employee['status']): string {
    if (status === 'active') {
        return 'bg-emerald-500/10 text-emerald-200 border border-emerald-500/20';
    }

    if (status === 'inactive') {
        return 'bg-zinc-500/10 text-zinc-200 border border-zinc-500/20';
    }

    if (status === 'on_leave') {
        return 'bg-amber-500/10 text-amber-200 border border-amber-500/20';
    }

    return 'bg-rose-500/10 text-rose-200 border border-rose-500/20';
}

export function EmployeeCard({
    employee,
    onEdit,
    onDelete,
    onToggleStatus,
}: {
    employee: Employee;
    onEdit: (employee: Employee) => void;
    onDelete: (employee: Employee) => void;
    onToggleStatus: (employee: Employee, enabled: boolean) => void;
}) {
    const canToggle = employee.status === 'active' || employee.status === 'inactive';

    return (
        <Card className="glass-card group overflow-hidden relative transition-all duration-300">
            <CardContent className="p-5 space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                        <div className="flex items-center gap-2">
                            <div className="h-10 w-10 rounded-2xl bg-muted/40 border border-border/60 flex items-center justify-center text-muted-foreground">
                                <User2 className="h-5 w-5" />
                            </div>
                            <div className="min-w-0">
                                <div className="font-bold tracking-tight truncate">{employee.name}</div>
                                <div className="mt-0.5 flex items-center gap-2 text-xs text-muted-foreground/80">
                                    <IdCard className="h-3.5 w-3.5" />
                                    <span className="truncate">{employee.employee_no}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <Badge className={statusBadgeClass(employee.status)}>{employee.status}</Badge>
                </div>

                <div className="grid gap-2 text-sm">
                    <div className="flex items-center justify-between gap-3">
                        <div className="text-muted-foreground/80">Branch</div>
                        <div className="font-medium truncate">{employee.branch?.name ?? '—'}</div>
                    </div>
                    <div className="flex items-center justify-between gap-3">
                        <div className="text-muted-foreground/80">Department</div>
                        <div className="font-medium truncate">{employee.department?.name ?? '—'}</div>
                    </div>
                    <div className="flex items-center justify-between gap-3">
                        <div className="text-muted-foreground/80">Position</div>
                        <div className="font-medium truncate">{employee.position?.title ?? '—'}</div>
                    </div>
                    <div className="flex items-center justify-between gap-3">
                        <div className="flex items-center gap-2 text-muted-foreground/80">
                            <Mail className="h-4 w-4" />
                            Email
                        </div>
                        <div className="font-medium truncate">{employee.work_email ?? '—'}</div>
                    </div>
                    <div className="flex items-center justify-between gap-3">
                        <div className="flex items-center gap-2 text-muted-foreground/80">
                            <Phone className="h-4 w-4" />
                            Phone
                        </div>
                        <div className="font-medium truncate">{employee.phone ?? '—'}</div>
                    </div>
                </div>

                <div className="pt-2 flex items-center justify-between gap-3 border-t border-border/60">
                    <div className="flex items-center gap-2">
                        {canToggle ? (
                            <div className="flex items-center gap-2">
                                <Switch
                                    checked={employee.status === 'active'}
                                    onCheckedChange={(checked) => onToggleStatus(employee, checked)}
                                />
                                <span className="text-xs text-muted-foreground/80">
                                    {employee.status === 'active' ? 'Active' : 'Inactive'}
                                </span>
                            </div>
                        ) : (
                            <span className="text-xs text-muted-foreground/80">Status managed in details</span>
                        )}
                    </div>

                    <div className="flex items-center gap-2">
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="h-9 w-9 rounded-xl hover:bg-accent"
                            onClick={() => router.visit(`/organization/employees/${employee.id}`)}
                            title="View"
                        >
                            <Eye className="h-4 w-4" />
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="h-9 w-9 rounded-xl hover:bg-accent"
                            onClick={() => onEdit(employee)}
                            title="Edit"
                        >
                            <Edit2 className="h-4 w-4" />
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="h-9 w-9 rounded-xl hover:bg-destructive/10 text-destructive hover:text-destructive"
                            onClick={() => onDelete(employee)}
                            title="Delete"
                        >
                            <Trash2 className="h-4 w-4" />
                        </Button>
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}

