import { Head } from '@inertiajs/react';
import { FileText, Search, Sparkles, Zap } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { AiSettingsPageSections } from '@/features/settings/ai/ai-settings-page-sections';
import { AiSettingsPanel } from '@/features/settings/ai/ai-settings-panel';
import type { AiSettings } from '@/features/settings/ai/ai-settings-panel';
import { DocumentAiSettingsCard } from '@/features/settings/ai/document-ai-settings-card';
import type { DocumentAiSettingsProps } from '@/features/settings/ai/document-ai-settings-card';
import { cn } from '@/lib/utils';

export type AiSettingsPageProps = {
    platform_ai: AiSettings | null;
    document_ai: DocumentAiSettingsProps | null;
    can: {
        platform_update: boolean;
        document_ai_manage: boolean;
    };
};

const documentModeLabels: Record<
    NonNullable<DocumentAiSettingsProps['mode']>,
    string
> = {
    off: 'Off',
    optional: 'Optional',
    automatic: 'Automatic',
};

function StatusChip({
    icon: Icon,
    label,
    value,
    href,
    tone = 'neutral',
}: {
    icon: React.ComponentType<{ className?: string }>;
    label: string;
    value: string;
    href?: string;
    tone?: 'neutral' | 'success' | 'warning';
}) {
    const content = (
        <>
            <Icon className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
            <span className="text-muted-foreground">{label}</span>
            <span
                className={cn(
                    'font-medium',
                    tone === 'success' &&
                        'text-emerald-600 dark:text-emerald-400',
                    tone === 'warning' && 'text-amber-600 dark:text-amber-400',
                    tone === 'neutral' && 'text-foreground',
                )}
            >
                {value}
            </span>
        </>
    );

    const className = cn(
        'inline-flex items-center gap-2 rounded-full border border-border/80 bg-card px-3 py-1.5 text-xs dark:border-white/10 dark:bg-white/5',
        href && 'transition-colors hover:border-primary/40 hover:bg-muted/40',
    );

    if (href) {
        return (
            <a href={href} className={className}>
                {content}
            </a>
        );
    }

    return <div className={className}>{content}</div>;
}

function AiStatusOverview({
    platformAi,
    documentAi,
}: {
    platformAi: AiSettings | null;
    documentAi: DocumentAiSettingsProps | null;
}) {
    if (!platformAi && !documentAi) {
        return null;
    }

    const providerConfigured = platformAi
        ? platformAi.provider === 'openai'
            ? platformAi.openai.has_api_key
            : platformAi.openrouter.has_api_key
        : documentAi?.provider_available === true;

    return (
        <div className="mb-8 flex flex-wrap gap-2">
            {platformAi ? (
                <>
                    <StatusChip
                        icon={Zap}
                        label="Provider"
                        value={
                            platformAi.provider === 'openai'
                                ? 'OpenAI'
                                : 'OpenRouter'
                        }
                        href="#platform-ai"
                        tone={providerConfigured ? 'success' : 'warning'}
                    />
                    <StatusChip
                        icon={Search}
                        label="Smart Search"
                        value={platformAi.enabled ? 'On' : 'Off'}
                        href="#ai-features"
                        tone={platformAi.enabled ? 'success' : 'neutral'}
                    />
                </>
            ) : null}

            {documentAi ? (
                <StatusChip
                    icon={FileText}
                    label="Document AI"
                    value={documentModeLabels[documentAi.mode]}
                    href="#document-ai"
                    tone={
                        documentAi.mode === 'off'
                            ? 'neutral'
                            : documentAi.available
                              ? 'success'
                              : 'warning'
                    }
                />
            ) : null}

            {platformAi ? (
                <Badge variant="secondary" className="self-center text-[10px]">
                    Jump to a section
                </Badge>
            ) : null}
        </div>
    );
}

export default function AiSettingsPage({
    platform_ai,
    document_ai,
    can,
}: AiSettingsPageProps) {
    const documentAiCard = document_ai ? (
        <div id="document-ai">
            <DocumentAiSettingsCard settings={document_ai} />
        </div>
    ) : null;

    return (
        <>
            <Head title="AI" />

            <div className="mb-6 flex flex-col gap-2">
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
                        <h1 className="bg-linear-to-br from-foreground to-foreground/50 bg-clip-text text-3xl font-extrabold tracking-tight text-transparent sm:text-4xl">
                            AI
                        </h1>
                        <p className="text-sm font-medium text-muted-foreground/80">
                            Providers and AI-powered features in one place.
                        </p>
                    </div>
                </div>
            </div>

            <AiStatusOverview
                platformAi={platform_ai}
                documentAi={document_ai}
            />

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
