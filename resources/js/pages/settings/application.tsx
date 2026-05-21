import { Head, useForm, usePage } from '@inertiajs/react';
import { Building2, ImageIcon, Mail, Settings2 } from 'lucide-react';
import { useState } from 'react';
import { sendSmtpTestEmail } from '@/features/settings/send-smtp-test-email';
import { toast } from '@/lib/toast';
import Heading from '@/components/heading';
import { BrandingUploadField } from '@/components/settings/branding-upload-field';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
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
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';

type CurrencyOption = { code: string; name: string; symbol: string };

type Props = {
    general: {
        app_name: string;
        company_name: string;
        support_email: string;
        support_phone: string;
        company_address: string;
        timezone: string;
        currency: string;
        date_format: string;
    };
    branding: {
        main_logo_url: string | null;
        login_logo_url: string | null;
        favicon_url: string | null;
        login_background_url: string | null;
        email_branding_logo_url: string | null;
    };
    preferences: {
        primary_color: string;
        accent_color: string;
        sidebar_compact_default: boolean;
    };
    timezones: string[];
    date_formats: { value: string; label: string }[];
    currencies: CurrencyOption[];
    smtp: {
        host: string;
        port: number;
        username: string;
        encryption: string;
        from_address: string;
        from_name: string;
        has_password: boolean;
        is_configured: boolean;
        uses_env_fallback: boolean;
        email_branding_logo_url: string | null;
        email_footer: {
            tagline: string;
            website: string;
            certifications: string;
        };
    };
};

