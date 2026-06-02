import { Head, Link, usePage } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import Heading from '@/components/heading';
import { Card } from '@/components/ui/card';
import {
    filterSettingsNavItems,
    SETTINGS_MASTER_DATA_ITEMS,
    SETTINGS_SYSTEM_ITEMS,
} from '@/lib/settings-nav';
import type { SettingsNavItem } from '@/lib/settings-nav';
import { cn } from '@/lib/utils';

const SETTINGS_GROUPS = [
    {
        title: 'System',
        description: 'Application branding, email, WhatsApp, security, and appearance.',
        items: SETTINGS_SYSTEM_ITEMS,
    },
    {
        title: 'Master data',
        description: 'Reference data used across employees, payroll, and compliance.',
        items: SETTINGS_MASTER_DATA_ITEMS,
    },
];

export default function SettingsIndex() {
    const { auth } = usePage().props as { auth?: { permissions?: string[] } };
    const permissions = auth?.permissions ?? [];

    const visibleGroups = SETTINGS_GROUPS.map((group) => ({
        ...group,
        items: filterSettingsNavItems(group.items, permissions),
    })).filter((group) => group.items.length > 0);

    const moduleCount = visibleGroups.reduce(
        (count, group) => count + group.items.length,
        0,
    );

    return (
        <>
            <Head title="Settings" />

            <div className="space-y-10">
                <Heading
                    title="Settings"
                    description="Open system preferences and master data from one place instead of scrolling the sidebar."
                />

                <p className="text-sm text-muted-foreground">
                    {moduleCount} {moduleCount === 1 ? 'module' : 'modules'} available
                </p>

                {visibleGroups.map((group) => (
                    <section key={group.title} className="space-y-4">
                        <div className="space-y-1">
                            <h2 className="text-base font-semibold tracking-tight">
                                {group.title}
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                {group.description}
                            </p>
                        </div>

                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                            {group.items.map((item) => (
                                <SettingsNavCard key={item.href} item={item} />
                            ))}
                        </div>
                    </section>
                ))}
            </div>
        </>
    );
}

function SettingsNavCard({ item }: { item: SettingsNavItem }) {
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
                        Manage {item.title.toLowerCase()} standards and validations.
                    </p>
                </div>
            </Card>
        </Link>
    );
}
