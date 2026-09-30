import { Head } from '@inertiajs/react';
import { Sparkles } from 'lucide-react';
import { AiSettingsPageSections } from '@/features/settings/ai/ai-settings-page-sections';
import { AiSettingsPanel } from '@/features/settings/ai/ai-settings-panel';
import type { AiSettings } from '@/features/settings/ai/ai-settings-panel';
import { DocumentAiSettingsCard } from '@/features/settings/ai/document-ai-settings-card';
import type { DocumentAiSettingsProps } from '@/features/settings/ai/document-ai-settings-card';

export type AiSettingsPageProps = {
    platform_ai: AiSettings | null;
    document_ai: DocumentAiSettingsProps | null;
    can: {
        platform_update: boolean;
        document_ai_manage: boolean;
    };
};

export default function AiSettingsPage({
    platform_ai,
    document_ai,
    can,
}: AiSettingsPageProps) {
    const documentAiCard = document_ai ? (
        <DocumentAiSettingsCard settings={document_ai} />
    ) : null;

    return (
        <>
            <Head title="AI" />

            <div className="mb-10 flex flex-col gap-2">
                <div className="flex items-center gap-2">
                    <span className="flex h-2 w-2 animate-pulse rounded-full bg-primary" />
                    <span className="text-[10px] font-bold tracking-[0.2em] text-muted-foreground/80 uppercase">
                        Settings
                    </span>
                </div>
                <div className="flex items-center gap-3">
                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl border border-violet-500/20 bg-violet-500/10">
                        <Sparkles className="h-5 w-5 text-violet-500" />
                    </div>
                    <div>
                        <h1 className="bg-linear-to-br from-foreground to-foreground/50 bg-clip-text text-4xl font-extrabold tracking-tight text-transparent">
                            AI
                        </h1>
                        <p className="text-sm font-medium text-muted-foreground/80">
                            Configure platform AI providers and AI-powered
                            OMS-HRM features.
                        </p>
                    </div>
                </div>
            </div>

            <AiSettingsPageSections
                platformAi={platform_ai}
                documentAi={document_ai}
                platformPanel={
                    platform_ai ? (
                        <AiSettingsPanel
                            {...platform_ai}
                            canUpdate={can.platform_update}
                            featuresExtra={documentAiCard}
                        />
                    ) : null
                }
                documentAiCard={documentAiCard}
            />
        </>
    );
}

AiSettingsPage.layout = {};
