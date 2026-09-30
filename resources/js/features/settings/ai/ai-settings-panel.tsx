import { useForm } from '@inertiajs/react';
import { CheckCircle2, PlugZap, Search, XCircle } from 'lucide-react';
import { useEffect, useState } from 'react';
import InputError from '@/components/input-error';
import { SettingsSecretInput } from '@/components/settings/settings-secret-input';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { testAiConnection } from '@/features/settings/test-ai-connection';
import { toast } from '@/lib/toast';
import { cn } from '@/lib/utils';
import {
    test as testAiProvider,
    update as updateAiSettings,
} from '@/routes/application/ai';

type AiProvider = 'openai' | 'openrouter';

type ConnectionStatus = 'idle' | 'connected' | 'failed';

export type AiSettings = {
    enabled: boolean;
    provider: AiProvider;
    openai: {
        has_api_key: boolean;
        model: string;
    };
    openrouter: {
        has_api_key: boolean;
        model: string;
    };
    default_models?: {
        openai: string;
        openrouter: string;
    };
};

export type AiSettingsPanelProps = AiSettings & {
    canUpdate: boolean;
    featuresExtra?: React.ReactNode;
};

function FieldLabel({
    htmlFor,
    children,
}: {
    htmlFor?: string;
    children: React.ReactNode;
}) {
    return (
        <Label
            htmlFor={htmlFor}
            className="ml-0.5 text-[10px] font-bold tracking-widest text-muted-foreground/60 uppercase"
        >
            {children}
        </Label>
    );
}

function FieldInput(props: React.ComponentProps<typeof Input>) {
    return (
        <Input
            {...props}
            className={cn(
                'h-11 rounded-xl border-input bg-background/50 px-4 text-foreground transition-all focus-visible:ring-primary/40 dark:border-white/10 dark:bg-white/5',
                props.className,
            )}
        />
    );
}

function ProviderFields({
    title,
    selected,
    hasApiKey,
    apiKeyId,
    apiKeyValue,
    onApiKeyChange,
    apiKeyError,
    modelId,
    modelValue,
    onModelChange,
    modelError,
    canUpdate,
    defaultModelHint,
}: {
    title: string;
    selected: boolean;
    hasApiKey: boolean;
    apiKeyId: string;
    apiKeyValue: string;
    onApiKeyChange: (value: string) => void;
    apiKeyError?: string;
    modelId: string;
    modelValue: string;
    onModelChange: (value: string) => void;
    modelError?: string;
    canUpdate: boolean;
    defaultModelHint: string;
}) {
    return (
        <div
            className={cn(
                'space-y-4 rounded-xl border p-4 transition-colors',
                selected
                    ? 'border-primary/40 bg-primary/5 dark:bg-primary/10'
                    : 'border-border/70 bg-muted/20 dark:border-white/5 dark:bg-white/[0.03]',
            )}
        >
            <div className="flex flex-wrap items-center gap-2">
                <h3 className="text-sm font-semibold tracking-tight">
                    {title}
                </h3>
                {selected ? (
                    <Badge className="text-[10px]">Active</Badge>
                ) : null}
                {hasApiKey ? (
                    <Badge variant="success" className="text-[10px]">
                        Key saved
                    </Badge>
                ) : (
                    <Badge variant="secondary" className="text-[10px]">
                        No key
                    </Badge>
                )}
            </div>

            <div className="space-y-1.5">
                <FieldLabel htmlFor={apiKeyId}>API key</FieldLabel>
                <SettingsSecretInput
                    id={apiKeyId}
                    value={apiKeyValue}
                    onChange={(event) => onApiKeyChange(event.target.value)}
                    placeholder={
                        hasApiKey ? '•••••••• (configured)' : 'Paste API key'
                    }
                    disabled={!canUpdate}
                    autoComplete="new-password"
                />
                <InputError message={apiKeyError} />
            </div>

            <div className="space-y-1.5">
                <FieldLabel htmlFor={modelId}>Model</FieldLabel>
                <FieldInput
                    id={modelId}
                    value={modelValue}
                    onChange={(event) => onModelChange(event.target.value)}
                    placeholder={`Default: ${defaultModelHint}`}
                    disabled={!canUpdate}
                    autoComplete="off"
                />
                <InputError message={modelError} />
            </div>
        </div>
    );
}

