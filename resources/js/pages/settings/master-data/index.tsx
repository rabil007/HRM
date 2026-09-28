import { Head, usePage } from '@inertiajs/react';
import Heading from '@/components/heading';
import { SettingsNavCard } from '@/components/settings/settings-nav-card';
import {
    filterSettingsNavItems,
    NO_PLATFORM_ACCESS,
} from '@/lib/nav-visibility';
import type { NavPlatformAccess } from '@/lib/nav-visibility';
import { SETTINGS_MASTER_DATA_ITEMS } from '@/lib/settings-nav';

export default function MasterDataIndex() {
    const { auth } = usePage().props as {
        auth?: {
            permissions?: string[];
            platform?: NavPlatformAccess;
        };
    };
    const items = filterSettingsNavItems(
        SETTINGS_MASTER_DATA_ITEMS,
        auth?.permissions ?? [],
        auth?.platform ?? NO_PLATFORM_ACCESS,
    );

    return (
        <>
            <Head title="Master Data" />

            <div className="space-y-6">
                <Heading
                    title="Master Data"
                    description="Manage reference data used across employees, payroll, and compliance."
                />

                {items.length > 0 ? (
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        {items.map((item) => (
                            <SettingsNavCard key={item.href} item={item} />
                        ))}
                    </div>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        No Master Data catalogs are available for this company.
                    </p>
                )}
            </div>
        </>
    );
}
