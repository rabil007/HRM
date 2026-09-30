import type { ReactNode } from 'react';
import type { AiSettings } from '@/features/settings/ai/ai-settings-panel';
import type { DocumentAiSettingsProps } from '@/features/settings/ai/document-ai-settings-card';

export type AiSettingsPageSectionsProps = {
    platformAi: AiSettings | null;
    documentAi: DocumentAiSettingsProps | null;
    platformPanel: ReactNode;
    documentAiCard: ReactNode;
};

/**
 * Permission-aware layout for the centralized AI settings page.
 * Platform provider controls render only when platform props exist.
 * Document AI renders when company settings exist.
 */
export function AiSettingsPageSections({
    platformAi,
    documentAi,
    platformPanel,
    documentAiCard,
}: AiSettingsPageSectionsProps) {
    return (
        <div className="space-y-8" data-testid="ai-settings-page">
            {platformAi ? (
                <div data-testid="platform-ai-section">{platformPanel}</div>
            ) : null}

            {!platformAi && documentAi ? (
                <section
                    className="space-y-3"
                    data-testid="ai-features-section"
                    id="ai-features"
                >
                    <div>
                        <h2 className="text-sm font-semibold tracking-tight text-foreground">
                            AI Features
                        </h2>
                        <p className="mt-1 text-xs text-muted-foreground">
                            Document AI policy for the active company. Provider
                            credentials stay platform-managed.
                        </p>
                    </div>
                    <div data-testid="document-ai-section">
                        {documentAiCard}
                    </div>
                </section>
            ) : null}

            {!platformAi && !documentAi ? (
                <p className="rounded-xl border border-dashed border-border/80 px-4 py-8 text-center text-sm text-muted-foreground dark:border-white/10">
                    No AI settings are available for your account.
                </p>
            ) : null}
        </div>
    );
}
