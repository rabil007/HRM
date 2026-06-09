import { ClipboardList, Pencil, Trash2 } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import type { EmployeeProfileTemplate } from '../types';

export function TemplateCard({
    template,
    onDelete,
}: {
    template: EmployeeProfileTemplate;
    onDelete: (template: EmployeeProfileTemplate) => void;
}) {
    const statusClass = template.is_active
        ? 'bg-emerald-500/10 text-emerald-700 border-emerald-500/20 dark:text-emerald-200'
        : 'bg-muted/60 text-muted-foreground border-border dark:bg-zinc-500/10 dark:text-zinc-200 dark:border-zinc-500/20';

    const editHref = `/organization/templates/employee-profile/${template.id}/edit`;

    return (
        <Card className="glass-card group relative overflow-hidden transition-all duration-300 dark:bg-linear-to-br dark:from-white/6 dark:to-white/3 dark:hover:from-white/8 dark:hover:to-white/4">
            <a href={editHref} className="absolute inset-0" aria-label={`Edit ${template.name}`} />

            <CardHeader className="pb-3">
                <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                        <div className="flex items-center gap-2">
                            <ClipboardList className="h-4 w-4 shrink-0 text-primary/80" />
                            <CardTitle className="line-clamp-1 text-lg font-extrabold tracking-tight">
                                {template.name}
                            </CardTitle>
                        </div>
                        <CardDescription className="mt-2 line-clamp-2 text-sm font-medium text-muted-foreground/85">
                            {template.description?.trim() || 'No description'}
                        </CardDescription>
                    </div>
                    <Badge
                        className={`border text-[10px] font-bold tracking-wider uppercase ${statusClass}`}
                    >
                        {template.is_active ? 'active' : 'inactive'}
                    </Badge>
                </div>
            </CardHeader>

            <CardContent className="pt-0">
                <div className="grid gap-2 pb-12">
                    <div className="flex items-center justify-between gap-3 rounded-xl border border-border/60 bg-muted/30 px-3 py-2 dark:border-white/6 dark:bg-white/4">
                        <div className="text-xs font-semibold text-muted-foreground/80">ID</div>
                        <div className="text-sm font-bold tabular-nums">
                            #{String(template.id).padStart(4, '0')}
                        </div>
                    </div>
                </div>
            </CardContent>

            <div className="pointer-events-none absolute right-4 bottom-4 left-4">
                <div className="pointer-events-auto flex items-center justify-end gap-1 rounded-xl border border-border/60 bg-muted/30 p-1.5 backdrop-blur-xl dark:border-white/6 dark:bg-white/4">
                    <Button
                        asChild
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="h-8 w-8 rounded-lg hover:bg-accent dark:hover:bg-white/10"
                        title="Edit"
                    >
                        <a href={editHref} onClick={(e) => e.stopPropagation()}>
                            <Pencil className="h-4 w-4" />
                        </a>
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="h-8 w-8 rounded-lg text-destructive hover:bg-destructive/10 hover:text-destructive"
                        onClick={(e) => {
                            e.stopPropagation();
                            onDelete(template);
                        }}
                        title="Delete"
                    >
                        <Trash2 className="h-4 w-4" />
                    </Button>
                </div>
            </div>
        </Card>
    );
}
