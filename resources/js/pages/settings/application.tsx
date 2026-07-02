import { Head, useForm, usePage } from '@inertiajs/react';
import {
    Building2,
    Camera,
    CheckCircle2,
    ImageIcon,
    Layout,
    Mail,
    MessageCircle,
    Palette,
    Send,
    Settings2,
    ScrollText,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { BrandingUploadField } from '@/components/settings/branding-upload-field';
import { SettingsSecretInput } from '@/components/settings/settings-secret-input';
import { ThemeColorPicker } from '@/components/settings/theme-color-picker';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
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
import { Textarea } from '@/components/ui/textarea';
import { HikvisionSettingsPanel } from '@/features/settings/hikvision-settings-panel';
import type { HikvisionSettingsPanelProps } from '@/features/settings/hikvision-settings-panel';
import { sendSmtpTestEmail } from '@/features/settings/send-smtp-test-email';
import { WhatsAppSettingsPanel } from '@/features/settings/whatsapp-settings-panel';
import type { WhatsAppSettingsPanelProps } from '@/features/settings/whatsapp-settings-panel';
import { toast } from '@/lib/toast';
import { cn } from '@/lib/utils';

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
        salary_certificate_signature_url: string | null;
        salary_certificate_stamp_url: string | null;
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
        password: string;
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
    whatsapp: WhatsAppSettingsPanelProps | null;
    hikvision: HikvisionSettingsPanelProps | null;
};

const ALL_NAV_ITEMS = [
    {
        id: 'general',
        label: 'General',
        icon: Building2,
        description: 'Identity & regional',
        permission: 'settings.application.view',
    },
    {
        id: 'branding',
        label: 'Branding',
        icon: ImageIcon,
        description: 'Logos & visuals',
        permission: 'settings.application.view',
    },
    {
        id: 'smtp',
        label: 'SMTP / Email',
        icon: Mail,
        description: 'Mail delivery',
        permission: 'settings.application.view',
    },
    {
        id: 'whatsapp',
        label: 'WhatsApp',
        icon: MessageCircle,
        description: 'Business messaging',
        permission: 'settings.integrations.whatsapp.view',
    },
    {
        id: 'hikvision',
        label: 'Hikvision',
        icon: Camera,
        description: 'Access control API',
        permission: 'settings.integrations.hikvision.view',
    },
    {
        id: 'preferences',
        label: 'System',
        icon: Settings2,
        description: 'UI preferences',
        permission: 'settings.application.view',
    },
] as const;

type NavId = (typeof ALL_NAV_ITEMS)[number]['id'];

function resolveInitialTab(
    navItems: { id: NavId }[],
    requestedTab: string | null,
): NavId {
    if (requestedTab && navItems.some((item) => item.id === requestedTab)) {
        return requestedTab as NavId;
    }

    return navItems[0]?.id ?? 'general';
}

/** Reusable section heading inside a settings card */
function SectionHeading({
    icon: Icon,
    title,
    description,
    color = 'bg-primary/10 border-primary/20 text-primary',
}: {
    icon: React.ComponentType<{ className?: string }>;
    title: string;
    description?: string;
    color?: string;
}) {
    return (
        <div className="mb-6 flex items-center gap-4">
            <div
                className={cn(
                    'flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl border',
                    color,
                )}
            >
                <Icon className="h-5 w-5" />
            </div>
            <div>
                <h2 className="text-base font-bold tracking-tight text-foreground">
                    {title}
                </h2>
                {description ? (
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {description}
                    </p>
                ) : null}
            </div>
        </div>
    );
}

/** Styled label for form fields */
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

/** Styled input wrapper */
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

/** A settings card with consistent padding */
function SettingsCard({
    children,
    className,
}: {
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <Card
            className={cn(
                'border-border/80 bg-card dark:border-white/5 dark:bg-white/5',
                className,
            )}
        >
            <CardContent className="p-6">{children}</CardContent>
        </Card>
    );
}

