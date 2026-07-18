import { Head } from '@inertiajs/react';
import { Camera } from 'lucide-react';
import { HikvisionSettingsPanel } from '@/features/settings/hikvision-settings-panel';
import type { HikvisionSettingsPanelProps } from '@/features/settings/hikvision-settings-panel';

export default function HikvisionIntegrationSettings(
    props: HikvisionSettingsPanelProps,
) {
    return (
        <>
            <Head title="Hikvision" />

            <div className="mb-10 flex flex-col gap-2">
                <div className="flex items-center gap-2">
                    <span className="flex h-2 w-2 animate-pulse rounded-full bg-primary" />
                    <span className="text-[10px] font-bold tracking-[0.2em] text-muted-foreground/80 uppercase">
                        Company Settings · Integrations
                    </span>
                </div>
                <div className="flex items-center gap-3">
                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl border border-primary/20 bg-primary/10">
                        <Camera className="h-5 w-5 text-primary" />
                    </div>
                    <div>
                        <h1 className="bg-linear-to-br from-foreground to-foreground/50 bg-clip-text text-4xl font-extrabold tracking-tight text-transparent">
                            Hikvision
                        </h1>
                        <p className="text-sm font-medium text-muted-foreground/80">
                            Configure this company’s Hik-Connect credentials,
                            webhooks, devices, and fetch schedules.
                        </p>
                    </div>
                </div>
            </div>

            <HikvisionSettingsPanel {...props} />
        </>
    );
}

HikvisionIntegrationSettings.layout = {};
