import { Head, useForm } from '@inertiajs/react';
import {
    CheckCircle2,
    Copy,
    FileUp,
    Link2,
    Lock,
    MessageCircle,
    PlugZap,
    Send,
    XCircle,
} from 'lucide-react';
import { useRef, useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import {
    sendWhatsAppTestDocument,
    sendWhatsAppTestText,
    type WhatsAppTestSendResult,
} from '@/features/settings/send-whatsapp-test-message';
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
    default_test_message: string;
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

export default function WhatsAppIntegration({
    settings,
    callback_url,
    default_test_message,
    can,
}: Props) {
    const [connectionStatus, setConnectionStatus] = useState<ConnectionStatus>('idle');
    const [connectionMessage, setConnectionMessage] = useState<string | null>(null);
    const [testing, setTesting] = useState(false);
    const [testPhone, setTestPhone] = useState('');
    const [testMessage, setTestMessage] = useState(default_test_message);
    const [testCaption, setTestCaption] = useState('');
    const [testFile, setTestFile] = useState<File | null>(null);
    const [sendingText, setSendingText] = useState(false);
    const [sendingDocument, setSendingDocument] = useState(false);
    const [testResult, setTestResult] = useState<WhatsAppTestSendResult | null>(null);
    const fileInputRef = useRef<HTMLInputElement>(null);

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

    const canSendTests = settings.is_configured;

    const handleSendTestText = async () => {
        if (!can.update || !canSendTests) {
            return;
        }

        const phone = testPhone.trim();
        const message = testMessage.trim();

        if (!phone) {
            toast.error('Enter a WhatsApp number with country code.');
            return;
        }

        if (!message) {
            toast.error('Enter a test message.');
            return;
        }

        setSendingText(true);
        setTestResult(null);

        try {
            const result = await sendWhatsAppTestText(phone, message);
            setTestResult(result);

            if (result.success) {
                toast.success(result.message);
            } else {
                toast.error(result.message);
            }
        } catch (error) {
            const message =
                error instanceof Error ? error.message : 'Failed to send WhatsApp test message.';
            toast.error(message);
        } finally {
            setSendingText(false);
        }
    };

    const handleSendTestDocument = async () => {
        if (!can.update || !canSendTests) {
            return;
        }

        const phone = testPhone.trim();

        if (!phone) {
            toast.error('Enter a WhatsApp number with country code.');
            return;
        }

        if (!testFile) {
            toast.error('Choose a file to send.');
            return;
        }

        setSendingDocument(true);
        setTestResult(null);

        try {
            const result = await sendWhatsAppTestDocument(phone, testFile, testCaption);
            setTestResult(result);

            if (result.success) {
                toast.success(result.message);
            } else {
                toast.error(result.message);
            }
        } catch (error) {
            const message =
                error instanceof Error ? error.message : 'Failed to send WhatsApp test document.';
            toast.error(message);
        } finally {
            setSendingDocument(false);
        }
    };

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

                {can.update ? (
                    <Card className="border-white/5 bg-white/5">
                        <CardContent className="p-6 space-y-5">
                            <div className="flex items-center gap-4">
                                <div className="w-10 h-10 rounded-2xl border border-green-500/20 bg-green-500/10 flex items-center justify-center shrink-0">
                                    <Send className="w-5 h-5 text-green-600" />
                                </div>
                                <div>
                                    <h2 className="text-base font-bold tracking-tight">Test messages</h2>
                                    <p className="text-xs text-muted-foreground mt-0.5">
                                        Send a text message or upload a file to verify delivery.
                                    </p>
                                </div>
                            </div>

                            <div className="grid gap-5 lg:grid-cols-2">
                                <div className="space-y-1.5">
                                    <FieldLabel htmlFor="test_phone">WhatsApp number</FieldLabel>
                                    <FieldInput
                                        id="test_phone"
                                        type="tel"
                                        value={testPhone}
                                        onChange={(e) => setTestPhone(e.target.value)}
                                        placeholder="+971501234567"
                                        autoComplete="tel"
                                        disabled={!canSendTests}
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Include country code. Recipient must be on your Meta test list
                                        or have an open messaging window.
                                    </p>
                                </div>

                                <div className="space-y-1.5">
                                    <FieldLabel htmlFor="test_message">Text message</FieldLabel>
                                    <Textarea
                                        id="test_message"
                                        value={testMessage}
                                        onChange={(e) => setTestMessage(e.target.value)}
                                        rows={4}
                                        disabled={!canSendTests}
                                        className="rounded-xl border-white/10 bg-white/5 focus-visible:ring-primary/40"
                                    />
                                </div>

                                <div className="space-y-1.5 lg:col-span-2">
                                    <FieldLabel htmlFor="test_file">Test file</FieldLabel>
                                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                                        <input
                                            ref={fileInputRef}
                                            id="test_file"
                                            type="file"
                                            accept=".pdf,.png,.jpg,.jpeg,.webp,.doc,.docx,.xls,.xlsx"
                                            disabled={!canSendTests}
                                            onChange={(event) => {
                                                setTestFile(event.target.files?.[0] ?? null);
                                            }}
                                            className="block w-full text-sm text-muted-foreground file:mr-4 file:rounded-lg file:border-0 file:bg-white/10 file:px-4 file:py-2 file:text-sm file:font-medium file:text-foreground hover:file:bg-white/15"
                                        />
                                        {testFile ? (
                                            <span className="text-xs text-muted-foreground">
                                                {testFile.name} ({Math.round(testFile.size / 1024)} KB)
                                            </span>
                                        ) : null}
                                    </div>
                                </div>

                                <div className="space-y-1.5 lg:col-span-2">
                                    <FieldLabel htmlFor="test_caption">
                                        Document caption (optional)
                                    </FieldLabel>
                                    <FieldInput
                                        id="test_caption"
                                        value={testCaption}
                                        onChange={(e) => setTestCaption(e.target.value)}
                                        placeholder="Test document from Herd OMS"
                                        disabled={!canSendTests}
                                    />
                                </div>
                            </div>

                            <div className="flex flex-wrap gap-3">
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="rounded-xl"
                                    disabled={!canSendTests || sendingText || sendingDocument}
                                    onClick={handleSendTestText}
                                >
                                    {sendingText ? <Spinner className="mr-2" /> : null}
                                    Send text message
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="rounded-xl"
                                    disabled={!canSendTests || sendingText || sendingDocument || !testFile}
                                    onClick={handleSendTestDocument}
                                >
                                    {sendingDocument ? <Spinner className="mr-2" /> : null}
                                    <FileUp className="mr-2 h-4 w-4" />
                                    Send file
                                </Button>
                            </div>

                            {!canSendTests ? (
                                <p className="text-xs text-muted-foreground">
                                    Save and enable WhatsApp settings with all credentials before
                                    sending test messages.
                                </p>
                            ) : null}

                            {testResult ? (
                                <div
                                    className={cn(
                                        'rounded-xl border px-4 py-3 text-sm space-y-2',
                                        testResult.success
                                            ? 'border-emerald-500/20 bg-emerald-500/5 text-emerald-600'
                                            : 'border-destructive/20 bg-destructive/5 text-destructive',
                                    )}
                                >
                                    <p className="font-medium">{testResult.message}</p>
                                    {testResult.message_id ? (
                                        <p className="font-mono text-xs break-all">
                                            Message ID: {testResult.message_id}
                                        </p>
                                    ) : null}
                                    {testResult.media_id ? (
                                        <p className="font-mono text-xs break-all">
                                            Media ID: {testResult.media_id}
                                        </p>
                                    ) : null}
                                    {testResult.http_status ? (
                                        <p className="text-xs">HTTP {testResult.http_status}</p>
                                    ) : null}
                                </div>
                            ) : null}
                        </CardContent>
                    </Card>
                ) : null}

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
