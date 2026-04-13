import type { InertiaFormProps } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import type { Company, CompanyFormData, Country, Currency } from '../types';

const weekDays: { value: number; label: string }[] = [
    { value: 1, label: 'Mon' },
    { value: 2, label: 'Tue' },
    { value: 3, label: 'Wed' },
    { value: 4, label: 'Thu' },
    { value: 5, label: 'Fri' },
    { value: 6, label: 'Sat' },
    { value: 7, label: 'Sun' },
];

export function CompanyFormSheet({
    open,
    onOpenChange,
    company,
    countries,
    currencies,
    form,
    onSubmit,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    company: Company | null;
    countries: Country[];
    currencies: Currency[];
    form: InertiaFormProps<CompanyFormData>;
    onSubmit: () => void;
}) {
    const selectedCountry = countries.find((c) => c.id === form.data.country_id);
    const dialCode = selectedCountry?.dial_code ?? '';

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="w-full sm:max-w-md border-white/5 bg-black/60 backdrop-blur-3xl p-0 flex flex-col"
            >
                <SheetHeader className="p-8 pb-6 border-b border-white/5">
                    <SheetTitle className="text-xl font-bold tracking-tight text-white">
                        {company ? 'Edit Company' : 'New Company'}
                    </SheetTitle>
                    <SheetDescription className="text-sm text-muted-foreground/80 mt-1">
                        {company ? 'Update organization profile details.' : 'Register a new entity in the system.'}
                    </SheetDescription>
                </SheetHeader>

                <div className="flex-1 overflow-y-auto p-8 space-y-8">
                    <div className="space-y-5">
                        <div className="space-y-2">
                            <Label
                                htmlFor="logo"
                                className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70"
                            >
                                Logo
                            </Label>
                            <Input
                                id="logo"
                                type="file"
                                accept="image/*"
                                className="rounded-xl border-white/10 bg-white/5 focus-visible:ring-primary/40 h-11 transition-all file:mr-4 file:rounded-lg file:border-0 file:bg-white/10 file:px-3 file:py-2 file:text-xs file:font-semibold file:text-foreground"
                                onChange={(e) => form.setData('logo', e.target.files?.[0] ?? null)}
                            />
                            {form.errors.logo ? (
                                <div className="text-xs font-medium text-destructive">{form.errors.logo}</div>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <Label
                                htmlFor="name"
                                className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70"
                            >
                                Company Name
                            </Label>
                            <Input
                                id="name"
                                placeholder="Acme Solutions"
                                className="rounded-xl border-white/10 bg-white/5 focus-visible:ring-primary/40 h-11 transition-all"
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                            />
                            {form.errors.name ? (
                                <div className="text-xs font-medium text-destructive">{form.errors.name}</div>
                            ) : null}
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <Label
                                    htmlFor="industry"
                                    className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70"
                                >
                                    Industry
                                </Label>
                                <Input
                                    id="industry"
                                    placeholder="Technology"
                                    className="rounded-xl border-white/10 bg-white/5 focus-visible:ring-primary/40 h-11 transition-all"
                                    value={form.data.industry}
                                    onChange={(e) => form.setData('industry', e.target.value)}
                                />
                            </div>
                            <div className="space-y-2">
                                <Label
                                    htmlFor="city"
                                    className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70"
                                >
                                    City
                                </Label>
                                <Input
                                    id="city"
                                    placeholder="Dubai"
                                    className="rounded-xl border-white/10 bg-white/5 focus-visible:ring-primary/40 h-11 transition-all"
                                    value={form.data.city}
                                    onChange={(e) => form.setData('city', e.target.value)}
                                />
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <Label
                                    htmlFor="country_id"
                                    className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70"
                                >
                                    Country
                                </Label>
                                <select
                                    id="country_id"
                                    className="w-full rounded-xl border border-white/10 bg-white/5 h-11 px-3 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-primary/40 transition-all"
                                    value={form.data.country_id}
                                    onChange={(e) =>
                                        form.setData('country_id', e.target.value ? Number(e.target.value) : '')
                                    }
                                >
                                    <option value="">Select country</option>
                                    {countries.map((country) => (
                                        <option key={country.id} value={country.id}>
                                            {country.code} {country.name}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="space-y-2">
                                <Label
                                    htmlFor="phone"
                                    className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70"
                                >
                                    Phone
                                </Label>
                                <div className="relative">
                                    {dialCode ? (
                                        <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                                            <span className="text-sm text-muted-foreground/80">{dialCode}</span>
                                        </div>
                                    ) : null}
                                    <Input
                                        id="phone"
                                        placeholder="Phone number"
                                        className={`rounded-xl border-white/10 bg-white/5 focus-visible:ring-primary/40 h-11 transition-all ${dialCode ? 'pl-14' : ''}`}
                                        value={form.data.phone}
                                        onChange={(e) => form.setData('phone', e.target.value)}
                                    />
                                </div>
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <Label
                                    htmlFor="registration_number"
                                    className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70"
                                >
                                    Registration #
                                </Label>
                                <Input
                                    id="registration_number"
                                    placeholder="Reg-123"
                                    className="rounded-xl border-white/10 bg-white/5 focus-visible:ring-primary/40 h-11 transition-all"
                                    value={form.data.registration_number}
                                    onChange={(e) => form.setData('registration_number', e.target.value)}
                                />
                            </div>
                            <div className="space-y-2">
                                <Label
                                    htmlFor="tax_id"
                                    className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70"
                                >
                                    Tax ID
                                </Label>
                                <Input
                                    id="tax_id"
                                    placeholder="TRN..."
                                    className="rounded-xl border-white/10 bg-white/5 focus-visible:ring-primary/40 h-11 transition-all"
                                    value={form.data.tax_id}
                                    onChange={(e) => form.setData('tax_id', e.target.value)}
                                />
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <Label
                                    htmlFor="company_size"
                                    className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70"
                                >
                                    Company size
                                </Label>
                                <Input
                                    id="company_size"
                                    placeholder="1-50"
                                    className="rounded-xl border-white/10 bg-white/5 focus-visible:ring-primary/40 h-11 transition-all"
                                    value={form.data.company_size}
                                    onChange={(e) => form.setData('company_size', e.target.value)}
                                />
                            </div>
                            <div className="space-y-2">
                                <Label
                                    htmlFor="currency_id"
                                    className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70"
                                >
                                    Currency
                                </Label>
                                <select
                                    id="currency_id"
                                    className="w-full rounded-xl border border-white/10 bg-white/5 h-11 px-3 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-primary/40 transition-all"
                                    value={form.data.currency_id}
                                    onChange={(e) =>
                                        form.setData('currency_id', e.target.value ? Number(e.target.value) : '')
                                    }
                                >
                                    <option value="">Select currency</option>
                                    {currencies.map((currency) => (
                                        <option key={currency.id} value={currency.id}>
                                            {currency.code} {currency.name}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        </div>
                    </div>

                    <div className="pt-4 space-y-5 border-t border-white/5">
                        <div className="space-y-2">
                            <Label
                                htmlFor="address"
                                className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70"
                            >
                                Address
                            </Label>
                            <Input
                                id="address"
                                placeholder="Building, street..."
                                className="rounded-xl border-white/10 bg-white/5 focus-visible:ring-primary/40 h-11 transition-all"
                                value={form.data.address}
                                onChange={(e) => form.setData('address', e.target.value)}
                            />
                        </div>

                        <div className="space-y-2">
                            <Label
                                htmlFor="website"
                                className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70"
                            >
                                Website
                            </Label>
                            <Input
                                id="website"
                                placeholder="company.com"
                                className="rounded-xl border-white/10 bg-white/5 focus-visible:ring-primary/40 h-11 transition-all"
                                value={form.data.website}
                                onChange={(e) => form.setData('website', e.target.value)}
                            />
                        </div>

                        <div className="space-y-2">
                            <Label
                                htmlFor="email"
                                className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70"
                            >
                                Contact Email
                            </Label>
                            <Input
                                id="email"
                                type="email"
                                placeholder="hr@company.com"
                                className="rounded-xl border-white/10 bg-white/5 focus-visible:ring-primary/40 h-11 transition-all"
                                value={form.data.email}
                                onChange={(e) => form.setData('email', e.target.value)}
                            />
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <Label
                                    htmlFor="timezone"
                                    className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70"
                                >
                                    Timezone
                                </Label>
                                <Input
                                    id="timezone"
                                    placeholder="Asia/Dubai"
                                    className="rounded-xl border-white/10 bg-white/5 focus-visible:ring-primary/40 h-11 transition-all"
                                    value={form.data.timezone}
                                    onChange={(e) => form.setData('timezone', e.target.value)}
                                />
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <Label
                                    htmlFor="payroll_cycle"
                                    className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70"
                                >
                                    Payroll cycle
                                </Label>
                                <select
                                    id="payroll_cycle"
                                    className="w-full rounded-xl border border-white/10 bg-white/5 h-11 px-3 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-primary/40 transition-all"
                                    value={form.data.payroll_cycle}
                                    onChange={(e) =>
                                        form.setData('payroll_cycle', e.target.value as 'monthly' | 'biweekly' | 'weekly')
                                    }
                                >
                                    <option value="monthly">Monthly</option>
                                    <option value="biweekly">Biweekly</option>
                                    <option value="weekly">Weekly</option>
                                </select>
                            </div>
                            <div className="space-y-2">
                                <Label
                                    htmlFor="status"
                                    className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70"
                                >
                                    Status
                                </Label>
                                <select
                                    id="status"
                                    className="w-full rounded-xl border border-white/10 bg-white/5 h-11 px-3 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-primary/40 transition-all"
                                    value={form.data.status}
                                    onChange={(e) =>
                                        form.setData('status', e.target.value as 'active' | 'suspended' | 'inactive')
                                    }
                                >
                                    <option value="active">Active</option>
                                    <option value="suspended">Suspended</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                        </div>

                        <div className="space-y-2">
                            <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                Working days
                            </Label>
                            <div className="flex flex-wrap gap-2">
                                {weekDays.map((day) => {
                                    const checked = form.data.working_days.includes(day.value);

                                    return (
                                        <label
                                            key={day.value}
                                            className={`flex items-center gap-2 rounded-xl border px-3 h-11 text-sm transition-all cursor-pointer ${
                                                checked
                                                    ? 'border-primary/30 bg-primary/10 text-foreground'
                                                    : 'border-white/10 bg-white/5 text-muted-foreground'
                                            }`}
                                        >
                                            <input
                                                type="checkbox"
                                                className="accent-primary"
                                                checked={checked}
                                                onChange={(e) => {
                                                    const next = e.target.checked
                                                        ? [...form.data.working_days, day.value]
                                                        : form.data.working_days.filter((v) => v !== day.value);
                                                    form.setData('working_days', next);
                                                }}
                                            />
                                            {day.label}
                                        </label>
                                    );
                                })}
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <Label
                                    htmlFor="wps_agent_code"
                                    className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70"
                                >
                                    WPS agent code
                                </Label>
                                <Input
                                    id="wps_agent_code"
                                    placeholder="Agent..."
                                    className="rounded-xl border-white/10 bg-white/5 focus-visible:ring-primary/40 h-11 transition-all"
                                    value={form.data.wps_agent_code}
                                    onChange={(e) => form.setData('wps_agent_code', e.target.value)}
                                />
                            </div>
                            <div className="space-y-2">
                                <Label
                                    htmlFor="wps_mol_uid"
                                    className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70"
                                >
                                    WPS MOL UID
                                </Label>
                                <Input
                                    id="wps_mol_uid"
                                    placeholder="UID..."
                                    className="rounded-xl border-white/10 bg-white/5 focus-visible:ring-primary/40 h-11 transition-all"
                                    value={form.data.wps_mol_uid}
                                    onChange={(e) => form.setData('wps_mol_uid', e.target.value)}
                                />
                            </div>
                        </div>
                    </div>
                </div>

                <div className="p-6 border-t border-white/5 bg-black/20 flex gap-3">
                    <Button
                        variant="ghost"
                        onClick={() => onOpenChange(false)}
                        className="rounded-xl h-11 px-6 text-muted-foreground flex-1"
                    >
                        Cancel
                    </Button>
                    <Button
                        className="rounded-xl h-11 px-8 flex-1 font-semibold"
                        disabled={form.processing}
                        onClick={onSubmit}
                    >
                        {company ? 'Save Changes' : 'Create Company'}
                    </Button>
                </div>
            </SheetContent>
        </Sheet>
    );
}

