import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { Card } from '@/components/ui/card';
import type { SettingsNavItem } from '@/lib/settings-nav';
import { cn } from '@/lib/utils';

export function SettingsNavCard({ item }: { item: SettingsNavItem }) {
    const color = item.color ?? 'bg-muted text-muted-foreground';

    return (
        <Link href={item.href} className="group block h-full">
            <Card
                className={cn(
                    'h-full gap-0 py-0 transition-colors',
                    'hover:border-primary/40 hover:bg-muted/20',
                )}
            >
                <div className="flex items-start justify-between gap-4 p-5">
                    <div
                        className={cn(
                            'flex h-10 w-10 shrink-0 items-center justify-center rounded-lg',
                            color,
                        )}
                    >
                        <item.icon className="h-5 w-5" />
                    </div>
                    <ChevronRight className="h-5 w-5 text-muted-foreground transition-transform group-hover:translate-x-0.5" />
                </div>
                <div className="space-y-1 px-5 pb-5">
                    <p className="font-semibold text-foreground group-hover:text-primary">
                        {item.title}
                    </p>
                    <p className="text-sm text-muted-foreground">
                        Manage {item.title.toLowerCase()} standards and
                        validations.
                    </p>
                </div>
            </Card>
        </Link>
    );
}
