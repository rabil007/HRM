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
import type { Branch } from '../types';

export function BranchCard({
    branch,
    onEdit,
    onDelete,
}: {
    branch: Branch;
    onEdit: (branch: Branch) => void;
    onDelete: (branch: Branch) => void;
}) {
    const statusLabel = branch.status ?? '—';
    const statusClass =
        statusLabel === 'active'
            ? 'bg-emerald-500/10 text-emerald-200 border-emerald-500/20'
            : statusLabel === 'inactive'
              ? 'bg-zinc-500/10 text-zinc-200 border-zinc-500/20'
              : 'bg-white/5 text-muted-foreground border-white/10';

    const copy = async (value: string) => {
        try {
            await navigator.clipboard.writeText(value);
        } catch {
            return;
        }
    };

    return (
        <Card className="group border-white/5 bg-linear-to-br from-white/6 to-white/3 backdrop-blur-xl hover:from-white/8 hover:to-white/4 transition-all duration-300 overflow-hidden relative">
            <a href={`/organization/branches/${branch.id}`} className="absolute inset-0" aria-label="View branch details" />

            <CardHeader className="pb-3">
                <div className="flex items-start gap-4">
                    <div className="h-12 w-12 rounded-2xl bg-white/6 flex items-center justify-center border border-white/10 text-foreground/80 shrink-0">
                        <Store className="h-6 w-6" />
                    </div>

                    <div className="min-w-0 flex-1">
                        <div className="flex items-center justify-between gap-3">
                            <CardTitle className="text-lg font-extrabold tracking-tight line-clamp-1">{branch.name}</CardTitle>
                            <div className="flex items-center gap-1.5 relative z-10">
                                <Badge className={`text-[10px] uppercase font-bold tracking-wider border ${statusClass}`}>{statusLabel}</Badge>
                            </div>
                        </div>

                        <div className="mt-2 flex flex-wrap gap-2">
                            <Badge variant="secondary" className="bg-white/5 text-muted-foreground border-white/10 text-[10px] uppercase font-bold tracking-wider">
                                {branch.code || '—'}
                            </Badge>
                            {branch.is_headquarters ? (
                                <Badge className="text-[10px] uppercase font-bold tracking-wider border bg-primary/10 text-primary border-primary/20">
                                    HQ
                                </Badge>
                            ) : null}
                            <Badge variant="secondary" className="bg-white/5 text-muted-foreground border-white/10 text-[10px] uppercase font-bold tracking-wider">
                                {branch.company.name ?? '—'}
                            </Badge>
                        </div>

                        <CardDescription className="mt-3 flex items-center gap-2 text-sm font-medium text-muted-foreground/85">
                            <MapPin className="h-4 w-4" />
                            <span className="truncate">{[branch.city, branch.country].filter(Boolean).join(', ') || '—'}</span>
                        </CardDescription>
                    </div>
                </div>
            </CardHeader>

            <CardContent className="pt-0">
                <div className="grid gap-2">
                    <div className="flex items-center justify-between gap-2 rounded-xl border border-white/6 bg-white/4 px-3 py-2 relative z-10">
                        <div className="text-xs font-semibold text-muted-foreground/80">Quick actions</div>
                        <div className="flex items-center gap-1">
                            <Button
                                asChild
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="h-9 w-9 rounded-lg hover:bg-white/10"
                                title="View"
                            >
                                <a href={`/organization/branches/${branch.id}`} onClick={(e) => e.stopPropagation()}>
                                    <Eye className="h-4 w-4" />
                                </a>
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="h-9 w-9 rounded-lg hover:bg-white/10"
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
                                className="h-9 w-9 rounded-lg hover:bg-destructive/10 text-destructive hover:text-destructive"
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

                    <div className="flex items-center justify-between gap-3 rounded-xl border border-white/6 bg-white/4 px-3 py-2">
                        <div className="flex items-center gap-2 text-xs font-semibold text-muted-foreground/80">
                            <IdCard className="h-4 w-4" />
                            ID
                        </div>
                        <div className="text-sm font-bold tabular-nums">#{String(branch.id).padStart(4, '0')}</div>
                    </div>

                    {branch.phone ? (
                        <div className="flex items-center justify-between gap-2 rounded-xl border border-white/6 bg-white/4 px-3 py-2">
                            <a
                                href={`tel:${branch.phone}`}
                                className="min-w-0 flex items-center gap-2 text-sm font-medium text-foreground/90 hover:text-primary transition-colors relative z-10"
                                onClick={(e) => e.stopPropagation()}
                            >
                                <Phone className="h-4 w-4 text-muted-foreground" />
                                <span className="truncate">{branch.phone}</span>
                            </a>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="h-9 w-9 rounded-lg hover:bg-white/10 relative z-10"
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
                        <div className="flex items-center justify-between gap-2 rounded-xl border border-white/6 bg-white/4 px-3 py-2">
                            <a
                                href={`mailto:${branch.email}`}
                                className="min-w-0 flex items-center gap-2 text-sm font-medium text-foreground/90 hover:text-primary transition-colors relative z-10"
                                onClick={(e) => e.stopPropagation()}
                            >
                                <Mail className="h-4 w-4 text-muted-foreground" />
                                <span className="truncate">{branch.email}</span>
                            </a>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="h-9 w-9 rounded-lg hover:bg-white/10 relative z-10"
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
        </Card>
    );
}

