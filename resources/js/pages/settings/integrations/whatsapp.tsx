import { Head, useForm } from '@inertiajs/react';
import {
    CheckCircle2,
    Copy,
    Link2,
    Lock,
    MessageCircle,
    PlugZap,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { testWhatsAppConnection } from '@/features/settings/test-whatsapp-connection';
import { toast } from '@/lib/toast';
import { cn } from '@/lib/utils';

type ConnectionStatus = 'idle' | 'connected' | 'failed';

type Props = {
    settings: {
        business_account_id: string;
        phone_number_id: string;
        app_id: string;
        webhook_verify_token: string;
        enabled: boolean;
        has_access_token: boolean;
        has_app_secret: boolean;
        is_configured: boolean;
        webhook_status: 'configured' | 'not_configured';
    };
    callback_url: string;
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

export default function WhatsAppIntegration({ settings, callback_url, can }: Props) {
    const [connectionStatus, setConnectionStatus] = useState<ConnectionStatus>('idle');
    const [connectionMessage, setConnectionMessage] = useState<string | null>(null);
    const [testing, setTesting] = useState(false);

    const form = useForm({
        business_account_id: settings.business_account_id ?? '',
        phone_number_id: settings.phone_number_id ?? '',
        access_token: '',
        app_id: settings.app_id ?? '',
        app_secret: '',
        webhook_verify_token: settings.webhook_verify_token ?? '',
        enabled: settings.enabled ?? false,
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        if (!can.update) {
            return;
        }

        form.put('/settings/integrations/whatsapp', {
            preserveScroll: true,
            onSuccess: () => {
                form.setData('access_token', '');
                form.setData('app_secret', '');
                toast.success('WhatsApp settings saved.');
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
            const result = await testWhatsAppConnection('/settings/integrations/whatsapp/test', {
                business_account_id: form.data.business_account_id,
                phone_number_id: form.data.phone_number_id,
                access_token: form.data.access_token,
                app_id: form.data.app_id,
                app_secret: form.data.app_secret,
                webhook_verify_token: form.data.webhook_verify_token,
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
                error instanceof Error ? error.message : 'WhatsApp connection test failed.';
            setConnectionStatus('failed');
            setConnectionMessage(message);
            toast.error(message);
        } finally {
            setTesting(false);
        }
    };

    const copyCallbackUrl = async () => {
        try {
            await navigator.clipboard.writeText(callback_url);
            toast.success('Callback URL copied.');
        } catch {
            toast.error('Unable to copy callback URL.');
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

    const webhookStatusLabel =
        settings.webhook_status === 'configured' ? 'Verify token set' : 'Not configured';

    return (
        <>
            <Head title="WhatsApp integration" />

            <div className="space-y-6">
                <Heading
                    title="WhatsApp"
                    description="Configure WhatsApp Business API credentials for document delivery and employee notifications."
                />

                <Card className="border-white/5 bg-white/5">
                    <CardContent className="p-6 space-y-5">
                        <div className="flex items-center gap-4">
                            <div className="w-10 h-10 rounded-2xl border border-green-500/20 bg-green-500/10 flex items-center justify-center shrink-0">
                                <PlugZap className="w-5 h-5 text-green-600" />
                            </div>
                            <div>
                                <h2 className="text-base font-bold tracking-tight">Connection status</h2>
                                <p className="text-xs text-muted-foreground mt-0.5">
                                    Test your credentials after saving or updating values below.
                                </p>
                            </div>
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                            <StatusItem label="Connection status">
                                <span className="inline-flex items-center gap-2 text-sm font-medium">
                                    <span className={cn('h-2.5 w-2.5 rounded-full', statusDotClass)} />
                                    {statusLabel}
                                </span>
                            </StatusItem>
                            <StatusItem label="Business account ID">
                                <span className="text-sm font-mono truncate">
                                    {form.data.business_account_id || '—'}
                                </span>
                            </StatusItem>
                            <StatusItem label="Phone number ID">
                                <span className="text-sm font-mono truncate">
                                    {form.data.phone_number_id || '—'}
                                </span>
                            </StatusItem>
                            <StatusItem label="Webhook status">
                                <span className="text-sm">{webhookStatusLabel}</span>
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
                    </CardContent>
                </Card>

                <form onSubmit={submit} className="space-y-6">
                    <Card className="border-white/5 bg-white/5">
                        <CardContent className="p-6 space-y-6">
                            <div className="flex items-center gap-4">
                                <div className="w-10 h-10 rounded-2xl border border-green-500/20 bg-green-500/10 flex items-center justify-center shrink-0">
                                    <MessageCircle className="w-5 h-5 text-green-600" />
                                </div>
                                <div>
                                    <h2 className="text-base font-bold tracking-tight">API credentials</h2>
                                    <p className="text-xs text-muted-foreground mt-0.5">
                                        Paste your Meta WhatsApp Business Cloud API values.
                                    </p>
                                </div>
                            </div>

                            <div className="grid gap-5 sm:grid-cols-2">
                                <div className="space-y-1.5 sm:col-span-2">
                                    <FieldLabel htmlFor="business_account_id">
                                        WhatsApp business account ID
                                    </FieldLabel>
                                    <FieldInput
                                        id="business_account_id"
                                        value={form.data.business_account_id}
                                        onChange={(e) =>
                                            form.setData('business_account_id', e.target.value)
                                        }
                                        disabled={!can.update}
                                        autoComplete="off"
                                    />
                                    <InputError message={form.errors.business_account_id} />
                                </div>

                                <div className="space-y-1.5">
                                    <FieldLabel htmlFor="phone_number_id">Phone number ID</FieldLabel>
                                    <FieldInput
                                        id="phone_number_id"
                                        value={form.data.phone_number_id}
                                        onChange={(e) =>
                                            form.setData('phone_number_id', e.target.value)
                                        }
                                        disabled={!can.update}
                                        autoComplete="off"
                                    />
                                    <InputError message={form.errors.phone_number_id} />
                                </div>

                                <div className="space-y-1.5">
                                    <FieldLabel htmlFor="app_id">App ID</FieldLabel>
                                    <FieldInput
                                        id="app_id"
                                        value={form.data.app_id}
                                        onChange={(e) => form.setData('app_id', e.target.value)}
                                        disabled={!can.update}
                                        autoComplete="off"
                                    />
                                    <InputError message={form.errors.app_id} />
                                </div>

                                <div className="space-y-1.5 sm:col-span-2">
                                    <FieldLabel htmlFor="access_token">Access token</FieldLabel>
                                    <div className="relative">
                                        <Lock className="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground/40 pointer-events-none" />
                                        <FieldInput
                                            id="access_token"
                                            type="password"
                                            value={form.data.access_token}
                                            onChange={(e) =>
                                                form.setData('access_token', e.target.value)
                                            }
                                            placeholder={
                                                settings.has_access_token
                                                    ? 'Leave blank to keep current access token'
                                                    : 'Permanent access token'
                                            }
                                            disabled={!can.update}
                                            autoComplete="new-password"
                                            className="pl-10"
                                        />
                                    </div>
                                    <InputError message={form.errors.access_token} />
                                </div>

                                <div className="space-y-1.5 sm:col-span-2">
                                    <FieldLabel htmlFor="app_secret">App secret</FieldLabel>
                                    <div className="relative">
                                        <Lock className="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground/40 pointer-events-none" />
                                        <FieldInput
                                            id="app_secret"
                                            type="password"
                                            value={form.data.app_secret}
                                            onChange={(e) =>
                                                form.setData('app_secret', e.target.value)
                                            }
                                            placeholder={
                                                settings.has_app_secret
                                                    ? 'Leave blank to keep current app secret'
                                                    : 'App secret'
                                            }
                                            disabled={!can.update}
                                            autoComplete="new-password"
                                            className="pl-10"
                                        />
                                    </div>
                                    <InputError message={form.errors.app_secret} />
                                </div>

                                <div className="space-y-1.5 sm:col-span-2">
                                    <FieldLabel htmlFor="webhook_verify_token">
                                        Webhook verify token
                                    </FieldLabel>
                                    <FieldInput
                                        id="webhook_verify_token"
                                        value={form.data.webhook_verify_token}
                                        onChange={(e) =>
                                            form.setData('webhook_verify_token', e.target.value)
                                        }
                                        disabled={!can.update}
                                        autoComplete="off"
                                    />
                                    <InputError message={form.errors.webhook_verify_token} />
                                </div>

                                <div className="space-y-1.5 sm:col-span-2">
                                    <FieldLabel htmlFor="callback_url">Callback URL</FieldLabel>
                                    <div className="flex gap-2">
                                        <div className="relative flex-1">
                                            <Link2 className="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground/40 pointer-events-none" />
                                            <FieldInput
                                                id="callback_url"
                                                value={callback_url}
                                                readOnly
                                                className="pl-10 font-mono text-xs"
                                            />
                                        </div>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={copyCallbackUrl}
                                            className="shrink-0 rounded-xl"
                                        >
                                            <Copy className="h-4 w-4" />
                                            Copy
                                        </Button>
                                    </div>
                                    <p className="text-xs text-muted-foreground">
                                        Register this URL in your Meta app webhook settings.
                                    </p>
                                </div>

                                <div className="flex items-center justify-between rounded-xl border border-white/10 bg-white/5 px-4 py-3 sm:col-span-2">
                                    <div>
                                        <p className="text-sm font-medium">Enabled</p>
                                        <p className="text-xs text-muted-foreground">
                                            Allow modules to send WhatsApp messages using these
                                            credentials.
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
                            </div>
                        </CardContent>
                    </Card>

                    {can.update ? (
                        <div className="flex flex-wrap items-center gap-3">
                            <Button
                                type="submit"
                                disabled={form.processing}
                                className="rounded-xl"
                            >
                                {form.processing ? <Spinner className="mr-2" /> : null}
                                Save settings
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                disabled={testing}
                                onClick={handleTestConnection}
                                className="rounded-xl"
                            >
                                {testing ? <Spinner className="mr-2" /> : null}
                                Test connection
                            </Button>
                        </div>
                    ) : null}
                </form>
            </div>
        </>
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
        <div className="rounded-xl border border-white/10 bg-white/5 px-4 py-3 space-y-1">
            <p className="text-[10px] uppercase tracking-widest text-muted-foreground/60 font-bold">
                {label}
            </p>
            {children}
        </div>
    );
}
