import { useForm, router } from '@inertiajs/react';
import {
    Camera,
    CheckCircle2,
    Clock,
    Info,
    Link2,
    PlugZap,
    Radio,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { SettingsSecretInput } from '@/components/settings/settings-secret-input';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { HikvisionDevicesSection } from '@/features/settings/hikvision-devices-section';
import type { HikvisionDevicesSectionProps } from '@/features/settings/hikvision-devices-section';
import { testHikvisionConnection } from '@/features/settings/test-hikvision-connection';
import { formatDisplayDateTime } from '@/lib/format-date';
import { toast } from '@/lib/toast';
import { cn } from '@/lib/utils';

type ConnectionStatus = 'idle' | 'connected' | 'failed';

export type HikvisionSettingsPanelProps = {
    settings: {
        api_host: string;
        api_key: string;
        api_secret: string;
        enabled: boolean;
        has_api_key: boolean;
        has_api_secret: boolean;
        is_configured: boolean;
        uses_env_fallback: boolean;
        webhook_verify_token: string;
        webhook_enabled: boolean;
        webhook_registered_at: string | null;
        webhook_last_event_at: string | null;
        has_webhook_verify_token: boolean;
        events_fetch_schedule_enabled: boolean;
        events_fetch_schedule_at: string;
        events_evening_fetch_schedule_enabled: boolean;
        events_evening_fetch_schedule_at: string;
        events_last_fetched_at: string | null;
    };
    webhook_url: string;
    scheduler_timezone: string;
    can: {
        update: boolean;
        webhook_manage: boolean;
    };
    devices?: HikvisionDevicesSectionProps['devices'];
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

function StatusItem({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="rounded-xl border border-border/80 bg-muted/20 px-4 py-3 dark:border-white/5 dark:bg-white/5">
            <p className="text-[10px] font-bold tracking-widest text-muted-foreground/60 uppercase">
                {label}
            </p>
            <div className="mt-1">{children}</div>
        </div>
    );
}

export function HikvisionSettingsPanel({
    settings,
    webhook_url,
    scheduler_timezone,
    can,
    devices,
}: HikvisionSettingsPanelProps) {
    const [connectionStatus, setConnectionStatus] =
        useState<ConnectionStatus>('idle');
    const [connectionMessage, setConnectionMessage] = useState<string | null>(
        null,
    );
    const [testing, setTesting] = useState(false);
    const [registeringWebhook, setRegisteringWebhook] = useState(false);

    const form = useForm({
        api_host: settings.api_host ?? '',
        api_key: settings.api_key ?? '',
        api_secret: settings.api_secret ?? '',
        enabled: settings.enabled ?? false,
        webhook_enabled: settings.webhook_enabled ?? false,
        webhook_verify_token: settings.webhook_verify_token ?? '',
        events_fetch_schedule_enabled:
            settings.events_fetch_schedule_enabled ?? false,
        events_fetch_schedule_at: settings.events_fetch_schedule_at ?? '18:00',
        events_evening_fetch_schedule_enabled:
            settings.events_evening_fetch_schedule_enabled ?? false,
        events_evening_fetch_schedule_at:
            settings.events_evening_fetch_schedule_at ?? '20:00',
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
            const result = await testHikvisionConnection(
                '/settings/application/hikvision/test',
                {
                    api_host: form.data.api_host,
                    api_key: form.data.api_key,
                    api_secret: form.data.api_secret,
                    enabled: form.data.enabled,
                },
            );

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
                error instanceof Error
                    ? error.message
                    : 'Hikvision connection test failed.';
            setConnectionStatus('failed');
            setConnectionMessage(message);
            toast.error(message);
        } finally {
            setTesting(false);
        }
    };

    const handleRegisterWebhook = () => {
        if (!can.webhook_manage || registeringWebhook) {
            return;
        }

        setRegisteringWebhook(true);

        router.post(
            '/settings/application/hikvision/webhook/register',
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success('Webhook registered with Hik-Connect.');
                },
                onError: (errors) => {
                    const message =
                        typeof errors.webhook === 'string'
                            ? errors.webhook
                            : 'Failed to register webhook.';
                    toast.error(message);
                },
                onFinish: () => {
                    setRegisteringWebhook(false);
                },
            },
        );
    };

    const webhookStatusLabel = settings.webhook_registered_at
        ? 'Registered'
        : settings.webhook_enabled
          ? 'Enabled (not registered)'
          : 'Not registered';

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
                <div className="flex items-start gap-3 rounded-xl border border-amber-500/20 bg-amber-500/5 px-4 py-3 text-sm text-amber-400">
                    <span className="mt-0.5 shrink-0">⚠</span>
                    <p>
                        Currently using values from{' '}
                        <code className="font-mono text-xs">.env</code> until
                        you save Hikvision settings here.
                    </p>
                </div>
            ) : null}

            <Card className="border-border/80 bg-card dark:border-white/5 dark:bg-white/5">
                <CardContent className="space-y-5 p-6">
                    <div className="flex items-center gap-4">
                        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl border border-primary/20 bg-primary/10">
                            <PlugZap className="h-5 w-5 text-primary" />
                        </div>
                        <div>
                            <h2 className="text-base font-bold tracking-tight">
                                Connection status
                            </h2>
                            <p className="mt-0.5 text-xs text-muted-foreground">
                                Test your Hik-Connect for Teams API credentials.
                            </p>
                        </div>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        <StatusItem label="Connection status">
                            <span className="inline-flex items-center gap-2 text-sm font-medium">
                                <span
                                    className={cn(
                                        'h-2.5 w-2.5 rounded-full',
                                        statusDotClass,
                                    )}
                                />
                                {statusLabel}
                            </span>
                        </StatusItem>
                        <StatusItem label="API host">
                            <span className="truncate font-mono text-sm">
                                {form.data.api_host || '—'}
                            </span>
                        </StatusItem>
                        <StatusItem label="Integration">
                            <span className="text-sm">
                                {settings.is_configured
                                    ? 'Configured'
                                    : 'Not configured'}
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
                            {testing ? (
                                <Spinner className="mr-2" />
                            ) : (
                                <PlugZap className="mr-2 h-4 w-4" />
                            )}
                            Test connection
                        </Button>
                    ) : null}
                </CardContent>
            </Card>

            <Alert className="border-primary/20 bg-primary/5">
                <Info className="h-4 w-4" />
                <AlertTitle>Hik-Connect for Teams OpenAPI</AlertTitle>
                <AlertDescription>
                    Generate your app key and secret from Hik-Connect Team
                    Management → API Integration. Use your regional host, for
                    example{' '}
                    <span className="font-mono text-xs">
                        https://isgp.hikcentralconnect.com
                    </span>{' '}
                    for Singapore/India.
                </AlertDescription>
            </Alert>

            <form onSubmit={submit} className="space-y-6">
                <Card className="border-border/80 bg-card dark:border-white/5 dark:bg-white/5">
                    <CardContent className="space-y-6 p-6">
                        <div className="flex items-center gap-4">
                            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl border border-primary/20 bg-primary/10">
                                <Camera className="h-5 w-5 text-primary" />
                            </div>
                            <div>
                                <h2 className="text-base font-bold tracking-tight">
                                    API credentials
                                </h2>
                                <p className="mt-0.5 text-xs text-muted-foreground">
                                    HikCentral Connect OpenAPI credentials for
                                    access control and attendance integration.
                                </p>
                            </div>
                        </div>

                        <div className="flex items-center justify-between rounded-xl border border-border/80 bg-muted/20 px-4 py-3 dark:border-white/5 dark:bg-white/5">
                            <div>
                                <p className="text-sm font-medium">
                                    Enable Hikvision integration
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    Required before syncing persons, devices, or
                                    attendance data.
                                </p>
                            </div>
                            <Switch
                                checked={form.data.enabled}
                                onCheckedChange={(checked) =>
                                    form.setData('enabled', checked)
                                }
                                disabled={!can.update}
                            />
                        </div>

                        <div className="grid gap-5 sm:grid-cols-2">
                            <div className="space-y-1.5 sm:col-span-2">
                                <FieldLabel htmlFor="api_host">
                                    API host
                                </FieldLabel>
                                <FieldInput
                                    id="api_host"
                                    type="url"
                                    value={form.data.api_host}
                                    onChange={(e) =>
                                        form.setData('api_host', e.target.value)
                                    }
                                    placeholder="https://isgp.hikcentralconnect.com"
                                    disabled={!can.update}
                                    autoComplete="off"
                                />
                                <InputError message={form.errors.api_host} />
                            </div>

                            <div className="space-y-1.5 sm:col-span-2">
                                <FieldLabel htmlFor="api_key">
                                    API key
                                </FieldLabel>
                                <SettingsSecretInput
                                    id="api_key"
                                    value={form.data.api_key}
                                    onChange={(e) =>
                                        form.setData('api_key', e.target.value)
                                    }
                                    placeholder="App key (AK)"
                                    disabled={!can.update}
                                    autoComplete="new-password"
                                />
                                <InputError message={form.errors.api_key} />
                            </div>

                            <div className="space-y-1.5 sm:col-span-2">
                                <FieldLabel htmlFor="api_secret">
                                    API secret
                                </FieldLabel>
                                <SettingsSecretInput
                                    id="api_secret"
                                    value={form.data.api_secret}
                                    onChange={(e) =>
                                        form.setData(
                                            'api_secret',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="App secret (SK)"
                                    disabled={!can.update}
                                    autoComplete="new-password"
                                />
                                <InputError message={form.errors.api_secret} />
                            </div>
                        </div>

                        {can.update ? (
                            <div className="flex justify-end">
                                <Button
                                    type="submit"
                                    className="h-11 rounded-xl px-6"
                                    disabled={form.processing}
                                >
                                    {form.processing ? <Spinner /> : null}
                                    Save Hikvision settings
                                </Button>
                            </div>
                        ) : null}
                    </CardContent>
                </Card>

                <Card className="border-border/80 bg-card dark:border-white/5 dark:bg-white/5">
                    <CardContent className="space-y-5 p-6">
                        <div className="flex items-center gap-4">
                            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl border border-primary/20 bg-primary/10">
                                <Clock className="h-5 w-5 text-primary" />
                            </div>
                            <div>
                                <h2 className="text-base font-bold tracking-tight">
                                    Automatic daily fetch
                                </h2>
                                <p className="mt-0.5 text-xs text-muted-foreground">
                                    Pull yesterday&apos;s door and mobile app
                                    access records from Hik-Connect on a
                                    schedule. Today is covered by webhooks and
                                    the evening fetch.
                                </p>
                            </div>
                        </div>

                        <Alert className="border-primary/20 bg-primary/5">
                            <Info className="h-4 w-4" />
                            <AlertTitle>Server cron required</AlertTitle>
                            <AlertDescription>
                                Laravel scheduler must run every minute on the
                                server, for example{' '}
                                <span className="font-mono text-xs">
                                    * * * * * php artisan schedule:run
                                </span>
                                . A queue worker is also required to process the
                                fetch job.
                            </AlertDescription>
                        </Alert>

                        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                            <StatusItem label="Last fetch">
                                <span className="text-sm">
                                    {settings.events_last_fetched_at
                                        ? formatDisplayDateTime(
                                              settings.events_last_fetched_at,
                                          )
                                        : 'Never'}
                                </span>
                            </StatusItem>
                            <StatusItem label="Scheduled time">
                                <span className="text-sm">
                                    {form.data.events_fetch_schedule_enabled
                                        ? `${form.data.events_fetch_schedule_at} (${scheduler_timezone})`
                                        : 'Disabled'}
                                </span>
                            </StatusItem>
                            <StatusItem label="Fetch scope">
                                <span className="text-sm">
                                    Yesterday and today
                                </span>
                            </StatusItem>
                        </div>

                        <div className="flex items-center justify-between rounded-xl border border-border/80 bg-muted/20 px-4 py-3 dark:border-white/5 dark:bg-white/5">
                            <div>
                                <p className="text-sm font-medium">
                                    Enable daily fetch
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    Fetches yesterday (mobile backfill) and
                                    today (door + mobile). Manual fetch on
                                    Access Events can target a single date.
                                </p>
                            </div>
                            <Switch
                                checked={
                                    form.data.events_fetch_schedule_enabled
                                }
                                onCheckedChange={(checked) =>
                                    form.setData(
                                        'events_fetch_schedule_enabled',
                                        checked,
                                    )
                                }
                                disabled={
                                    !can.update || !settings.is_configured
                                }
                            />
                        </div>

                        {form.data.events_fetch_schedule_enabled ? (
                            <div className="max-w-xs space-y-1.5">
                                <FieldLabel htmlFor="events_fetch_schedule_at">
                                    Daily fetch time
                                </FieldLabel>
                                <FieldInput
                                    id="events_fetch_schedule_at"
                                    type="time"
                                    value={form.data.events_fetch_schedule_at}
                                    onChange={(event) =>
                                        form.setData(
                                            'events_fetch_schedule_at',
                                            event.target.value,
                                        )
                                    }
                                    disabled={!can.update}
                                />
                                <p className="text-xs text-muted-foreground">
                                    Uses application timezone (
                                    {scheduler_timezone}). Mobile app records
                                    are usually available the next day;
                                    yesterday is included on each run for
                                    backfill.
                                </p>
                                <InputError
                                    message={
                                        form.errors.events_fetch_schedule_at
                                    }
                                />
                            </div>
                        ) : null}

                        {can.update ? (
                            <div className="flex justify-end">
                                <Button
                                    type="submit"
                                    className="h-11 rounded-xl px-6"
                                    disabled={form.processing}
                                >
                                    {form.processing ? <Spinner /> : null}
                                    Save Hikvision settings
                                </Button>
                            </div>
                        ) : null}
                    </CardContent>
                </Card>

                <Card className="border-border/80 bg-card dark:border-white/5 dark:bg-white/5">
                    <CardContent className="space-y-5 p-6">
                        <div className="flex items-center gap-4">
                            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl border border-primary/20 bg-primary/10">
                                <Clock className="h-5 w-5 text-primary" />
                            </div>
                            <div>
                                <h2 className="text-base font-bold tracking-tight">
                                    Evening fetch (manual-style)
                                </h2>
                                <p className="mt-0.5 text-xs text-muted-foreground">
                                    Runs the same fetch as manual Access Events
                                    for today&apos;s date — ideal after mobile
                                    check-outs are complete.
                                </p>
                            </div>
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                            <StatusItem label="Scheduled time">
                                <span className="text-sm">
                                    {form.data
                                        .events_evening_fetch_schedule_enabled
                                        ? `${form.data.events_evening_fetch_schedule_at} (${scheduler_timezone})`
                                        : 'Disabled'}
                                </span>
                            </StatusItem>
                            <StatusItem label="Fetch scope">
                                <span className="text-sm">
                                    Today (manual fetch path)
                                </span>
                            </StatusItem>
                            <StatusItem label="Separate from morning">
                                <span className="text-sm">
                                    Independent schedule
                                </span>
                            </StatusItem>
                        </div>

                        <div className="flex items-center justify-between rounded-xl border border-border/80 bg-muted/20 px-4 py-3 dark:border-white/5 dark:bg-white/5">
                            <div>
                                <p className="text-sm font-medium">
                                    Enable evening fetch
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    Fetches today&apos;s door and mobile records
                                    using the manual fetch job. Does not change
                                    the morning schedule above.
                                </p>
                            </div>
                            <Switch
                                checked={
                                    form.data
                                        .events_evening_fetch_schedule_enabled
                                }
                                onCheckedChange={(checked) =>
                                    form.setData(
                                        'events_evening_fetch_schedule_enabled',
                                        checked,
                                    )
                                }
                                disabled={
                                    !can.update || !settings.is_configured
                                }
                            />
                        </div>

                        {form.data.events_evening_fetch_schedule_enabled ? (
                            <div className="max-w-xs space-y-1.5">
                                <FieldLabel htmlFor="events_evening_fetch_schedule_at">
                                    Evening fetch time
                                </FieldLabel>
                                <FieldInput
                                    id="events_evening_fetch_schedule_at"
                                    type="time"
                                    value={
                                        form.data
                                            .events_evening_fetch_schedule_at
                                    }
                                    onChange={(event) =>
                                        form.setData(
                                            'events_evening_fetch_schedule_at',
                                            event.target.value,
                                        )
                                    }
                                    disabled={!can.update}
                                />
                                <p className="text-xs text-muted-foreground">
                                    Uses application timezone (
                                    {scheduler_timezone}). Recommended around
                                    20:00 after mobile check-outs.
                                </p>
                                <InputError
                                    message={
                                        form.errors
                                            .events_evening_fetch_schedule_at
                                    }
                                />
                            </div>
                        ) : null}

                        {can.update ? (
                            <div className="flex justify-end">
                                <Button
                                    type="submit"
                                    className="h-11 rounded-xl px-6"
                                    disabled={form.processing}
                                >
                                    {form.processing ? <Spinner /> : null}
                                    Save Hikvision settings
                                </Button>
                            </div>
                        ) : null}
                    </CardContent>
                </Card>

                <Card className="border-border/80 bg-card dark:border-white/5 dark:bg-white/5">
                    <CardContent className="space-y-5 p-6">
                        <div className="flex items-center gap-4">
                            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl border border-primary/20 bg-primary/10">
                                <Radio className="h-5 w-5 text-primary" />
                            </div>
                            <div>
                                <h2 className="text-base font-bold tracking-tight">
                                    Webhook push
                                </h2>
                                <p className="mt-0.5 text-xs text-muted-foreground">
                                    Receive real-time access events from
                                    Hik-Connect at your public callback URL.
                                </p>
                            </div>
                        </div>

                        <Alert className="border-amber-500/20 bg-amber-500/5">
                            <Info className="h-4 w-4" />
                            <AlertTitle>Public HTTPS URL required</AlertTitle>
                            <AlertDescription>
                                Hik-Connect must reach your callback URL over
                                the public internet. Local development requires
                                a tunnel (for example ngrok) pointing to this
                                app.
                            </AlertDescription>
                        </Alert>

                        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                            <StatusItem label="Webhook status">
                                <span className="text-sm font-medium">
                                    {webhookStatusLabel}
                                </span>
                            </StatusItem>
                            <StatusItem label="Last event received">
                                <span className="text-sm">
                                    {settings.webhook_last_event_at
                                        ? formatDisplayDateTime(
                                              settings.webhook_last_event_at,
                                          )
                                        : 'Never'}
                                </span>
                            </StatusItem>
                            <StatusItem label="Registered at">
                                <span className="text-sm">
                                    {settings.webhook_registered_at
                                        ? formatDisplayDateTime(
                                              settings.webhook_registered_at,
                                          )
                                        : '—'}
                                </span>
                            </StatusItem>
                        </div>

                        <div className="space-y-1.5">
                            <FieldLabel>Callback URL</FieldLabel>
                            <div className="flex items-center gap-2 rounded-xl border border-border/80 bg-muted/20 px-4 py-3 dark:border-white/10 dark:bg-white/5">
                                <Link2 className="h-4 w-4 shrink-0 text-muted-foreground/50" />
                                <span className="truncate font-mono text-xs">
                                    {webhook_url}
                                </span>
                            </div>
                        </div>

                        <div className="space-y-1.5">
                            <FieldLabel htmlFor="webhook_verify_token">
                                Sign secret
                            </FieldLabel>
                            <SettingsSecretInput
                                id="webhook_verify_token"
                                value={form.data.webhook_verify_token}
                                onChange={(event) =>
                                    form.setData(
                                        'webhook_verify_token',
                                        event.target.value,
                                    )
                                }
                                placeholder="Auto-generated on save, or use 8-32 letters/digits"
                                disabled={!can.update}
                                autoComplete="off"
                            />
                            <InputError
                                message={form.errors.webhook_verify_token}
                            />
                        </div>

                        <div className="flex items-center justify-between rounded-xl border border-border/80 bg-muted/20 px-4 py-3 dark:border-white/5 dark:bg-white/5">
                            <div>
                                <p className="text-sm font-medium">
                                    Enable webhook ingestion
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    Accept inbound events at the callback URL
                                    using the verify token.
                                </p>
                            </div>
                            <Switch
                                checked={form.data.webhook_enabled}
                                onCheckedChange={(checked) =>
                                    form.setData('webhook_enabled', checked)
                                }
                                disabled={!can.update}
                            />
                        </div>

                        {can.webhook_manage ? (
                            <Button
                                type="button"
                                variant="outline"
                                className="rounded-xl"
                                disabled={
                                    !settings.is_configured ||
                                    registeringWebhook
                                }
                                onClick={handleRegisterWebhook}
                            >
                                {registeringWebhook ? (
                                    <Spinner className="mr-2" />
                                ) : (
                                    <Radio className="mr-2 h-4 w-4" />
                                )}
                                Register webhook
                            </Button>
                        ) : null}
                    </CardContent>
                </Card>
            </form>

            {devices ? (
                <HikvisionDevicesSection
                    devices={devices}
                    isConfigured={settings.is_configured}
                />
            ) : null}
        </div>
    );
}