export default function ApplicationSettings({
    general,
    branding,
    preferences,
    timezones,
    date_formats,
    currencies,
    smtp,
}: Props) {
    const [tab, setTab] = useState('general');
    const [testRecipient, setTestRecipient] = useState('');
    const [testSubject, setTestSubject] = useState(
        () => `${general.app_name || 'HRM'} — SMTP test`,
    );
    const [testBody, setTestBody] = useState(
        () => `This is a test email from ${general.app_name || 'HRM'}.`,
    );
    const [testAttachment, setTestAttachment] = useState<File | null>(null);
    const [isSendingTest, setIsSendingTest] = useState(false);
    const authUser = usePage().props.auth?.user as { email?: string } | undefined;

    const generalForm = useForm({
        app_name: general.app_name ?? '',
        company_name: general.company_name ?? '',
        support_email: general.support_email ?? '',
        support_phone: general.support_phone ?? '',
        company_address: general.company_address ?? '',
        timezone: general.timezone ?? 'UTC',
        currency: general.currency ?? 'USD',
        date_format: general.date_format ?? 'Y-m-d',
    });

    const smtpForm = useForm({
        host: smtp.host ?? '',
        port: smtp.port ?? 587,
        username: smtp.username ?? '',
        password: '',
        encryption: smtp.encryption ?? 'tls',
        from_address: smtp.from_address ?? '',
        from_name: smtp.from_name ?? '',
        email_branding_logo: null as File | null,
        mail_footer_tagline: smtp.email_footer?.tagline ?? '',
        mail_footer_website: smtp.email_footer?.website ?? '',
        mail_footer_certifications: smtp.email_footer?.certifications ?? '',
    });

    const brandingForm = useForm({
        main_logo: null as File | null,
        login_logo: null as File | null,
        favicon: null as File | null,
        login_background: null as File | null,
    });

    const preferencesForm = useForm({
        primary_color: preferences.primary_color ?? '#6366f1',
        accent_color: preferences.accent_color ?? '#8b5cf6',
        sidebar_compact_default: preferences.sidebar_compact_default ?? false,
    });

    function submitGeneral(e: React.FormEvent) {
        e.preventDefault();
        generalForm.put('/settings/application/general', { preserveScroll: true });
    }

    function submitBranding(e: React.FormEvent) {
        e.preventDefault();
        brandingForm.post('/settings/application/branding', {
            preserveScroll: true,
            forceFormData: true,
        });
    }

    function submitPreferences(e: React.FormEvent) {
        e.preventDefault();
        preferencesForm.post('/settings/application/branding', {
            preserveScroll: true,
        });
    }

    function submitSmtp(e: React.FormEvent) {
        e.preventDefault();
        smtpForm.post('/settings/application/smtp', {
            preserveScroll: true,
            forceFormData: true,
        });
    }

    async function handleSendTestEmail() {
        const recipient = testRecipient.trim();

        if (recipient === '') {
            toast.error('Enter a recipient email for the test.');

            return;
        }

        setIsSendingTest(true);

        try {
            const formData = new FormData();
            formData.append('recipient', recipient);
            formData.append('subject', testSubject.trim());
            formData.append('body', testBody);
            formData.append('host', smtpForm.data.host);
            formData.append('port', String(smtpForm.data.port));
            formData.append('username', smtpForm.data.username);
            formData.append('encryption', smtpForm.data.encryption);
            formData.append('from_address', smtpForm.data.from_address);
            formData.append('from_name', smtpForm.data.from_name);

            if (smtpForm.data.password) {
                formData.append('password', smtpForm.data.password);
            }

            if (testAttachment) {
                formData.append('attachment', testAttachment);
            }

            const message = await sendSmtpTestEmail('/settings/application/smtp/test', formData);
            toast.success(message);
        } catch (error) {
            toast.error(error instanceof Error ? error.message : 'Failed to send test email.');
        } finally {
            setIsSendingTest(false);
        }
    }

    return (
        <>
            <Head title="Application settings" />

            <h1 className="sr-only">Application settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Application settings"
                    description="Manage branding, identity, and system preferences for the entire platform"
                />

                <Tabs value={tab} onValueChange={setTab} className="w-full">
                    <TabsList className="grid h-10 w-full max-w-2xl grid-cols-2 sm:grid-cols-4">
                        <TabsTrigger value="general" className="gap-2 data-[state=active]:shadow-none">
                            <Building2 className="size-4" />
                            General
                        </TabsTrigger>
                        <TabsTrigger value="branding" className="gap-2 data-[state=active]:shadow-none">
                            <ImageIcon className="size-4" />
                            Branding
                        </TabsTrigger>
                        <TabsTrigger value="smtp" className="gap-2 data-[state=active]:shadow-none">
                            <Mail className="size-4" />
                            SMTP
                        </TabsTrigger>
                        <TabsTrigger value="preferences" className="gap-2 data-[state=active]:shadow-none">
                            <Settings2 className="size-4" />
                            System
                        </TabsTrigger>
                    </TabsList>

                    <TabsContent value="general" className="mt-6">
                        <Card>
                            <CardHeader>
                                <CardTitle>General</CardTitle>
                                <CardDescription>
                                    Application identity and regional defaults used across the platform.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <form onSubmit={submitGeneral} className="grid gap-5 sm:grid-cols-2">
                                    <div className="space-y-2 sm:col-span-2">
                                        <Label htmlFor="app_name">Application name</Label>
                                        <Input
                                            id="app_name"
                                            value={generalForm.data.app_name}
                                            onChange={(e) => generalForm.setData('app_name', e.target.value)}
                                        />
                                        {generalForm.errors.app_name ? (
                                            <p className="text-xs text-destructive">{generalForm.errors.app_name}</p>
                                        ) : null}
                                    </div>

                                    <div className="space-y-2 sm:col-span-2">
                                        <Label htmlFor="company_name">Company name</Label>
                                        <Input
                                            id="company_name"
                                            value={generalForm.data.company_name}
                                            onChange={(e) => generalForm.setData('company_name', e.target.value)}
                                        />
                                        {generalForm.errors.company_name ? (
                                            <p className="text-xs text-destructive">{generalForm.errors.company_name}</p>
                                        ) : null}
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="support_email">Support email</Label>
                                        <Input
                                            id="support_email"
                                            type="email"
                                            value={generalForm.data.support_email}
                                            onChange={(e) => generalForm.setData('support_email', e.target.value)}
                                        />
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="support_phone">Support phone</Label>
                                        <Input
                                            id="support_phone"
                                            value={generalForm.data.support_phone}
                                            onChange={(e) => generalForm.setData('support_phone', e.target.value)}
                                        />
                                    </div>

                                    <div className="space-y-2 sm:col-span-2">
                                        <Label htmlFor="company_address">Company address</Label>
                                        <Textarea
                                            id="company_address"
                                            rows={3}
                                            value={generalForm.data.company_address}
                                            onChange={(e) => generalForm.setData('company_address', e.target.value)}
                                        />
                                    </div>

                                    <div className="space-y-2">
                                        <Label>Timezone</Label>
                                        <Select
                                            value={generalForm.data.timezone}
                                            onValueChange={(value) => generalForm.setData('timezone', value)}
                                        >
                                            <SelectTrigger>
                                                <SelectValue placeholder="Select timezone" />
                                            </SelectTrigger>
                                            <SelectContent className="max-h-64">
                                                {timezones.map((tz) => (
                                                    <SelectItem key={tz} value={tz}>
                                                        {tz}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <div className="space-y-2">
                                        <Label>Currency</Label>
                                        <Select
                                            value={generalForm.data.currency}
                                            onValueChange={(value) => generalForm.setData('currency', value)}
                                        >
                                            <SelectTrigger>
                                                <SelectValue placeholder="Select currency" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {currencies.map((c) => (
                                                    <SelectItem key={c.code} value={c.code}>
                                                        {c.code} — {c.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <div className="space-y-2 sm:col-span-2">
                                        <Label>Date format</Label>
                                        <Select
                                            value={generalForm.data.date_format}
                                            onValueChange={(value) => generalForm.setData('date_format', value)}
                                        >
                                            <SelectTrigger>
                                                <SelectValue placeholder="Select format" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {date_formats.map((f) => (
                                                    <SelectItem key={f.value} value={f.value}>
                                                        {f.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <div className="sm:col-span-2">
                                        <Button type="submit" disabled={generalForm.processing}>
                                            {generalForm.processing ? <Spinner /> : null}
                                            Save general settings
                                        </Button>
                                    </div>
                                </form>
                            </CardContent>
                        </Card>
                    </TabsContent>

                    <TabsContent value="branding" className="mt-6">
                        <Card>
                            <CardHeader>
                                <CardTitle>Branding</CardTitle>
                                <CardDescription>
                                    Upload logos and favicon. Changes apply immediately across login and the browser tab.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <form onSubmit={submitBranding} className="grid gap-6">
                                    <BrandingUploadField
                                        label="Main logo"
                                        assetKey="main_logo"
                                        currentUrl={branding.main_logo_url}
                                        onFileChange={(file) => brandingForm.setData('main_logo', file)}
                                        error={brandingForm.errors.main_logo}
                                    />
                                    <BrandingUploadField
                                        label="Login page logo"
                                        assetKey="login_logo"
                                        currentUrl={branding.login_logo_url}
                                        onFileChange={(file) => brandingForm.setData('login_logo', file)}
                                        error={brandingForm.errors.login_logo}
                                    />
                                    <BrandingUploadField
                                        label="Favicon"
                                        assetKey="favicon"
                                        currentUrl={branding.favicon_url}
                                        accept="image/png,image/jpeg,image/jpg,image/svg+xml,image/x-icon,.ico"
                                        hint="PNG, JPG, SVG, or ICO — max 512 KB"
                                        onFileChange={(file) => brandingForm.setData('favicon', file)}
                                        error={brandingForm.errors.favicon}
                                    />
                                    <BrandingUploadField
                                        label="Login background"
                                        assetKey="login_background"
                                        currentUrl={branding.login_background_url}
                                        onFileChange={(file) => brandingForm.setData('login_background', file)}
                                        error={brandingForm.errors.login_background}
                                    />

                                    <Button type="submit" disabled={brandingForm.processing}>
                                        {brandingForm.processing ? <Spinner /> : null}
                                        Save branding
                                    </Button>
                                </form>
                            </CardContent>
                        </Card>
                    </TabsContent>

                    <TabsContent value="smtp" className="mt-6">
                        <Card>
                            <CardHeader>
                                <CardTitle>SMTP / Email</CardTitle>
                                <CardDescription>
                                    Configure SMTP, email footer branding, and test delivery. Saved settings
                                    override <code className="text-xs">.env</code>. Company name, phone, and
                                    address in the footer come from the General tab.
                                    {smtp.uses_env_fallback ? (
                                        <span className="mt-1 block text-amber-600 dark:text-amber-400">
                                            Currently using values from .env until you save SMTP settings.
                                        </span>
                                    ) : null}
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-8">
                                <form onSubmit={submitSmtp} className="grid gap-5 sm:grid-cols-2">
                                    <div className="space-y-2 sm:col-span-2">
                                        <Label htmlFor="mail_host">SMTP host</Label>
                                        <Input
                                            id="mail_host"
                                            value={smtpForm.data.host}
                                            onChange={(e) => smtpForm.setData('host', e.target.value)}
                                            placeholder="smtp.example.com"
                                        />
                                        {smtpForm.errors.host ? (
                                            <p className="text-xs text-destructive">{smtpForm.errors.host}</p>
                                        ) : null}
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="mail_port">Port</Label>
                                        <Input
                                            id="mail_port"
                                            type="number"
                                            min={1}
                                            max={65535}
                                            value={smtpForm.data.port}
                                            onChange={(e) =>
                                                smtpForm.setData('port', Number(e.target.value) || 587)
                                            }
                                        />
                                        {smtpForm.errors.port ? (
                                            <p className="text-xs text-destructive">{smtpForm.errors.port}</p>
                                        ) : null}
                                    </div>

                                    <div className="space-y-2">
                                        <Label>Encryption</Label>
                                        <Select
                                            value={smtpForm.data.encryption}
                                            onValueChange={(value) => smtpForm.setData('encryption', value)}
                                        >
                                            <SelectTrigger>
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="tls">TLS (587)</SelectItem>
                                                <SelectItem value="ssl">SSL (465)</SelectItem>
                                                <SelectItem value="none">None</SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <div className="space-y-2 sm:col-span-2">
                                        <Label htmlFor="mail_username">Username</Label>
                                        <Input
                                            id="mail_username"
                                            value={smtpForm.data.username}
                                            onChange={(e) => smtpForm.setData('username', e.target.value)}
                                            autoComplete="off"
                                        />
                                    </div>

                                    <div className="space-y-2 sm:col-span-2">
                                        <Label htmlFor="mail_password">Password</Label>
                                        <Input
                                            id="mail_password"
                                            type="password"
                                            value={smtpForm.data.password}
                                            onChange={(e) => smtpForm.setData('password', e.target.value)}
                                            placeholder={
                                                smtp.has_password
                                                    ? 'Leave blank to keep current password'
                                                    : 'SMTP password'
                                            }
                                            autoComplete="new-password"
                                        />
                                        {smtpForm.errors.password ? (
                                            <p className="text-xs text-destructive">{smtpForm.errors.password}</p>
                                        ) : null}
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="mail_from_address">From address</Label>
                                        <Input
                                            id="mail_from_address"
                                            type="email"
                                            value={smtpForm.data.from_address}
                                            onChange={(e) => smtpForm.setData('from_address', e.target.value)}
                                        />
                                        {smtpForm.errors.from_address ? (
                                            <p className="text-xs text-destructive">
                                                {smtpForm.errors.from_address}
                                            </p>
                                        ) : null}
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="mail_from_name">From name</Label>
                                        <Input
                                            id="mail_from_name"
                                            value={smtpForm.data.from_name}
                                            onChange={(e) => smtpForm.setData('from_name', e.target.value)}
                                        />
                                        {smtpForm.errors.from_name ? (
                                            <p className="text-xs text-destructive">{smtpForm.errors.from_name}</p>
                                        ) : null}
                                    </div>

                                    <div className="space-y-4 rounded-lg border border-dashed p-4 sm:col-span-2">
                                        <BrandingUploadField
                                            label="Email branding logo"
                                            assetKey="email_branding_logo"
                                            currentUrl={smtp.email_branding_logo_url}
                                            hint="Used in the footer of all outgoing emails (recommended: transparent PNG)."
                                            onFileChange={(file) =>
                                                smtpForm.setData('email_branding_logo', file)
                                            }
                                            error={smtpForm.errors.email_branding_logo}
                                        />
                                        <div>
                                            <h3 className="text-sm font-semibold">Email footer text</h3>
                                            <p className="mt-1 text-sm text-muted-foreground">
                                                Shown below the message body on every email. Company name,
                                                phone, email, and address come from General settings.
                                            </p>
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="mail_footer_tagline">Tagline</Label>
                                            <Input
                                                id="mail_footer_tagline"
                                                value={smtpForm.data.mail_footer_tagline}
                                                onChange={(e) =>
                                                    smtpForm.setData('mail_footer_tagline', e.target.value)
                                                }
                                                placeholder="Your Complete Marine Solutions"
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="mail_footer_website">Website</Label>
                                            <Input
                                                id="mail_footer_website"
                                                value={smtpForm.data.mail_footer_website}
                                                onChange={(e) =>
                                                    smtpForm.setData('mail_footer_website', e.target.value)
                                                }
                                                placeholder="www.overseas-ms.com"
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="mail_footer_certifications">Certifications bar</Label>
                                            <Input
                                                id="mail_footer_certifications"
                                                value={smtpForm.data.mail_footer_certifications}
                                                onChange={(e) =>
                                                    smtpForm.setData(
                                                        'mail_footer_certifications',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="ISO 9001:2015 | ISO 14001:2015 | ISO 45001:2018 | ICV Certified"
                                            />
                                        </div>
                                    </div>

                                    <div className="sm:col-span-2">
                                        <Button type="submit" disabled={smtpForm.processing}>
                                            {smtpForm.processing ? <Spinner /> : null}
                                            Save email settings
                                        </Button>
                                    </div>
                                </form>

                                <div className="rounded-lg border border-dashed p-5">
                                    <h3 className="text-sm font-semibold">Send test email</h3>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        Uses SMTP settings above (saved or unsaved). Customize the message
                                        below. Check inbox and junk after sending.
                                    </p>
                                    <div className="mt-4 grid max-w-2xl gap-4">
                                        <div className="space-y-2">
                                            <Label htmlFor="test_recipient">Recipient</Label>
                                            <Input
                                                id="test_recipient"
                                                type="email"
                                                value={testRecipient}
                                                onChange={(e) => setTestRecipient(e.target.value)}
                                                placeholder={authUser?.email ?? 'you@company.com'}
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="test_subject">Subject</Label>
                                            <Input
                                                id="test_subject"
                                                value={testSubject}
                                                onChange={(e) => setTestSubject(e.target.value)}
                                                placeholder="SMTP test"
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="test_body">Body</Label>
                                            <Textarea
                                                id="test_body"
                                                rows={5}
                                                value={testBody}
                                                onChange={(e) => setTestBody(e.target.value)}
                                                placeholder="Message shown in the email body…"
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="test_attachment">Attachment (optional)</Label>
                                            <Input
                                                id="test_attachment"
                                                type="file"
                                                accept=".pdf,.png,.jpg,.jpeg,.doc,.docx,application/pdf,image/*"
                                                onChange={(e) =>
                                                    setTestAttachment(e.target.files?.[0] ?? null)
                                                }
                                            />
                                            {testAttachment ? (
                                                <p className="text-xs text-muted-foreground">
                                                    {testAttachment.name} (
                                                    {(testAttachment.size / 1024).toFixed(1)} KB)
                                                </p>
                                            ) : (
                                                <p className="text-xs text-muted-foreground">
                                                    PDF, PNG, JPG, or Word — max 20 MB
                                                </p>
                                            )}
                                        </div>
                                        <div>
                                            <Button
                                                type="button"
                                                variant="secondary"
                                                disabled={
                                                    isSendingTest || smtpForm.data.host.trim() === ''
                                                }
                                                onClick={() => void handleSendTestEmail()}
                                            >
                                                {isSendingTest ? <Spinner /> : null}
                                                Send test email
                                            </Button>
                                        </div>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </TabsContent>

                    <TabsContent value="preferences" className="mt-6">
                        <Card>
                            <CardHeader>
                                <CardTitle>System preferences</CardTitle>
                                <CardDescription>Theme accents and default UI behavior.</CardDescription>
                            </CardHeader>
                            <CardContent>
                                <form onSubmit={submitPreferences} className="grid max-w-md gap-5">
                                    <div className="space-y-2">
                                        <Label htmlFor="primary_color">Primary color</Label>
                                        <div className="flex items-center gap-3">
                                            <Input
                                                id="primary_color"
                                                type="color"
                                                className="h-11 w-14 cursor-pointer p-1"
                                                value={preferencesForm.data.primary_color}
                                                onChange={(e) =>
                                                    preferencesForm.setData('primary_color', e.target.value)
                                                }
                                            />
                                            <Input
                                                value={preferencesForm.data.primary_color}
                                                onChange={(e) =>
                                                    preferencesForm.setData('primary_color', e.target.value)
                                                }
                                            />
                                        </div>
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="accent_color">Accent color</Label>
                                        <div className="flex items-center gap-3">
                                            <Input
                                                id="accent_color"
                                                type="color"
                                                className="h-11 w-14 cursor-pointer p-1"
                                                value={preferencesForm.data.accent_color}
                                                onChange={(e) =>
                                                    preferencesForm.setData('accent_color', e.target.value)
                                                }
                                            />
                                            <Input
                                                value={preferencesForm.data.accent_color}
                                                onChange={(e) =>
                                                    preferencesForm.setData('accent_color', e.target.value)
                                                }
                                            />
                                        </div>
                                    </div>

                                    <div className="flex items-center gap-2">
                                        <Checkbox
                                            id="sidebar_compact_default"
                                            checked={preferencesForm.data.sidebar_compact_default}
                                            onCheckedChange={(checked) =>
                                                preferencesForm.setData(
                                                    'sidebar_compact_default',
                                                    checked === true,
                                                )
                                            }
                                        />
                                        <Label htmlFor="sidebar_compact_default" className="cursor-pointer font-normal">
                                            Collapse sidebar by default for new sessions
                                        </Label>
                                    </div>

                                    <Button type="submit" disabled={preferencesForm.processing}>
                                        {preferencesForm.processing ? <Spinner /> : null}
                                        Save preferences
                                    </Button>
                                </form>
                            </CardContent>
                        </Card>
                    </TabsContent>
                </Tabs>
            </div>
        </>
    );
}

ApplicationSettings.layout = {};
