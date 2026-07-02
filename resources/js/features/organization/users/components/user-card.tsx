import { Eye, Pencil, Trash2, User as UserIcon } from 'lucide-react';
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
import type { User } from '../types';

export function UserCard({
    user,
    onEdit,
    onDelete,
    onToggleStatus,
}: {
    user: User;
    onEdit: (user: User) => void;
    onDelete: (user: User) => void;
    onToggleStatus: (user: User, enabled: boolean) => void;
}) {
    const statusClass =
        user.status === 'active'
            ? 'bg-emerald-500/10 text-emerald-700 border-emerald-500/20 dark:text-emerald-200'
            : user.status === 'suspended'
              ? 'bg-amber-500/10 text-amber-700 border-amber-500/20 dark:text-amber-200'
              : 'bg-muted/60 text-muted-foreground border-border dark:bg-zinc-500/10 dark:text-zinc-200 dark:border-zinc-500/20';

    return (
        <Card className="group relative overflow-hidden glass-card transition-all duration-300 dark:bg-linear-to-br dark:from-white/6 dark:to-white/3 dark:hover:from-white/8 dark:hover:to-white/4">
            <a
                href={`/organization/users/${user.id}`}
                className="absolute inset-0"
                aria-label="View user details"
            />

            <CardHeader className="pb-3">
                <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                        <div className="flex items-center gap-2">
                            <div className="flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-border/60 bg-muted/40 text-foreground/80 dark:border-white/10 dark:bg-white/6">
                                {user.avatar ? (
                                    <img
                                        src={user.avatar}
                                        alt={user.name}
                                        className="h-full w-full object-cover"
                                        loading="lazy"
                                    />
                                ) : (
                                    <UserIcon className="h-4 w-4 text-primary/80" />
                                )}
                            </div>
                            <CardTitle className="line-clamp-1 text-lg font-extrabold tracking-tight">
                                {user.name}
                            </CardTitle>
                        </div>
                        <CardDescription className="mt-2 text-sm font-medium text-muted-foreground/85">
                            {user.email}
                            {user.company?.name
                                ? ` • ${user.company.name}`
                                : ''}
                        </CardDescription>
                        <div className="mt-3 flex flex-wrap gap-2">
                            {user.role?.name ? (
                                <Badge
                                    variant="secondary"
                                    className="border-border/60 bg-muted/40 text-[10px] font-bold tracking-wider text-muted-foreground uppercase dark:border-white/10 dark:bg-white/5"
                                >
                                    {user.role.name}
                                </Badge>
                            ) : null}
                        </div>
                    </div>
                    <Badge
                        className={`border text-[10px] font-bold tracking-wider uppercase ${statusClass}`}
                    >
                        {user.status}
                    </Badge>
                </div>
            </CardHeader>

            <CardContent className="pt-0">
                <div className="grid gap-2 pb-12">
                    <div className="flex items-center justify-between gap-3 rounded-xl border border-border/60 bg-muted/30 px-3 py-2 dark:border-white/6 dark:bg-white/4">
                        <div className="text-xs font-semibold text-muted-foreground/80">
                            ID
                        </div>
                        <div className="text-sm font-bold tabular-nums">
                            #{String(user.id).padStart(4, '0')}
                        </div>
                    </div>
                </div>
            </CardContent>

            <div className="pointer-events-none absolute right-4 bottom-4 left-4">
                <div className="pointer-events-auto flex items-center justify-between gap-2 rounded-xl border border-border/60 bg-muted/30 p-1.5 backdrop-blur-xl dark:border-white/6 dark:bg-white/4">
                    <div
                        className="flex items-center gap-2 pl-1.5"
                        onClick={(e) => e.stopPropagation()}
                    >
                        <Switch
                            checked={user.status === 'active'}
                            onCheckedChange={(checked) =>
                                onToggleStatus(user, checked)
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
                                href={`/organization/users/${user.id}`}
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
                                onEdit(user);
                            }}
                            title="Edit"
                        >
                            <Pencil className="h-4 w-4" />
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="h-8 w-8 rounded-lg text-destructive hover:bg-destructive/10 hover:text-destructive"
                            onClick={(e) => {
                                e.stopPropagation();
                                onDelete(user);
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
