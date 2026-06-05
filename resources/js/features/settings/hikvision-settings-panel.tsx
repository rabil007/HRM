import { useForm } from '@inertiajs/react';
import {
    Camera,
    CheckCircle2,
    Info,
    Lock,
    PlugZap,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { testHikvisionConnection } from '@/features/settings/test-hikvision-connection';
import { toast } from '@/lib/toast';
import { cn } from '@/lib/utils';

type ConnectionStatus = 'idle' | 'connected' | 'failed';

export type HikvisionSettingsPanelProps = {
    settings: {
        api_host: string;
        enabled: boolean;
        has_api_key: boolean;
        has_api_secret: boolean;
        is_configured: boolean;
        uses_env_fallback: boolean;
    };
    can: {
        update: boolean;
    };
};

function FieldLabel({ htmlFor, children }: { htmlFor?: string; children: React.ReactNode }) {
    return (
        <Label
            htmlFor={htmlFor}
            className="text-[10px] uppercase tracking-widest text-muted-foreground/60 font-bold ml-0.5"
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
                'rounded-xl border-white/10 bg-white/5 focus-visible:ring-primary/40 h-11 px-4 transition-all',
                props.className,
            )}
        />
    );
}

function StatusItem({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="rounded-xl border border-white/5 bg-white/5 px-4 py-3">
            <p className="text-[10px] uppercase tracking-widest text-muted-foreground/60 font-bold">
                {label}
            </p>
            <div className="mt-1">{children}</div>
        </div>
    );
}