export default function ApplicationSettings({
    general,
    branding,
    preferences,
    timezones,
    date_formats,
    currencies,
    smtp,
    whatsapp,
    hikvision,
}: Props) {
    const auth = usePage().props.auth;
    const authUser = auth?.user as { email?: string } | undefined;
    const permissions = useMemo(
        () =>
            (auth as { permissions?: string[] } | undefined)?.permissions ?? [],
        [auth],
    );

    const canUpdateApplication = permissions.includes(
        'settings.application.update',
    );

    const navItems = useMemo(
        () =>
            ALL_NAV_ITEMS.filter((item) =>
                permissions.includes(item.permission),
            ),
        [permissions],
    );

    const requestedTab =
        typeof window !== 'undefined'
            ? new URLSearchParams(window.location.search).get('tab')
            : null;

    const [tab, setTab] = useState<NavId>(() =>
        resolveInitialTab(navItems, requestedTab),
    );
    const [testRecipient, setTestRecipient] = useState('');
    const [testSubject, setTestSubject] = useState(
        () => `${general.app_name || 'HRM'} — SMTP test`,
    );
    const [testBody, setTestBody] = useState(
        () => `This is a test email from ${general.app_name || 'HRM'}.`,
    );
    const [testAttachment, setTestAttachment] = useState<File | null>(null);
    const [isSendingTest, setIsSendingTest] = useState(false);

    const generalForm = useForm({
        app_name: general.app_name ?? '',
        company_name: general.company_name ?? '',
        support_email: general.support_email ?? '',
        support_phone: general.support_phone ?? '',
        company_address: general.company_address ?? '',
        timezone: general.timezone ?? 'UTC',
        currency: general.currency ?? 'USD',
        date_format: general.date_format ?? 'Y-m-d',
        salary_certificate_signature: null as File | null,
        salary_certificate_stamp: null as File | null,
    });

    const smtpForm = useForm({
        host: smtp.host ?? '',
        port: smtp.port ?? 587,
        username: smtp.username ?? '',
        password: smtp.password ?? '',
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

        if (!canUpdateApplication) {
            return;
        }

        generalForm.post('/settings/application/general', {
            preserveScroll: true,
            forceFormData: true,
        });
    }

    function submitBranding(e: React.FormEvent) {
        e.preventDefault();

        if (!canUpdateApplication) {
            return;
        }

        brandingForm.post('/settings/application/branding', {
            preserveScroll: true,
            forceFormData: true,
        });
    }

    function submitPreferences(e: React.FormEvent) {
        e.preventDefault();

        if (!canUpdateApplication) {
            return;
        }

        preferencesForm.post('/settings/application/branding', {
            preserveScroll: true,
        });
    }

    function submitSmtp(e: React.FormEvent) {
        e.preventDefault();

        if (!canUpdateApplication) {
            return;
        }

        smtpForm.post('/settings/application/smtp', {
            preserveScroll: true,
            forceFormData: true,
        });
    }

    async function handleSendTestEmail() {
        if (!canUpdateApplication) {
            return;
        }

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

            const message = await sendSmtpTestEmail(
                '/settings/application/smtp/test',
                formData,
            );
            toast.success(message);
        } catch (error) {
            toast.error(
                error instanceof Error
                    ? error.message
                    : 'Failed to send test email.',
            );
        } finally {
            setIsSendingTest(false);
        }
    }

    return (
        <>
            <Head title="Application settings" />
            <h1 className="sr-only">Application settings</h1>

            <div className="mb-8 flex flex-col gap-2">
                <div className="flex items-center gap-2">
                    <span className="flex h-2 w-2 animate-pulse rounded-full bg-primary" />
                    <span className="text-[10px] font-bold tracking-[0.2em] text-muted-foreground/80 uppercase">
                        Settings
                    </span>
                </div>
                <h1 className="bg-linear-to-br from-foreground to-foreground/50 bg-clip-text text-4xl font-extrabold tracking-tight text-transparent">
                    Application
                </h1>
                <p className="text-sm font-medium text-muted-foreground/80">
                    Manage branding, email, WhatsApp, and system preferences for
                    the entire platform.
                </p>
            </div>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-12">
                {/* ── Sidebar nav ── */}
                <aside className="lg:sticky lg:top-6 lg:col-span-3 lg:self-start">
                    <Card className="overflow-hidden border-border/80 bg-card dark:border-white/5 dark:bg-white/5">
                        <div className="border-b border-border/80 bg-muted/20 p-4 dark:border-white/5 dark:bg-white/[0.02]">
                            <h3 className="text-xs font-bold tracking-widest text-muted-foreground uppercase">
                                Settings
                            </h3>
                        </div>
                        <div className="space-y-1 p-2">
                            {navItems.map((item) => {
                                const isActive = tab === item.id;

                                return (
                                    <button
                                        key={item.id}
                                        type="button"
                                        onClick={() => setTab(item.id)}
                                        className={cn(
                                            'flex w-full items-center gap-3 rounded-xl px-4 py-3 text-left transition-all',
                                            isActive
                                                ? 'bg-primary text-primary-foreground shadow-lg shadow-primary/20'
                                                : 'text-muted-foreground hover:bg-muted hover:text-foreground dark:hover:bg-white/5',
                                        )}
                                    >
                                        <item.icon
                                            className={cn(
                                                'h-4 w-4 shrink-0',
                                                isActive
                                                    ? 'text-primary-foreground'
                                                    : 'text-primary',
                                            )}
                                        />
                                        <div className="min-w-0">
                                            <p className="truncate text-sm font-bold tracking-tight">
                                                {item.label}
                                            </p>
                                            <p
                                                className={cn(
                                                    'truncate text-[10px]',
                                                    isActive
                                                        ? 'text-primary-foreground/70'
                                                        : 'text-muted-foreground/50',
                                                )}
                                            >
                                                {item.description}
                                            </p>
                                        </div>
                                    </button>
                                );
                            })}
                        </div>
                    </Card>
                </aside>

                {/* ── Main content ── */}
                <main className="space-y-6 lg:col-span-9">
                    {/* ══ GENERAL ══ */}
                    {tab === 'general' && (
                        <form onSubmit={submitGeneral} className="space-y-6">
                            {/* Identity */}
                            <SettingsCard>
                                <SectionHeading
                                    icon={Building2}
                                    title="Application identity"
                                    description="Core names used across the platform and emails."
                                />
                                <div className="grid gap-5 sm:grid-cols-2">
                                    <div className="space-y-1.5 sm:col-span-2">
                                        <FieldLabel htmlFor="app_name">
                                            Application name
                                        </FieldLabel>
                                        <FieldInput
                                            id="app_name"
                                            value={generalForm.data.app_name}
                                            onChange={(e) =>
                                                generalForm.setData(
                                                    'app_name',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="e.g. Herd OMS"
                                        />
                                        {generalForm.errors.app_name ? (
                                            <p className="text-xs text-destructive">
                                                {generalForm.errors.app_name}
                                            </p>
                                        ) : null}
                                    </div>

                                    <div className="space-y-1.5 sm:col-span-2">
                                        <FieldLabel htmlFor="company_name">
                                            Company name
                                        </FieldLabel>
                                        <FieldInput
                                            id="company_name"
                                            value={
                                                generalForm.data.company_name
                                            }
                                            onChange={(e) =>
                                                generalForm.setData(
                                                    'company_name',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                        {generalForm.errors.company_name ? (
                                            <p className="text-xs text-destructive">
                                                {
                                                    generalForm.errors
                                                        .company_name
                                                }
                                            </p>
                                        ) : null}
                                    </div>

                                    <div className="space-y-1.5">
                                        <FieldLabel htmlFor="support_email">
                                            Support email
                                        </FieldLabel>
                                        <FieldInput
                                            id="support_email"
                                            type="email"
                                            value={
                                                generalForm.data.support_email
                                            }
                                            onChange={(e) =>
                                                generalForm.setData(
                                                    'support_email',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>

                                    <div className="space-y-1.5">
                                        <FieldLabel htmlFor="support_phone">
                                            Support phone
                                        </FieldLabel>
                                        <FieldInput
                                            id="support_phone"
                                            value={
                                                generalForm.data.support_phone
                                            }
                                            onChange={(e) =>
                                                generalForm.setData(
                                                    'support_phone',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>

                                    <div className="space-y-1.5 sm:col-span-2">
                                        <FieldLabel htmlFor="company_address">
                                            Company address
                                        </FieldLabel>
                                        <Textarea
                                            id="company_address"
                                            rows={3}
                                            value={
                                                generalForm.data.company_address
                                            }
                                            onChange={(e) =>
                                                generalForm.setData(
                                                    'company_address',
                                                    e.target.value,
                                                )
                                            }
                                            className="resize-none rounded-xl border-input bg-background/50 px-4 py-3 text-foreground transition-all focus-visible:ring-primary/40 dark:border-white/10 dark:bg-white/5"
                                        />
                                    </div>
                                </div>
                            </SettingsCard>

                            {/* Regional */}
                            <SettingsCard>
                                <SectionHeading
                                    icon={Settings2}
                                    title="Regional defaults"
                                    description="Timezone, currency, and date display used across the platform."
                                    color="bg-sky-500/10 border-sky-500/20 text-sky-500"
                                />
                                <div className="grid gap-5 sm:grid-cols-2">
                                    <div className="space-y-1.5">
                                        <FieldLabel>Timezone</FieldLabel>
                                        <Select
                                            value={generalForm.data.timezone}
                                            onValueChange={(value) =>
                                                generalForm.setData(
                                                    'timezone',
                                                    value,
                                                )
                                            }
                                        >
                                            <SelectTrigger className="h-11 rounded-xl border-input bg-background/50 text-foreground dark:border-white/10 dark:bg-white/5">
                                                <SelectValue placeholder="Select timezone" />
                                            </SelectTrigger>
                                            <SelectContent className="max-h-64">
                                                {timezones.map((tz) => (
                                                    <SelectItem
                                                        key={tz}
                                                        value={tz}
                                                    >
                                                        {tz}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <div className="space-y-1.5">
                                        <FieldLabel>Currency</FieldLabel>
                                        <Select
                                            value={generalForm.data.currency}
                                            onValueChange={(value) =>
                                                generalForm.setData(
                                                    'currency',
                                                    value,
                                                )
                                            }
                                        >
                                            <SelectTrigger className="h-11 rounded-xl border-input bg-background/50 text-foreground dark:border-white/10 dark:bg-white/5">
                                                <SelectValue placeholder="Select currency" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {currencies.map((c) => (
                                                    <SelectItem
                                                        key={c.code}
                                                        value={c.code}
                                                    >
                                                        {c.code} — {c.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <div className="space-y-1.5 sm:col-span-2">
                                        <FieldLabel>Date format</FieldLabel>
                                        <Select
                                            value={generalForm.data.date_format}
                                            onValueChange={(value) =>
                                                generalForm.setData(
                                                    'date_format',
                                                    value,
                                                )
                                            }
                                        >
                                            <SelectTrigger className="h-11 rounded-xl border-input bg-background/50 text-foreground dark:border-white/10 dark:bg-white/5">
                                                <SelectValue placeholder="Select format" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {date_formats.map((f) => (
                                                    <SelectItem
                                                        key={f.value}
                                                        value={f.value}
                                                    >
                                                        {f.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                </div>
                            </SettingsCard>

                            <SettingsCard>
                                <SectionHeading
                                    icon={ScrollText}
                                    title="Salary certificate"
                                    description="Signature and company stamp shown on printed employee salary certificates."
                                    color="bg-amber-500/10 border-amber-500/20 text-amber-600 dark:text-amber-400"
                                />
                                <div className="space-y-6">
                                    <BrandingUploadField
                                        label="Authorized signature"
                                        assetKey="salary_certificate_signature"
                                        currentUrl={
                                            general.salary_certificate_signature_url
                                        }
                                        accept="image/png,image/jpeg,image/jpg"
                                        hint="PNG or JPG — max 2 MB"
                                        onFileChange={(file) =>
                                            generalForm.setData(
                                                'salary_certificate_signature',
                                                file,
                                            )
                                        }
                                        error={
                                            generalForm.errors
                                                .salary_certificate_signature
                                        }
                                    />
                                    <BrandingUploadField
                                        label="Company stamp"
                                        assetKey="salary_certificate_stamp"
                                        currentUrl={
                                            general.salary_certificate_stamp_url
                                        }
                                        accept="image/png,image/jpeg,image/jpg"
                                        hint="PNG or JPG — max 2 MB"
                                        onFileChange={(file) =>
                                            generalForm.setData(
                                                'salary_certificate_stamp',
                                                file,
                                            )
                                        }
                                        error={
                                            generalForm.errors
                                                .salary_certificate_stamp
                                        }
                                    />
                                </div>
                            </SettingsCard>

                            <div className="flex justify-end">
                                <Button
                                    type="submit"
                                    className="h-11 rounded-xl px-6"
                                    disabled={
                                        !canUpdateApplication ||
                                        generalForm.processing
                                    }
                                >
                                    {generalForm.processing ? (
                                        <Spinner />
                                    ) : null}
                                    Save general settings
                                </Button>
                            </div>
                        </form>
                    )}

                    {/* ══ BRANDING ══ */}
                    {tab === 'branding' && (
                        <form onSubmit={submitBranding} className="space-y-6">
                            <SettingsCard>
                                <SectionHeading
                                    icon={ImageIcon}
                                    title="Brand assets"
                                    description="Upload logos and favicon. Changes apply immediately across login and browser tab."
                                    color="bg-accent/10 border-accent/20 text-accent"
                                />
                                <div className="space-y-6">
                                    <BrandingUploadField
                                        label="Main logo"
                                        assetKey="main_logo"
                                        currentUrl={branding.main_logo_url}
                                        onFileChange={(file) =>
                                            brandingForm.setData(
                                                'main_logo',
                                                file,
                                            )
                                        }
                                        error={brandingForm.errors.main_logo}
                                    />
                                    <BrandingUploadField
                                        label="Login page logo"
                                        assetKey="login_logo"
                                        currentUrl={branding.login_logo_url}
                                        onFileChange={(file) =>
                                            brandingForm.setData(
                                                'login_logo',
                                                file,
                                            )
                                        }
                                        error={brandingForm.errors.login_logo}
                                    />
                                    <BrandingUploadField
                                        label="Favicon"
                                        assetKey="favicon"
                                        currentUrl={branding.favicon_url}
                                        accept="image/png,image/jpeg,image/jpg,image/svg+xml,image/x-icon,.ico"
                                        hint="PNG, JPG, SVG, or ICO — max 512 KB"
                                        onFileChange={(file) =>
                                            brandingForm.setData(
                                                'favicon',
                                                file,
                                            )
                                        }
                                        error={brandingForm.errors.favicon}
                                    />
                                    <BrandingUploadField
                                        label="Login background"
                                        assetKey="login_background"
                                        currentUrl={
                                            branding.login_background_url
                                        }
                                        onFileChange={(file) =>
                                            brandingForm.setData(
                                                'login_background',
                                                file,
                                            )
                                        }
                                        error={
                                            brandingForm.errors.login_background
                                        }
                                    />
                                </div>
                            </SettingsCard>

                            <div className="flex justify-end">
                                <Button
                                    type="submit"
                                    className="h-11 rounded-xl px-6"
                                    disabled={
                                        !canUpdateApplication ||
                                        brandingForm.processing
                                    }
                                >
                                    {brandingForm.processing ? (
                                        <Spinner />
                                    ) : null}
                                    Save branding
                                </Button>
                            </div>
                        </form>
                    )}

                    {/* ══ SMTP ══ */}
                    {tab === 'smtp' && (
                        <div className="space-y-6">
                            {smtp.uses_env_fallback ? (
                                <div className="flex items-start gap-3 rounded-xl border border-amber-500/20 bg-amber-500/5 px-4 py-3 text-sm text-amber-400">
                                    <span className="mt-0.5 shrink-0">⚠</span>
                                    <p>
                                        Currently using values from{' '}
                                        <code className="font-mono text-xs">
                                            .env
                                        </code>{' '}
                                        until you save SMTP settings here.
                                    </p>
                                </div>
                            ) : null}

                            {/* Server settings */}
                            <form onSubmit={submitSmtp} className="space-y-6">
                                <SettingsCard>
                                    <SectionHeading
                                        icon={Mail}
                                        title="SMTP server"
                                        description="Connection credentials for outgoing mail delivery."
                                        color="bg-sky-500/10 border-sky-500/20 text-sky-500"
                                    />
                                    <div className="grid gap-5 sm:grid-cols-2">
                                        <div className="space-y-1.5 sm:col-span-2">
                                            <FieldLabel htmlFor="mail_host">
                                                SMTP host
                                            </FieldLabel>
                                            <FieldInput
                                                id="mail_host"
                                                value={smtpForm.data.host}
                                                onChange={(e) =>
                                                    smtpForm.setData(
                                                        'host',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="smtp.example.com"
                                            />
                                            {smtpForm.errors.host ? (
                                                <p className="text-xs text-destructive">
                                                    {smtpForm.errors.host}
                                                </p>
                                            ) : null}
                                        </div>

                                        <div className="space-y-1.5">
                                            <FieldLabel htmlFor="mail_port">
                                                Port
                                            </FieldLabel>
                                            <FieldInput
                                                id="mail_port"
                                                type="number"
                                                min={1}
                                                max={65535}
                                                value={smtpForm.data.port}
                                                onChange={(e) =>
                                                    smtpForm.setData(
                                                        'port',
                                                        Number(
                                                            e.target.value,
                                                        ) || 587,
                                                    )
                                                }
                                            />
                                            {smtpForm.errors.port ? (
                                                <p className="text-xs text-destructive">
                                                    {smtpForm.errors.port}
                                                </p>
                                            ) : null}
                                        </div>

                                        <div className="space-y-1.5">
                                            <FieldLabel>Encryption</FieldLabel>
                                            <Select
                                                value={smtpForm.data.encryption}
                                                onValueChange={(value) =>
                                                    smtpForm.setData(
                                                        'encryption',
                                                        value,
                                                    )
                                                }
                                            >
                                                <SelectTrigger className="h-11 rounded-xl border-input bg-background/50 text-foreground dark:border-white/10 dark:bg-white/5">
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="tls">
                                                        TLS (587)
                                                    </SelectItem>
                                                    <SelectItem value="ssl">
                                                        SSL (465)
                                                    </SelectItem>
                                                    <SelectItem value="none">
                                                        None
                                                    </SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </div>

                                        <div className="space-y-1.5 sm:col-span-2">
                                            <FieldLabel htmlFor="mail_username">
                                                Username
                                            </FieldLabel>
                                            <FieldInput
                                                id="mail_username"
                                                value={smtpForm.data.username}
                                                onChange={(e) =>
                                                    smtpForm.setData(
                                                        'username',
                                                        e.target.value,
                                                    )
                                                }
                                                autoComplete="off"
                                            />
                                        </div>

                                        <div className="space-y-1.5 sm:col-span-2">
                                            <FieldLabel htmlFor="mail_password">
                                                Password
                                            </FieldLabel>
                                            <SettingsSecretInput
                                                id="mail_password"
                                                value={smtpForm.data.password}
                                                onChange={(e) =>
                                                    smtpForm.setData(
                                                        'password',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="SMTP password"
                                                autoComplete="new-password"
                                            />
                                            {smtpForm.errors.password ? (
                                                <p className="text-xs text-destructive">
                                                    {smtpForm.errors.password}
                                                </p>
                                            ) : null}
                                        </div>

                                        <div className="space-y-1.5">
                                            <FieldLabel htmlFor="mail_from_address">
                                                From address
                                            </FieldLabel>
                                            <FieldInput
                                                id="mail_from_address"
                                                type="email"
                                                value={
                                                    smtpForm.data.from_address
                                                }
                                                onChange={(e) =>
                                                    smtpForm.setData(
                                                        'from_address',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                            {smtpForm.errors.from_address ? (
                                                <p className="text-xs text-destructive">
                                                    {
                                                        smtpForm.errors
                                                            .from_address
                                                    }
                                                </p>
                                            ) : null}
                                        </div>

                                        <div className="space-y-1.5">
                                            <FieldLabel htmlFor="mail_from_name">
                                                From name
                                            </FieldLabel>
                                            <FieldInput
                                                id="mail_from_name"
                                                value={smtpForm.data.from_name}
                                                onChange={(e) =>
                                                    smtpForm.setData(
                                                        'from_name',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                            {smtpForm.errors.from_name ? (
                                                <p className="text-xs text-destructive">
                                                    {smtpForm.errors.from_name}
                                                </p>
                                            ) : null}
                                        </div>
                                    </div>
                                </SettingsCard>

                                {/* Email branding + footer */}
                                <SettingsCard>
                                    <SectionHeading
                                        icon={Palette}
                                        title="Email branding & footer"
                                        description="Logo and text shown in the footer of every outgoing email."
                                        color="bg-accent/10 border-accent/20 text-accent"
                                    />
                                    <div className="space-y-5">
                                        <BrandingUploadField
                                            label="Email branding logo"
                                            assetKey="email_branding_logo"
                                            currentUrl={
                                                smtp.email_branding_logo_url
                                            }
                                            hint="Used in the footer of all outgoing emails (recommended: transparent PNG)."
                                            onFileChange={(file) =>
                                                smtpForm.setData(
                                                    'email_branding_logo',
                                                    file,
                                                )
                                            }
                                            error={
                                                smtpForm.errors
                                                    .email_branding_logo
                                            }
                                        />

                                        <div className="h-px bg-border/80 dark:bg-white/5" />

                                        <div className="space-y-1.5">
                                            <FieldLabel htmlFor="mail_footer_tagline">
                                                Tagline
                                            </FieldLabel>
                                            <FieldInput
                                                id="mail_footer_tagline"
                                                value={
                                                    smtpForm.data
                                                        .mail_footer_tagline
                                                }
                                                onChange={(e) =>
                                                    smtpForm.setData(
                                                        'mail_footer_tagline',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="Your Complete Marine Solutions"
                                            />
                                        </div>
                                        <div className="space-y-1.5">
                                            <FieldLabel htmlFor="mail_footer_website">
                                                Website
                                            </FieldLabel>
                                            <FieldInput
                                                id="mail_footer_website"
                                                value={
                                                    smtpForm.data
                                                        .mail_footer_website
                                                }
                                                onChange={(e) =>
                                                    smtpForm.setData(
                                                        'mail_footer_website',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="www.overseas-ms.com"
                                            />
                                        </div>
                                        <div className="space-y-1.5">
                                            <FieldLabel htmlFor="mail_footer_certifications">
                                                Certifications bar
                                            </FieldLabel>
                                            <FieldInput
                                                id="mail_footer_certifications"
                                                value={
                                                    smtpForm.data
                                                        .mail_footer_certifications
                                                }
                                                onChange={(e) =>
                                                    smtpForm.setData(
                                                        'mail_footer_certifications',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="ISO 9001:2015 | ISO 14001:2015 | ICV Certified"
                                            />
                                        </div>
                                    </div>
                                </SettingsCard>

                                <div className="flex justify-end">
                                    <Button
                                        type="submit"
                                        className="h-11 rounded-xl px-6"
                                        disabled={
                                            !canUpdateApplication ||
                                            smtpForm.processing
                                        }
                                    >
                                        {smtpForm.processing ? (
                                            <Spinner />
                                        ) : null}
                                        Save email settings
                                    </Button>
                                </div>
                            </form>

                            {/* Test email */}
                            <SettingsCard>
                                <SectionHeading
                                    icon={Send}
                                    title="Send test email"
                                    description="Uses current SMTP fields (saved or unsaved). Check inbox and junk after sending."
                                    color="bg-emerald-500/10 border-emerald-500/20 text-emerald-500"
                                />
                                <div className="grid gap-5 sm:grid-cols-2">
                                    <div className="space-y-1.5">
                                        <FieldLabel htmlFor="test_recipient">
                                            Recipient
                                        </FieldLabel>
                                        <FieldInput
                                            id="test_recipient"
                                            type="email"
                                            value={testRecipient}
                                            onChange={(e) =>
                                                setTestRecipient(e.target.value)
                                            }
                                            placeholder={
                                                authUser?.email ??
                                                'you@company.com'
                                            }
                                        />
                                    </div>
                                    <div className="space-y-1.5">
                                        <FieldLabel htmlFor="test_subject">
                                            Subject
                                        </FieldLabel>
                                        <FieldInput
                                            id="test_subject"
                                            value={testSubject}
                                            onChange={(e) =>
                                                setTestSubject(e.target.value)
                                            }
                                            placeholder="SMTP test"
                                        />
                                    </div>
                                    <div className="space-y-1.5 sm:col-span-2">
                                        <FieldLabel htmlFor="test_body">
                                            Body
                                        </FieldLabel>
                                        <Textarea
                                            id="test_body"
                                            rows={4}
                                            value={testBody}
                                            onChange={(e) =>
                                                setTestBody(e.target.value)
                                            }
                                            placeholder="Message shown in the email body…"
                                            className="resize-none rounded-xl border-input bg-background/50 px-4 py-3 text-foreground transition-all focus-visible:ring-primary/40 dark:border-white/10 dark:bg-white/5"
                                        />
                                    </div>
                                    <div className="space-y-1.5 sm:col-span-2">
                                        <FieldLabel htmlFor="test_attachment">
                                            Attachment (optional)
                                        </FieldLabel>
                                        <Input
                                            id="test_attachment"
                                            type="file"
                                            accept=".pdf,.png,.jpg,.jpeg,.doc,.docx,application/pdf,image/*"
                                            onChange={(e) =>
                                                setTestAttachment(
                                                    e.target.files?.[0] ?? null,
                                                )
                                            }
                                            className="h-11 rounded-xl border-input bg-background/50 px-4 text-foreground transition-all file:mr-3 file:rounded-lg file:border-0 file:bg-muted file:px-3 file:py-1 file:text-xs file:font-medium dark:border-white/10 dark:bg-white/5 dark:file:bg-white/10"
                                        />
                                        <p className="ml-0.5 text-[10px] text-muted-foreground/50">
                                            {testAttachment
                                                ? `${testAttachment.name} (${(testAttachment.size / 1024).toFixed(1)} KB)`
                                                : 'PDF, PNG, JPG, or Word — max 20 MB'}
                                        </p>
                                    </div>

                                    <div className="sm:col-span-2">
                                        <Button
                                            type="button"
                                            variant="secondary"
                                            className="h-11 rounded-xl px-6"
                                            disabled={
                                                isSendingTest ||
                                                smtpForm.data.host.trim() === ''
                                            }
                                            onClick={() =>
                                                void handleSendTestEmail()
                                            }
                                        >
                                            {isSendingTest ? (
                                                <Spinner />
                                            ) : (
                                                <Send className="h-4 w-4" />
                                            )}
                                            Send test email
                                        </Button>
                                    </div>
                                </div>
                            </SettingsCard>
                        </div>
                    )}

                    {/* ══ WHATSAPP ══ */}
                    {tab === 'whatsapp' && whatsapp ? (
                        <WhatsAppSettingsPanel {...whatsapp} />
                    ) : null}

                    {/* ══ HIKVISION ══ */}
                    {tab === 'hikvision' && hikvision ? (
                        <HikvisionSettingsPanel {...hikvision} />
                    ) : null}

                    {/* ══ PREFERENCES ══ */}
                    {tab === 'preferences' && (
                        <form
                            onSubmit={submitPreferences}
                            className="space-y-6"
                        >
                            <SettingsCard>
                                <SectionHeading
                                    icon={Palette}
                                    title="Theme colors"
                                    description="Primary and accent colors applied globally across the UI."
                                    color="bg-accent/10 border-accent/20 text-accent"
                                />
                                <div className="grid gap-6 sm:grid-cols-2">
                                    <div className="space-y-1.5">
                                        <FieldLabel htmlFor="primary_color">
                                            Primary color
                                        </FieldLabel>
                                        <ThemeColorPicker
                                            id="primary_color"
                                            value={
                                                preferencesForm.data
                                                    .primary_color
                                            }
                                            onChange={(color) =>
                                                preferencesForm.setData(
                                                    'primary_color',
                                                    color,
                                                )
                                            }
                                        />
                                    </div>

                                    <div className="space-y-1.5">
                                        <FieldLabel htmlFor="accent_color">
                                            Accent color
                                        </FieldLabel>
                                        <ThemeColorPicker
                                            id="accent_color"
                                            value={
                                                preferencesForm.data
                                                    .accent_color
                                            }
                                            onChange={(color) =>
                                                preferencesForm.setData(
                                                    'accent_color',
                                                    color,
                                                )
                                            }
                                        />
                                    </div>
                                </div>
                            </SettingsCard>

                            <SettingsCard>
                                <SectionHeading
                                    icon={Layout}
                                    title="UI behavior"
                                    description="Default layout and navigation preferences."
                                    color="bg-sky-500/10 border-sky-500/20 text-sky-500"
                                />
                                <label className="flex cursor-pointer items-center gap-4 rounded-xl border border-border/80 bg-muted/20 p-4 transition-colors hover:bg-muted/50 dark:border-white/5 dark:bg-white/[0.02] dark:hover:bg-white/[0.04]">
                                    <Checkbox
                                        id="sidebar_compact_default"
                                        checked={
                                            preferencesForm.data
                                                .sidebar_compact_default
                                        }
                                        onCheckedChange={(checked) =>
                                            preferencesForm.setData(
                                                'sidebar_compact_default',
                                                checked === true,
                                            )
                                        }
                                    />
                                    <div>
                                        <p className="text-sm font-semibold text-foreground">
                                            Collapse sidebar by default
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            New sessions will open with the
                                            sidebar collapsed
                                        </p>
                                    </div>
                                    {preferencesForm.data
                                        .sidebar_compact_default ? (
                                        <CheckCircle2 className="ml-auto h-4 w-4 shrink-0 text-primary" />
                                    ) : null}
                                </label>
                            </SettingsCard>

                            <div className="flex justify-end">
                                <Button
                                    type="submit"
                                    className="h-11 rounded-xl px-6"
                                    disabled={
                                        !canUpdateApplication ||
                                        preferencesForm.processing
                                    }
                                >
                                    {preferencesForm.processing ? (
                                        <Spinner />
                                    ) : null}
                                    Save preferences
                                </Button>
                            </div>
                        </form>
                    )}
                </main>
            </div>
        </>
    );
}

ApplicationSettings.layout = {};
