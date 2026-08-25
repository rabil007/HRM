import { useForm } from '@inertiajs/react';
import { CheckCircle2, PlugZap, Sparkles, XCircle } from 'lucide-react';
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
};

export type AiSettingsPanelProps = AiSettings & {
    canUpdate: boolean;
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

function ProviderCredentialCard({
    title,
    description,
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
}: {
    title: string;
    description: string;
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
}) {
    return (
        <Card className="border-border/80 bg-card dark:border-white/5 dark:bg-white/5">
            <CardContent className="space-y-5 p-6">
                <div className="flex flex-wrap items-center gap-2">
                    <h3 className="text-sm font-bold tracking-tight">
                        {title}
                    </h3>
                    {hasApiKey ? (
                        <Badge variant="success" className="text-[10px]">
                            Configured
                        </Badge>
                    ) : null}
                </div>
                <p className="text-xs text-muted-foreground">{description}</p>

                <div className="space-y-1.5">
                    <FieldLabel htmlFor={apiKeyId}>API key</FieldLabel>
                    <SettingsSecretInput
                        id={apiKeyId}
                        value={apiKeyValue}
                        onChange={(event) => onApiKeyChange(event.target.value)}
                        placeholder={
                            hasApiKey
                                ? '•••••••• (configured)'
                                : 'Paste API key'
                        }
                        disabled={!canUpdate}
                        autoComplete="new-password"
                    />
                    <InputError message={apiKeyError} />
                    <p className="ml-0.5 text-[10px] text-muted-foreground/50">
                        Leave blank to keep the stored key. Keys are never shown
                        after save.
                    </p>
                </div>

                <div className="space-y-1.5">
                    <FieldLabel htmlFor={modelId}>Model</FieldLabel>
                    <FieldInput
                        id={modelId}
                        value={modelValue}
                        onChange={(event) => onModelChange(event.target.value)}
                        placeholder="Leave blank for the SDK/provider default"
                        disabled={!canUpdate}
                        autoComplete="off"
                    />
                    <InputError message={modelError} />
                    <p className="ml-0.5 text-[10px] text-muted-foreground/50">
                        Optional. Leave blank to use the SDK/provider default
                        when supported.
                    </p>
                </div>
            </CardContent>
        </Card>
    );
}

export function AiSettingsPanel({
    enabled,
    provider,
    openai,
    openrouter,
    canUpdate,
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
        <form onSubmit={submit} className="space-y-6">
            <Card className="border-border/80 bg-card dark:border-white/5 dark:bg-white/5">
                <CardContent className="space-y-6 p-6">
                    <div className="mb-2 flex items-center gap-4">
                        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl border border-violet-500/20 bg-violet-500/10 text-violet-500">
                            <Sparkles className="h-5 w-5" />
                        </div>
                        <div className="min-w-0 flex-1">
                            <div className="flex flex-wrap items-center gap-2">
                                <h2 className="text-base font-bold tracking-tight text-foreground">
                                    AI & Smart Search
                                </h2>
                                <Badge
                                    variant="secondary"
                                    className="text-[10px]"
                                >
                                    Platform-wide
                                </Badge>
                            </div>
                            <p className="mt-0.5 text-xs text-muted-foreground">
                                Provider credentials belong to OMS-HRM, not a
                                company. Smart Employee Search sends only the
                                user&apos;s short prompt plus fixed interpreter
                                instructions.
                            </p>
                        </div>
                    </div>

                    <div className="flex items-center justify-between rounded-xl border border-border/80 bg-muted/20 px-4 py-3 dark:border-white/5 dark:bg-white/5">
                        <div>
                            <p className="text-sm font-medium">
                                Enable Smart Employee Search
                            </p>
                            <p className="text-xs text-muted-foreground">
                                Interprets natural-language Employee Directory
                                filters. Off by default.
                            </p>
                        </div>
                        <Switch
                            checked={form.data.enabled}
                            onCheckedChange={(checked) =>
                                form.setData('enabled', checked)
                            }
                            disabled={!canUpdate}
                        />
                    </div>
                    <InputError message={form.errors.enabled} />

                    <div className="space-y-1.5">
                        <FieldLabel htmlFor="ai_provider">Provider</FieldLabel>
                        <Select
                            value={form.data.provider}
                            onValueChange={(value) =>
                                form.setData('provider', value as AiProvider)
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
                                <SelectItem value="openai">OpenAI</SelectItem>
                                <SelectItem value="openrouter">
                                    OpenRouter
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError message={form.errors.provider} />
                        <p className="ml-0.5 text-[10px] text-muted-foreground/50">
                            Switching providers keeps both stored credentials.
                            Test selected provider uses the last saved settings,
                            not unsaved form values.
                        </p>
                    </div>
                </CardContent>
            </Card>

            <ProviderCredentialCard
                title="OpenAI"
                description="Used when OpenAI is the selected provider."
                hasApiKey={openai.has_api_key}
                apiKeyId="openai_api_key"
                apiKeyValue={form.data.openai_api_key}
                onApiKeyChange={(value) =>
                    form.setData('openai_api_key', value)
                }
                apiKeyError={form.errors.openai_api_key}
                modelId="openai_model"
                modelValue={form.data.openai_model}
                onModelChange={(value) => form.setData('openai_model', value)}
                modelError={form.errors.openai_model}
                canUpdate={canUpdate}
            />

            <ProviderCredentialCard
                title="OpenRouter"
                description="Used when OpenRouter is the selected provider."
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
            />

            {canUpdate ? (
                <div className="flex flex-wrap items-center gap-3">
                    <Button
                        type="submit"
                        disabled={form.processing}
                        className="h-11 rounded-xl px-6"
                    >
                        {form.processing ? <Spinner className="mr-2" /> : null}
                        Save AI Settings
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        disabled={testing}
                        onClick={() => void handleTestConnection()}
                        className="h-11 rounded-xl px-6"
                    >
                        {testing ? (
                            <Spinner className="mr-2" />
                        ) : (
                            <PlugZap className="mr-2 h-4 w-4" />
                        )}
                        Test selected provider
                    </Button>
                    {connectionStatus === 'connected' && connectionMessage ? (
                        <span className="inline-flex items-center gap-1.5 text-xs text-emerald-500">
                            <CheckCircle2 className="h-4 w-4" />
                            {connectionMessage}
                        </span>
                    ) : null}
                    {connectionStatus === 'failed' && connectionMessage ? (
                        <span className="inline-flex items-center gap-1.5 text-xs text-destructive">
                            <XCircle className="h-4 w-4" />
                            {connectionMessage}
                        </span>
                    ) : null}
                </div>
            ) : null}
        </form>
    );
}
