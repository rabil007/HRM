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
        <div className="space-y-10" data-testid="ai-settings-page">
            {platformAi ? (
                <div data-testid="platform-ai-section">{platformPanel}</div>
            ) : null}

            {!platformAi && documentAi ? (
                <section
                    className="space-y-4"
                    data-testid="ai-features-section"
                >
                    <div>
                        <h2 className="text-sm font-semibold tracking-tight text-foreground">
                            AI Features
                        </h2>
                        <p className="mt-1 text-xs text-muted-foreground">
                            Company Document AI policy for the active company.
                            Provider credentials remain platform-managed.
                        </p>
                    </div>
                    <div data-testid="document-ai-section">
                        {documentAiCard}
                    </div>
                </section>
            ) : null}

            {!platformAi && !documentAi ? (
                <p className="text-sm text-muted-foreground">
                    No AI settings are available for your account.
                </p>
            ) : null}
        </div>
    );
}
