import { router } from '@inertiajs/react';
import { Cake, Clock, Edit2, Mail, Phone, Trash2, User2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import type { Employee } from '../types';

function getBadgeColor(text: string) {
    const colors = [
        'bg-emerald-600/90 text-white',
        'bg-blue-600/90 text-white',
        'bg-purple-600/90 text-white',
        'bg-amber-600/90 text-white',
        'bg-rose-600/90 text-white',
        'bg-cyan-600/90 text-white',
        'bg-indigo-600/90 text-white',
    ];
    if (!text || text === 'No Position') return 'bg-zinc-700/90 text-zinc-100';
    const hash = text.split('').reduce((acc, char) => acc + char.charCodeAt(0), 0);
    return colors[hash % colors.length];
}

export function EmployeeCard({
    employee,
    onEdit,
    onDelete,
}: {
    employee: Employee;
    onEdit: (employee: Employee) => void;
    onDelete: (employee: Employee) => void;
    onToggleStatus: (employee: Employee, enabled: boolean) => void;
}) {
    const imageSrc = employee.image
        ? employee.image.startsWith('http')
            ? employee.image
            : `/storage/${employee.image.replace(/^\/+/, '')}`
        : null;

    const positionTitle = employee.position?.title ?? 'No Position';
    const badgeColor = getBadgeColor(positionTitle);

    return (
        <Card 
            className="group overflow-hidden relative cursor-pointer border-white/5 hover:border-white/10 transition-all duration-200 h-[140px] bg-white/5 shadow-none"
            onClick={() => router.visit(`/organization/employees/${employee.id}`)}
        >
            <div className="flex h-full">
                {/* Left Side: Image */}
                <div className="w-[110px] shrink-0 bg-white/5 relative border-r border-white/5">
                    {imageSrc ? (
                        <img src={imageSrc} alt={employee.name} className="h-full w-full object-cover" />
                    ) : (
                        <div className="h-full w-full flex items-center justify-center text-muted-foreground">
                            <User2 className="h-10 w-10 opacity-30" />
                        </div>
                    )}
                </div>

                {/* Right Side: Info */}
                <div className="flex-1 p-3 flex flex-col justify-between min-w-0">
                    <div className="min-w-0">
                        {/* Header: Name and Actions */}
                        <div className="flex items-start justify-between gap-1">
                            <div className="font-bold text-sm tracking-tight truncate uppercase text-foreground/90 leading-tight pt-0.5" title={employee.name}>
                                {employee.name}
                            </div>
                            
                            {/* Hover Actions */}
                            <div className="opacity-0 group-hover:opacity-100 transition-opacity flex items-center shrink-0 -mt-1.5 -mr-1.5 bg-background/50 backdrop-blur-sm rounded shadow-sm">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="h-7 w-7 rounded hover:bg-white/10"
                                    onClick={(e) => { 
                                        e.stopPropagation(); 
                                        onEdit(employee); 
                                    }}
                                >
                                    <Edit2 className="h-3 w-3" />
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="h-7 w-7 rounded text-destructive hover:bg-destructive/10"
                                    onClick={(e) => { 
                                        e.stopPropagation(); 
                                        onDelete(employee); 
                                    }}
                                >
                                    <Trash2 className="h-3 w-3" />
                                </Button>
                            </div>
                        </div>

                        {/* ID Badge */}
                        <div className="mt-1 flex items-center justify-between">
                            <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold bg-white/10 text-muted-foreground/90">
                                {employee.employee_no}
                            </span>
                            
                            {/* Status indicator dot */}
                            <div 
                                className={`h-2.5 w-2.5 rounded-full ${
                                    employee.status === 'active' ? 'bg-emerald-500' : 
                                    employee.status === 'on_leave' ? 'bg-amber-500' : 
                                    employee.status === 'inactive' ? 'bg-zinc-500' : 'bg-rose-500'
                                }`} 
                                title={`Status: ${employee.status}`}
                            />
                        </div>

                        {/* Details List */}
                        <div className="space-y-1.5 mt-2.5">
                            <div className="flex items-center gap-2 text-xs text-muted-foreground/80 truncate" title={employee.work_email ?? ''}>
                                <Mail className="h-3.5 w-3.5 shrink-0 opacity-60 text-rose-400" />
                                <span className="truncate">{employee.work_email ?? '—'}</span>
                            </div>
                            <div className="flex items-center gap-2 text-xs text-muted-foreground/80 truncate" title={employee.phone ?? ''}>
                                <Phone className="h-3.5 w-3.5 shrink-0 opacity-60 text-emerald-400" />
                                <span className="truncate">{employee.phone ?? '—'}</span>
                            </div>
                        </div>
                    </div>

                    {/* Footer: Position and Clock */}
                    <div className="flex items-end justify-between pt-2">
                        <div className={`inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-semibold max-w-[85%] truncate ${badgeColor}`}>
                            <span className="truncate">{positionTitle}</span>
                        </div>
                        <Clock className="h-3.5 w-3.5 text-muted-foreground/40 shrink-0 mb-0.5" />
                    </div>
                </div>
            </div>
        </Card>
    );
}
