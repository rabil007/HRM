import { useForm } from '@inertiajs/react';
import { Sparkles } from 'lucide-react';
import { update as updateDocumentAiSettings } from '@/actions/App/Http/Controllers/Organization/DocumentAiSettingsController';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useHasPermission } from '@/hooks/use-has-permission';

export type DocumentAiMode = 'off' | 'optional' | 'automatic';

export type DocumentAiSettingsProps = {
    mode: DocumentAiMode;
    provider_available: boolean;
    available: boolean;
};

const modeDescriptions: Record<DocumentAiMode, string> = {
    off: 'Document uploads stay fully manual and never call an AI provider.',
    optional:
        'Authorized users can choose AI extraction when they want help with document intake.',
    automatic:
        'Eligible document uploads will start AI extraction automatically when the extraction workflow is enabled.',
};

const modeLabels: Record<DocumentAiMode, string> = {
    off: 'Off',
    optional: 'Optional',
    automatic: 'Automatic',
};

export function DocumentAiSettingsCard({
    settings,
}: {
    settings: DocumentAiSettingsProps;
}) {
    const canManage = useHasPermission('documents.ai.manage');
    const form = useForm<{ mode: DocumentAiMode }>({
        mode: settings.mode,
    });

    const submit = () => {
        if (!canManage || !form.isDirty || form.processing) {
            return;
        }

        form.put(updateDocumentAiSettings.url(), {
            preserveScroll: true,
        });
    };

    const providerWarning =
        form.data.mode !== 'off' && !settings.provider_available;

    return (
        <Card className="border-border/80 bg-card dark:border-white/5 dark:bg-white/5">
            <CardHeader className="gap-3 md:flex-row md:items-start md:justify-between">
                <div className="flex min-w-0 items-start gap-3">
                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl border border-violet-500/20 bg-violet-500/10 text-violet-500">
                        <Sparkles className="h-5 w-5" />
                    </div>
                    <div className="space-y-1">
                        <CardTitle className="text-base">
                            AI document assistance
                        </CardTitle>
                        <CardDescription>
                            Control whether this company can use AI-assisted
                            document intake. Platform AI credentials stay
                            managed by platform administrators.
                        </CardDescription>
                    </div>
                </div>
                <Badge
                    variant={form.data.mode === 'off' ? 'secondary' : 'default'}
                >
                    {modeLabels[form.data.mode]}
                </Badge>
            </CardHeader>
            <CardContent className="space-y-4">
                <div className="grid gap-3 md:grid-cols-[minmax(0,280px)_1fr_auto] md:items-end">
                    <div className="space-y-2">
                        <label
                            className="text-sm font-medium"
                            htmlFor="document_ai_mode"
                        >
                            Mode
                        </label>
                        <Select
                            value={form.data.mode}
                            onValueChange={(value) =>
                                form.setData('mode', value as DocumentAiMode)
                            }
                            disabled={!canManage || form.processing}
                        >
                            <SelectTrigger id="document_ai_mode">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="off">Off</SelectItem>
                                <SelectItem value="optional">
                                    Optional
                                </SelectItem>
                                <SelectItem value="automatic">
                                    Automatic
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError message={form.errors.mode} />
                    </div>

                    <div className="rounded-lg border bg-muted/30 px-3 py-2 text-sm text-muted-foreground">
                        {modeDescriptions[form.data.mode]}
                    </div>

                    {canManage ? (
                        <Button
                            type="button"
                            onClick={submit}
                            disabled={!form.isDirty || form.processing}
                        >
                            Save AI mode
                        </Button>
                    ) : null}
                </div>

                {providerWarning ? (
                    <p className="rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-sm text-amber-700 dark:text-amber-300">
                        The platform AI provider is not configured. This mode
                        can be saved, but Document AI will remain unavailable
                        until a platform administrator configures a provider.
                    </p>
                ) : null}

                <p className="text-xs text-muted-foreground">
                    Phase 1 only establishes the setting and permissions.
                    Existing uploads remain unchanged; extraction is wired in
                    the next phase.
                </p>
            </CardContent>
        </Card>
    );
}
