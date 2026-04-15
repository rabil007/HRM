import { Eye, Pencil, Trash2 } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Switch } from '@/components/ui/switch';
import type { Position } from '../types';

export function PositionCard({
    position,
    onEdit,
    onDelete,
    onToggleStatus,
}: {
    position: Position;
    onEdit: (position: Position) => void;
    onDelete: (position: Position) => void;
    onToggleStatus: (position: Position, enabled: boolean) => void;
}) {
    const statusClass =
        position.status === 'active'
            ? 'bg-emerald-500/10 text-emerald-200 border-emerald-500/20'
            : 'bg-zinc-500/10 text-zinc-200 border-zinc-500/20';

    return (
        <Card className="glass-card group overflow-hidden relative transition-all duration-300 dark:bg-linear-to-br dark:from-white/6 dark:to-white/3 dark:hover:from-white/8 dark:hover:to-white/4">
            <a href={`/organization/positions/${position.id}`} className="absolute inset-0" aria-label="View position details" />

            <CardHeader className="pb-3">
                <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                        <CardTitle className="text-lg font-extrabold tracking-tight line-clamp-1">{position.title}</CardTitle>
                        <CardDescription className="mt-2 text-sm font-medium text-muted-foreground/85">
                            {position.company.name ?? '—'}
                            {position.department?.name ? ` • ${position.department.name}` : ''}
                        </CardDescription>
                        <div className="mt-3 flex flex-wrap gap-2">
                            {position.grade ? (
                                <Badge variant="secondary" className="bg-muted/40 text-muted-foreground border-border/60 text-[10px] uppercase font-bold tracking-wider dark:bg-white/5 dark:border-white/10">
                                    {position.grade}
                                </Badge>
                            ) : null}
                            {position.min_salary || position.max_salary ? (
                                <Badge variant="secondary" className="bg-muted/40 text-muted-foreground border-border/60 text-[10px] uppercase font-bold tracking-wider dark:bg-white/5 dark:border-white/10">
                                    {position.min_salary ?? '—'} - {position.max_salary ?? '—'}
                                </Badge>
                            ) : null}
                        </div>
                    </div>
                    <Badge className={`text-[10px] uppercase font-bold tracking-wider border ${statusClass}`}>{position.status}</Badge>
                </div>
            </CardHeader>

            <CardContent className="pt-0">
                <div className="grid gap-2 pb-12">
                    <div className="flex items-center justify-between gap-3 rounded-xl border border-border/60 bg-muted/30 px-3 py-2 dark:border-white/6 dark:bg-white/4">
                        <div className="text-xs font-semibold text-muted-foreground/80">ID</div>
                        <div className="text-sm font-bold tabular-nums">#{String(position.id).padStart(4, '0')}</div>
                    </div>
                </div>
            </CardContent>

            <div className="pointer-events-none absolute bottom-4 left-4 right-4">
                <div className="pointer-events-auto flex items-center justify-between gap-2 rounded-xl border border-border/60 bg-muted/30 backdrop-blur-xl p-1.5 dark:border-white/6 dark:bg-white/4">
                    <div className="flex items-center gap-2 pl-1.5" onClick={(e) => e.stopPropagation()}>
                        <Switch checked={position.status === 'active'} onCheckedChange={(checked) => onToggleStatus(position, checked)} />
                        <span className="text-[10px] font-bold uppercase tracking-[0.2em] text-muted-foreground/70">
                            Active
                        </span>
                    </div>

                    <div className="flex items-center justify-end gap-1">
                    <Button
                        asChild
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="h-8 w-8 rounded-lg hover:bg-accent dark:hover:bg-white/10"
                        title="View"
                    >
                        <a href={`/organization/positions/${position.id}`} onClick={(e) => e.stopPropagation()}>
                            <Eye className="h-4 w-4" />
                        </a>
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="h-8 w-8 rounded-lg hover:bg-accent dark:hover:bg-white/10"
                        onClick={(e) => {
                            e.stopPropagation();
                            onEdit(position);
                        }}
                        title="Edit"
                    >
                        <Pencil className="h-4 w-4" />
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="h-8 w-8 rounded-lg hover:bg-destructive/10 text-destructive hover:text-destructive"
                        onClick={(e) => {
                            e.stopPropagation();
                            onDelete(position);
                        }}
                        title="Delete"
                    >
                        <Trash2 className="h-4 w-4" />
                    </Button>
                    </div>
                </div>
            </div>
        </Card>
    );
}

