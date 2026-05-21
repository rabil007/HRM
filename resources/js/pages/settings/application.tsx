import { Head, useForm } from '@inertiajs/react';
import { Building2, ImageIcon, Settings2 } from 'lucide-react';
import { useState } from 'react';
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
};

export default function ApplicationSettings({
    general,
    branding,
    preferences,
    timezones,
    date_formats,
    currencies,
}: Props) {
    const [tab, setTab] = useState('general');

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

    const brandingForm = useForm({
        main_logo: null as File | null,
        login_logo: null as File | null,
        favicon: null as File | null,
        login_background: null as File | null,
        email_branding_logo: null as File | null,
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
                    <TabsList className="grid w-full max-w-lg grid-cols-3">
                        <TabsTrigger value="general" className="gap-2">
                            <Building2 className="size-4" />
                            General
                        </TabsTrigger>
                        <TabsTrigger value="branding" className="gap-2">
                            <ImageIcon className="size-4" />
                            Branding
                        </TabsTrigger>
                        <TabsTrigger value="preferences" className="gap-2">
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
                                    <BrandingUploadField
                                        label="Email branding logo"
                                        assetKey="email_branding_logo"
                                        currentUrl={branding.email_branding_logo_url}
                                        onFileChange={(file) => brandingForm.setData('email_branding_logo', file)}
                                        error={brandingForm.errors.email_branding_logo}
                                    />

                                    <Button type="submit" disabled={brandingForm.processing}>
                                        {brandingForm.processing ? <Spinner /> : null}
                                        Save branding
                                    </Button>
                                </form>
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
                                <form onSubmit={submitBranding} className="grid max-w-md gap-5">
                                    <div className="space-y-2">
                                        <Label htmlFor="primary_color">Primary color</Label>
                                        <div className="flex items-center gap-3">
                                            <Input
                                                id="primary_color"
                                                type="color"
                                                className="h-11 w-14 cursor-pointer p-1"
                                                value={brandingForm.data.primary_color}
                                                onChange={(e) => brandingForm.setData('primary_color', e.target.value)}
                                            />
                                            <Input
                                                value={brandingForm.data.primary_color}
                                                onChange={(e) => brandingForm.setData('primary_color', e.target.value)}
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
                                                value={brandingForm.data.accent_color}
                                                onChange={(e) => brandingForm.setData('accent_color', e.target.value)}
                                            />
                                            <Input
                                                value={brandingForm.data.accent_color}
                                                onChange={(e) => brandingForm.setData('accent_color', e.target.value)}
                                            />
                                        </div>
                                    </div>

                                    <div className="flex items-center gap-2">
                                        <Checkbox
                                            id="sidebar_compact_default"
                                            checked={brandingForm.data.sidebar_compact_default}
                                            onCheckedChange={(checked) =>
                                                brandingForm.setData('sidebar_compact_default', checked === true)
                                            }
                                        />
                                        <Label htmlFor="sidebar_compact_default" className="cursor-pointer font-normal">
                                            Collapse sidebar by default for new sessions
                                        </Label>
                                    </div>

                                    <Button type="submit" disabled={brandingForm.processing}>
                                        {brandingForm.processing ? <Spinner /> : null}
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