function SmartEmployeeSearchCard({
    enabled,
    onEnabledChange,
    canUpdate,
    error,
}: {
    enabled: boolean;
    onEnabledChange: (enabled: boolean) => void;
    canUpdate: boolean;
    error?: string;
}) {
    return (
        <Card className="border-border/80 bg-card dark:border-white/5 dark:bg-white/5">
            <CardContent className="flex items-start gap-3 p-5 sm:items-center sm:p-6">
                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-sky-500/20 bg-sky-500/10 text-sky-600 dark:text-sky-400">
                    <Search className="h-4 w-4" />
                </div>
                <div className="min-w-0 flex-1 space-y-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <h3 className="text-sm font-semibold tracking-tight text-foreground">
                            Smart Employee Search
                        </h3>
                        <Badge variant="secondary" className="text-[10px]">
                            Platform-wide
                        </Badge>
                    </div>
                    <p className="text-xs text-muted-foreground">
                        Natural-language filters on the Employee Directory. Off
                        by default. Does not control Document AI.
                    </p>
                    <InputError message={error} />
                </div>
                <Switch
                    checked={enabled}
                    onCheckedChange={onEnabledChange}
                    disabled={!canUpdate}
                    aria-label="Enable Smart Employee Search"
                />
            </CardContent>
        </Card>
    );
}