export function HikvisionSettingsPanel({ settings, can }: HikvisionSettingsPanelProps) {
    const [connectionStatus, setConnectionStatus] = useState<ConnectionStatus>('idle');
    const [connectionMessage, setConnectionMessage] = useState<string | null>(null);
    const [testing, setTesting] = useState(false);

    const form = useForm({
        api_host: settings.api_host ?? '',
        api_key: '',
        api_secret: '',
        enabled: settings.enabled ?? false,
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        if (!can.update) {
            return;
        }

        form.put('/settings/application/hikvision', {
            preserveScroll: true,
            onSuccess: () => {
                form.setData('api_key', '');
                form.setData('api_secret', '');
                toast.success('Hikvision settings saved.');
            },
        });
    };

    const handleTestConnection = async () => {
        if (!can.update) {
            return;
        }

        setTesting(true);
        setConnectionMessage(null);

        try {
            const result = await testHikvisionConnection('/settings/application/hikvision/test', {
                api_host: form.data.api_host,
                api_key: form.data.api_key,
                api_secret: form.data.api_secret,
                enabled: form.data.enabled,
            });

            if (result.success) {
                setConnectionStatus('connected');
                setConnectionMessage(result.message);
                toast.success(result.message);
            } else {
                setConnectionStatus('failed');
                setConnectionMessage(result.message);
                toast.error(result.message);
            }
        } catch (error) {
            const message =
                error instanceof Error ? error.message : 'Hikvision connection test failed.';
            setConnectionStatus('failed');
            setConnectionMessage(message);
            toast.error(message);
        } finally {
            setTesting(false);
        }
    };

    const statusLabel =
        connectionStatus === 'connected'
            ? 'Connected'
            : connectionStatus === 'failed'
              ? 'Not connected'
              : 'Not connected';

    const statusDotClass =
        connectionStatus === 'connected'
            ? 'bg-emerald-500'
            : connectionStatus === 'failed'
              ? 'bg-destructive'
              : 'bg-muted-foreground/40';

    return (
        <div className="space-y-6">
            {settings.uses_env_fallback ? (
                <div className="flex items-start gap-3 px-4 py-3 rounded-xl border border-amber-500/20 bg-amber-500/5 text-amber-400 text-sm">
                    <span className="mt-0.5 shrink-0">⚠</span>
                    <p>
                        Currently using values from <code className="font-mono text-xs">.env</code>{' '}
                        until you save Hikvision settings here.
                    </p>
                </div>
            ) : null}

            <Card className="border-white/5 bg-white/5">
                <CardContent className="p-6 space-y-5">
                    <div className="flex items-center gap-4">
                        <div className="w-10 h-10 rounded-2xl border border-blue-500/20 bg-blue-500/10 flex items-center justify-center shrink-0">
                            <PlugZap className="w-5 h-5 text-blue-500" />
                        </div>
                        <div>
                            <h2 className="text-base font-bold tracking-tight">Connection status</h2>
                            <p className="text-xs text-muted-foreground mt-0.5">
                                Test your Hik-Connect for Teams API credentials.
                            </p>
                        </div>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        <StatusItem label="Connection status">
                            <span className="inline-flex items-center gap-2 text-sm font-medium">
                                <span className={cn('h-2.5 w-2.5 rounded-full', statusDotClass)} />
                                {statusLabel}
                            </span>
                        </StatusItem>
                        <StatusItem label="API host">
                            <span className="text-sm font-mono truncate">
                                {form.data.api_host || '—'}
                            </span>
                        </StatusItem>
                        <StatusItem label="Integration">
                            <span className="text-sm">
                                {settings.is_configured ? 'Configured' : 'Not configured'}
                            </span>
                        </StatusItem>
                    </div>

                    {connectionMessage ? (
                        <div
                            className={cn(
                                'flex items-start gap-3 rounded-xl border px-4 py-3 text-sm',
                                connectionStatus === 'connected'
                                    ? 'border-emerald-500/20 bg-emerald-500/5 text-emerald-600'
                                    : 'border-destructive/20 bg-destructive/5 text-destructive',
                            )}
                        >
                            {connectionStatus === 'connected' ? (
                                <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0" />
                            ) : (
                                <XCircle className="mt-0.5 h-4 w-4 shrink-0" />
                            )}
                            <p>{connectionMessage}</p>
                        </div>
                    ) : null}

                    {can.update ? (
                        <Button
                            type="button"
                            variant="outline"
                            className="rounded-xl"
                            disabled={testing || !form.data.api_host}
                            onClick={handleTestConnection}
                        >
                            {testing ? <Spinner className="mr-2" /> : <PlugZap className="mr-2 h-4 w-4" />}
                            Test connection
                        </Button>
                    ) : null}
                </CardContent>
            </Card>

            <Alert className="border-blue-500/20 bg-blue-500/5">
                <Info className="h-4 w-4" />
                <AlertTitle>Hik-Connect for Teams OpenAPI</AlertTitle>
                <AlertDescription>
                    Generate your app key and secret from Hik-Connect Team Management → API
                    Integration. Use your regional host, for example{' '}
                    <span className="font-mono text-xs">https://isgp.hikcentralconnect.com</span>{' '}
                    for Singapore/India.
                </AlertDescription>
            </Alert>

            <form onSubmit={submit} className="space-y-6">
                <Card className="border-white/5 bg-white/5">
                    <CardContent className="p-6 space-y-6">
                        <div className="flex items-center gap-4">
                            <div className="w-10 h-10 rounded-2xl border border-blue-500/20 bg-blue-500/10 flex items-center justify-center shrink-0">
                                <Camera className="w-5 h-5 text-blue-500" />
                            </div>
                            <div>
                                <h2 className="text-base font-bold tracking-tight">API credentials</h2>
                                <p className="text-xs text-muted-foreground mt-0.5">
                                    HikCentral Connect OpenAPI credentials for access control and
                                    attendance integration.
                                </p>
                            </div>
                        </div>

                        <div className="flex items-center justify-between rounded-xl border border-white/5 bg-white/5 px-4 py-3">
                            <div>
                                <p className="text-sm font-medium">Enable Hikvision integration</p>
                                <p className="text-xs text-muted-foreground">
                                    Required before syncing users or attendance data.
                                </p>
                            </div>
                            <Switch
                                checked={form.data.enabled}
                                onCheckedChange={(checked) => form.setData('enabled', checked)}
                                disabled={!can.update}
                            />
                        </div>

                        <div className="grid gap-5 sm:grid-cols-2">
                            <div className="space-y-1.5 sm:col-span-2">
                                <FieldLabel htmlFor="api_host">API host</FieldLabel>
                                <FieldInput
                                    id="api_host"
                                    type="url"
                                    value={form.data.api_host}
                                    onChange={(e) => form.setData('api_host', e.target.value)}
                                    placeholder="https://isgp.hikcentralconnect.com"
                                    disabled={!can.update}
                                    autoComplete="off"
                                />
                                <InputError message={form.errors.api_host} />
                            </div>

                            <div className="space-y-1.5 sm:col-span-2">
                                <FieldLabel htmlFor="api_key">API key</FieldLabel>
                                <div className="relative">
                                    <Lock className="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground/40 pointer-events-none" />
                                    <FieldInput
                                        id="api_key"
                                        type="password"
                                        value={form.data.api_key}
                                        onChange={(e) => form.setData('api_key', e.target.value)}
                                        placeholder={
                                            settings.has_api_key
                                                ? 'Leave blank to keep current API key'
                                                : 'App key (AK)'
                                        }
                                        disabled={!can.update}
                                        autoComplete="new-password"
                                        className="pl-10"
                                    />
                                </div>
                                <InputError message={form.errors.api_key} />
                            </div>

                            <div className="space-y-1.5 sm:col-span-2">
                                <FieldLabel htmlFor="api_secret">API secret</FieldLabel>
                                <div className="relative">
                                    <Lock className="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground/40 pointer-events-none" />
                                    <FieldInput
                                        id="api_secret"
                                        type="password"
                                        value={form.data.api_secret}
                                        onChange={(e) => form.setData('api_secret', e.target.value)}
                                        placeholder={
                                            settings.has_api_secret
                                                ? 'Leave blank to keep current API secret'
                                                : 'App secret (SK)'
                                        }
                                        disabled={!can.update}
                                        autoComplete="new-password"
                                        className="pl-10"
                                    />
                                </div>
                                <InputError message={form.errors.api_secret} />
                            </div>
                        </div>

                        {can.update ? (
                            <div className="flex justify-end">
                                <Button
                                    type="submit"
                                    className="rounded-xl h-11 px-6"
                                    disabled={form.processing}
                                >
                                    {form.processing ? <Spinner /> : null}
                                    Save Hikvision settings
                                </Button>
                            </div>
                        ) : null}
                    </CardContent>
                </Card>
            </form>
        </div>
    );
}
