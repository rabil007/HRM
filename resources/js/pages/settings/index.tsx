import { Head, usePage } from '@inertiajs/react';
import Heading from '@/components/heading';
import { SettingsNavCard } from '@/components/settings/settings-nav-card';
import { NO_PLATFORM_ACCESS } from '@/lib/nav-visibility';
import type { NavPlatformAccess } from '@/lib/nav-visibility';
import { accessibleSettingsNavGroups } from '@/lib/settings-nav';

export default function SettingsIndex() {
    const { auth } = usePage().props as {
        auth?: {
            permissions?: string[];
            platform?: NavPlatformAccess;
        };
    };
    const permissions = auth?.permissions ?? [];
    const platform = auth?.platform ?? NO_PLATFORM_ACCESS;

    const visibleGroups = accessibleSettingsNavGroups(permissions, platform);

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
                    {moduleCount} {moduleCount === 1 ? 'module' : 'modules'}{' '}
                    available
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
