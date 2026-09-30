import { useForm } from '@inertiajs/react';
import {
    Root as RadioGroup,
    Item as RadioItem,
} from '@radix-ui/react-radio-group';
import { FileText } from 'lucide-react';
import { update as updateDocumentAiSettings } from '@/actions/App/Http/Controllers/Organization/DocumentAiSettingsController';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { useHasPermission } from '@/hooks/use-has-permission';
import { cn } from '@/lib/utils';

export type DocumentAiMode = 'off' | 'optional' | 'automatic';

export type DocumentAiSettingsProps = {
    mode: DocumentAiMode;
    provider_available: boolean;
    available: boolean;
    company_name?: string | null;
};

const modeOptions: Array<{
    value: DocumentAiMode;
    label: string;
    description: string;
}> = [
    {
        value: 'off',
        label: 'Off',
        description: 'Fully manual uploads. No AI provider calls.',
    },
    {
        value: 'optional',
        label: 'Optional',
        description: 'Users choose Extract with AI, then review before Upload.',
    },
    {
        value: 'automatic',
        label: 'Automatic',
        description:
            'Eligible files start extraction automatically. Upload stays manual.',
    },
];

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

    const companyBadge =
        settings.company_name && settings.company_name.trim() !== ''
            ? settings.company_name
            : 'Active company';

    return (
        <Card className="border-border/80 bg-card dark:border-white/5 dark:bg-white/5">
            <CardContent className="space-y-5 p-5 sm:p-6">
                <div className="flex items-start gap-3">
                    <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-violet-500/20 bg-violet-500/10 text-violet-500">
                        <FileText className="h-4 w-4" />
                    </div>
                    <div className="min-w-0 flex-1 space-y-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <h3 className="text-sm font-semibold tracking-tight text-foreground">
                                Document AI
                            </h3>
                            <Badge variant="secondary" className="text-[10px]">
                                {companyBadge}
                            </Badge>
                        </div>
                        <p className="text-xs text-muted-foreground">
                            AI-assisted document intake for the active company.
                            Review and Upload stay under user control.
                        </p>
                    </div>
                </div>

                <RadioGroup
                    value={form.data.mode}
                    onValueChange={(value) =>
                        form.setData('mode', value as DocumentAiMode)
                    }
                    disabled={!canManage || form.processing}
                    aria-label="Document AI mode"
                    className="grid gap-2 sm:grid-cols-3"
                >
                    {modeOptions.map((option) => {
                        const selected = form.data.mode === option.value;

                        return (
                            <RadioItem
                                key={option.value}
                                value={option.value}
                                disabled={!canManage || form.processing}
                                className={cn(
                                    'cursor-pointer rounded-xl border bg-card/70 p-3.5 text-left transition-all outline-none',
                                    'focus-visible:ring-2 focus-visible:ring-primary/40',
                                    selected
                                        ? 'border-primary shadow-xs ring-1 ring-primary'
                                        : 'border-border/80 hover:border-border hover:bg-card',
                                    (!canManage || form.processing) &&
                                        'cursor-not-allowed opacity-70',
                                )}
                            >
                                <div className="text-sm font-semibold text-foreground">
                                    {option.label}
                                </div>
                                <p className="mt-1 text-xs leading-relaxed text-muted-foreground">
                                    {option.description}
                                </p>
                            </RadioItem>
                        );
                    })}
                </RadioGroup>
                <InputError message={form.errors.mode} />

                {providerWarning ? (
                    <p className="rounded-xl border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-sm text-amber-700 dark:text-amber-300">
                        The platform AI provider is not configured. Document AI
                        will remain unavailable until a platform administrator
                        configures a provider.
                    </p>
                ) : null}

                <div className="flex flex-col gap-3 border-t border-border/60 pt-4 sm:flex-row sm:items-center sm:justify-between">
                    <p className="text-xs text-muted-foreground">
                        Never updates employee master data. Never saves
                        documents automatically.
                    </p>
                    {canManage ? (
                        <Button
                            type="button"
                            onClick={submit}
                            disabled={!form.isDirty || form.processing}
                            className="h-10 shrink-0 rounded-xl px-5"
                        >
                            Save AI mode
                        </Button>
                    ) : null}
                </div>
            </CardContent>
        </Card>
    );
}