export function AiSettingsPanel({
    enabled,
    provider,
    openai,
    openrouter,
    default_models,
    canUpdate,
    featuresExtra,
}: AiSettingsPanelProps) {
    const [connectionStatus, setConnectionStatus] =
        useState<ConnectionStatus>('idle');
    const [connectionMessage, setConnectionMessage] = useState<string | null>(
        null,
    );
    const [testing, setTesting] = useState(false);

    const form = useForm({
        enabled,
        provider,
        openai_api_key: '',
        openai_model: openai.model ?? '',
        openrouter_api_key: '',
        openrouter_model: openrouter.model ?? '',
    });

    useEffect(() => {
        setConnectionStatus('idle');
        setConnectionMessage(null);
    }, [
        form.data.enabled,
        form.data.provider,
        form.data.openai_api_key,
        form.data.openai_model,
        form.data.openrouter_api_key,
        form.data.openrouter_model,
    ]);

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        if (!canUpdate) {
            return;
        }

        form.put(updateAiSettings.url(), {
            preserveScroll: true,
            onSuccess: () => {
                form.setData('openai_api_key', '');
                form.setData('openrouter_api_key', '');
            },
        });
    };

    const handleTestConnection = async () => {
        if (!canUpdate) {
            return;
        }

        setTesting(true);
        setConnectionMessage(null);

        try {
            const result = await testAiConnection(testAiProvider.url());

            setConnectionStatus('connected');
            setConnectionMessage(result.message);
            toast.success(result.message);
        } catch (error) {
            const message =
                error instanceof Error
                    ? error.message
                    : 'Unable to connect to the selected AI provider.';

            setConnectionStatus('failed');
            setConnectionMessage(message);
            toast.error(message);
        } finally {
            setTesting(false);
        }
    };

    return (
        <form onSubmit={submit} className="space-y-8">
            <section className="space-y-3" id="platform-ai">
                <div className="flex flex-wrap items-end justify-between gap-2">
                    <div>
                        <h2 className="text-sm font-semibold tracking-tight text-foreground">
                            Platform AI
                        </h2>
                        <p className="mt-1 text-xs text-muted-foreground">
                            Credentials belong to OMS-HRM, not a company.
                        </p>
                    </div>
                    <Badge variant="secondary" className="text-[10px]">
                        Platform-wide
                    </Badge>
                </div>

                <Card className="border-border/80 bg-card dark:border-white/5 dark:bg-white/5">
                    <CardContent className="space-y-5 p-5 sm:p-6">
                        <div className="max-w-sm space-y-1.5">
                            <FieldLabel htmlFor="ai_provider">
                                Active provider
                            </FieldLabel>
                            <Select
                                value={form.data.provider}
                                onValueChange={(value) =>
                                    form.setData(
                                        'provider',
                                        value as AiProvider,
                                    )
                                }
                                disabled={!canUpdate}
                            >
                                <SelectTrigger
                                    id="ai_provider"
                                    className="h-11 rounded-xl border-input bg-background/50 dark:border-white/10 dark:bg-white/5"
                                >
                                    <SelectValue placeholder="Select a provider" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="openai">
                                        OpenAI
                                    </SelectItem>
                                    <SelectItem value="openrouter">
                                        OpenRouter
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.provider} />
                            <p className="text-[10px] text-muted-foreground/60">
                                Switching keeps both keys. Test uses last saved
                                settings.
                            </p>
                        </div>

                        <div className="grid gap-4 lg:grid-cols-2">
                            <ProviderFields
                                title="OpenAI"
                                selected={form.data.provider === 'openai'}
                                hasApiKey={openai.has_api_key}
                                apiKeyId="openai_api_key"
                                apiKeyValue={form.data.openai_api_key}
                                onApiKeyChange={(value) =>
                                    form.setData('openai_api_key', value)
                                }
                                apiKeyError={form.errors.openai_api_key}
                                modelId="openai_model"
                                modelValue={form.data.openai_model}
                                onModelChange={(value) =>
                                    form.setData('openai_model', value)
                                }
                                modelError={form.errors.openai_model}
                                canUpdate={canUpdate}
                                defaultModelHint="GPT-5.6 Luna"
                            />

                            <ProviderFields
                                title="OpenRouter"
                                selected={form.data.provider === 'openrouter'}
                                hasApiKey={openrouter.has_api_key}
                                apiKeyId="openrouter_api_key"
                                apiKeyValue={form.data.openrouter_api_key}
                                onApiKeyChange={(value) =>
                                    form.setData('openrouter_api_key', value)
                                }
                                apiKeyError={form.errors.openrouter_api_key}
                                modelId="openrouter_model"
                                modelValue={form.data.openrouter_model}
                                onModelChange={(value) =>
                                    form.setData('openrouter_model', value)
                                }
                                modelError={form.errors.openrouter_model}
                                canUpdate={canUpdate}
                                defaultModelHint={
                                    default_models?.openrouter ||
                                    'openai/gpt-5.6-luna'
                                }
                            />
                        </div>

                        {canUpdate ? (
                            <div className="flex flex-col gap-3 border-t border-border/60 pt-4 sm:flex-row sm:flex-wrap sm:items-center">
                                <Button
                                    type="submit"
                                    disabled={form.processing}
                                    className="h-10 rounded-xl px-5"
                                >
                                    {form.processing ? (
                                        <Spinner className="mr-2" />
                                    ) : null}
                                    Save platform AI
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={testing}
                                    onClick={() => void handleTestConnection()}
                                    className="h-10 rounded-xl px-5"
                                >
                                    {testing ? (
                                        <Spinner className="mr-2" />
                                    ) : (
                                        <PlugZap className="mr-2 h-4 w-4" />
                                    )}
                                    Test selected provider
                                </Button>
                                {connectionStatus === 'connected' &&
                                connectionMessage ? (
                                    <span className="inline-flex items-center gap-1.5 text-xs text-emerald-500">
                                        <CheckCircle2 className="h-4 w-4" />
                                        {connectionMessage}
                                    </span>
                                ) : null}
                                {connectionStatus === 'failed' &&
                                connectionMessage ? (
                                    <span className="inline-flex items-center gap-1.5 text-xs text-destructive">
                                        <XCircle className="h-4 w-4" />
                                        {connectionMessage}
                                    </span>
                                ) : null}
                                <p className="text-[10px] text-muted-foreground/60 sm:ml-auto">
                                    Leave API keys blank to keep stored values.
                                </p>
                            </div>
                        ) : null}
                    </CardContent>
                </Card>
            </section>

            <section className="space-y-3" id="ai-features">
                <div>
                    <h2 className="text-sm font-semibold tracking-tight text-foreground">
                        AI Features
                    </h2>
                    <p className="mt-1 text-xs text-muted-foreground">
                        Features use the platform provider. Smart Search and
                        Document AI are independent.
                    </p>
                </div>

                <div className="space-y-3">
                    <SmartEmployeeSearchCard
                        enabled={form.data.enabled}
                        onEnabledChange={(checked) =>
                            form.setData('enabled', checked)
                        }
                        canUpdate={canUpdate}
                        error={form.errors.enabled}
                    />

                    {canUpdate ? (
                        <p className="px-1 text-xs text-muted-foreground">
                            Click{' '}
                            <span className="font-medium">
                                Save platform AI
                            </span>{' '}
                            above to persist the Smart Employee Search toggle.
                        </p>
                    ) : null}

                    {featuresExtra}
                </div>
            </section>
        </form>
    );
}
