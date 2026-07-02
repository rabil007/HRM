import {
    Clipboard,
    Edit2,
    Eye,
    IdCard,
    Mail,
    MapPin,
    Phone,
    Store,
    Trash2,
} from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Switch } from '@/components/ui/switch';
import type { Branch } from '../types';

export function BranchCard({
    branch,
    onEdit,
    onDelete,
    onToggleStatus,
}: {
    branch: Branch;
    onEdit: (branch: Branch) => void;
    onDelete: (branch: Branch) => void;
    onToggleStatus: (branch: Branch, enabled: boolean) => void;
}) {
    const statusLabel = branch.status ?? '—';
    const statusClass =
        statusLabel === 'active'
            ? 'bg-emerald-500/10 text-emerald-700 border-emerald-500/20 dark:text-emerald-200'
            : branch.status === 'inactive'
              ? 'bg-muted/60 text-muted-foreground border-border dark:bg-zinc-500/10 dark:text-zinc-200 dark:border-zinc-500/20'
              : 'bg-muted/40 text-muted-foreground border-border/60 dark:bg-white/5 dark:border-white/10';

    const copy = async (value: string) => {
        try {
            await navigator.clipboard.writeText(value);
        } catch {
            return;
        }
    };

    return (
        <Card className="group relative overflow-hidden glass-card transition-all duration-300 dark:bg-linear-to-br dark:from-white/6 dark:to-white/3 dark:hover:from-white/8 dark:hover:to-white/4">
            <a
                href={`/organization/branches/${branch.id}`}
                className="absolute inset-0"
                aria-label="View branch details"
            />

            <CardHeader className="pb-3">
                <div className="flex items-start gap-4">
                    <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl border border-border/60 bg-muted/40 text-foreground/80 dark:border-white/10 dark:bg-white/6">
                        <Store className="h-6 w-6" />
                    </div>

                    <div className="min-w-0 flex-1">
                        <div className="flex items-center justify-between gap-3">
                            <CardTitle className="line-clamp-1 text-lg font-extrabold tracking-tight">
                                {branch.name}
                            </CardTitle>
                            <div className="relative z-10 flex items-center gap-1.5">
                                <Badge
                                    className={`border text-[10px] font-bold tracking-wider uppercase ${statusClass}`}
                                >
                                    {statusLabel}
                                </Badge>
                            </div>
                        </div>

                        <div className="mt-2 flex flex-wrap gap-2">
                            <Badge
                                variant="secondary"
                                className="border-border/60 bg-muted/40 text-[10px] font-bold tracking-wider text-muted-foreground uppercase dark:border-white/10 dark:bg-white/5"
                            >
                                {branch.code || '—'}
                            </Badge>
                            {branch.is_headquarters ? (
                                <Badge className="border border-primary/20 bg-primary/10 text-[10px] font-bold tracking-wider text-primary uppercase">
                                    HQ
                                </Badge>
                            ) : null}
                            <Badge
                                variant="secondary"
                                className="border-border/60 bg-muted/40 text-[10px] font-bold tracking-wider text-muted-foreground uppercase dark:border-white/10 dark:bg-white/5"
                            >
                                {branch.company.name ?? '—'}
                            </Badge>
                        </div>

                        <CardDescription className="mt-3 flex items-center gap-2 text-sm font-medium text-muted-foreground/85">
                            <MapPin className="h-4 w-4" />
                            <span className="truncate">
                                {[branch.city, branch.country]
                                    .filter(Boolean)
                                    .join(', ') || '—'}
                            </span>
                        </CardDescription>
                    </div>
                </div>
            </CardHeader>

            <CardContent className="pt-0">
                <div className="grid gap-2 pb-12">
                    <div className="flex items-center justify-between gap-3 rounded-xl border border-border/60 bg-muted/30 px-3 py-2 dark:border-white/6 dark:bg-white/4">
                        <div className="flex items-center gap-2 text-xs font-semibold text-muted-foreground/80">
                            <IdCard className="h-4 w-4" />
                            ID
                        </div>
                        <div className="text-sm font-bold tabular-nums">
                            #{String(branch.id).padStart(4, '0')}
                        </div>
                    </div>

                    {branch.phone ? (
                        <div className="flex items-center justify-between gap-2 rounded-xl border border-border/60 bg-muted/30 px-3 py-2 dark:border-white/6 dark:bg-white/4">
                            <a
                                href={`tel:${branch.phone}`}
                                className="relative z-10 flex min-w-0 items-center gap-2 text-sm font-medium text-foreground/90 transition-colors hover:text-primary"
                                onClick={(e) => e.stopPropagation()}
                            >
                                <Phone className="h-4 w-4 text-muted-foreground" />
                                <span className="truncate">{branch.phone}</span>
                            </a>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="relative z-10 h-9 w-9 rounded-lg hover:bg-accent dark:hover:bg-white/10"
                                onClick={(e) => {
                                    e.stopPropagation();
                                    void copy(branch.phone ?? '');
                                }}
                                title="Copy phone"
                            >
                                <Clipboard className="h-4 w-4" />
                            </Button>
                        </div>
                    ) : null}

                    {branch.email ? (
                        <div className="flex items-center justify-between gap-2 rounded-xl border border-border/60 bg-muted/30 px-3 py-2 dark:border-white/6 dark:bg-white/4">
                            <a
                                href={`mailto:${branch.email}`}
                                className="relative z-10 flex min-w-0 items-center gap-2 text-sm font-medium text-foreground/90 transition-colors hover:text-primary"
                                onClick={(e) => e.stopPropagation()}
                            >
                                <Mail className="h-4 w-4 text-muted-foreground" />
                                <span className="truncate">{branch.email}</span>
                            </a>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="relative z-10 h-9 w-9 rounded-lg hover:bg-accent dark:hover:bg-white/10"
                                onClick={(e) => {
                                    e.stopPropagation();
                                    void copy(branch.email ?? '');
                                }}
                                title="Copy email"
                            >
                                <Clipboard className="h-4 w-4" />
                            </Button>
                        </div>
                    ) : null}
                </div>
            </CardContent>

            <div className="pointer-events-none absolute right-4 bottom-4 left-4">
                <div className="pointer-events-auto flex items-center justify-between gap-2 rounded-xl border border-border/60 bg-muted/30 p-1.5 backdrop-blur-xl dark:border-white/6 dark:bg-white/4">
                    <div
                        className="flex items-center gap-2 pl-1.5"
                        onClick={(e) => e.stopPropagation()}
                    >
                        <Switch
                            checked={branch.status === 'active'}
                            onCheckedChange={(checked) =>
                                onToggleStatus(branch, checked)
                            }
                        />
                        <span className="text-[10px] font-bold tracking-[0.2em] text-muted-foreground/70 uppercase">
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
                            <a
                                href={`/organization/branches/${branch.id}`}
                                onClick={(e) => e.stopPropagation()}
                            >
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
                                onEdit(branch);
                            }}
                            title="Edit"
                        >
                            <Edit2 className="h-4 w-4" />
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="h-8 w-8 rounded-lg text-destructive hover:bg-destructive/10 hover:text-destructive"
                            onClick={(e) => {
                                e.stopPropagation();
                                onDelete(branch);
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
