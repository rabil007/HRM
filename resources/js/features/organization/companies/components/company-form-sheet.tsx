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
import type { InertiaFormProps } from '@inertiajs/react';
import type { Company, CompanyFormData, Country, Currency } from '../types';

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

