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
import { Switch } from '@/components/ui/switch';
import type { Branch, BranchFormData, Company, Country } from '../types';

export function BranchFormSheet({
    open,
    onOpenChange,
    branch,
    companies,
    countries,
    form,
    onSubmit,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    branch: Branch | null;
    companies: Company[];
    countries: Country[];
    form: InertiaFormProps<BranchFormData>;
    onSubmit: () => void;
}) {
    const selectedCountry = countries.find((c) => c.code === form.data.country);
    const dialCode = selectedCountry?.dial_code ?? '';

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="w-full sm:max-w-md border-white/5 bg-black/60 backdrop-blur-3xl p-0 flex flex-col"
            >
                <SheetHeader className="p-8 pb-6 border-b border-white/5">
                    <SheetTitle className="text-xl font-bold tracking-tight text-white">
                        {branch ? 'Edit Branch' : 'New Branch'}
                    </SheetTitle>
                    <SheetDescription className="text-sm text-muted-foreground/80 mt-1">
                        {branch ? 'Update branch details.' : 'Add a new branch to a company.'}
                    </SheetDescription>
                </SheetHeader>

                <div className="flex-1 overflow-y-auto p-8 space-y-8">
                    <div className="space-y-5">
                        <div className="space-y-2">
                            <Label
                                htmlFor="company_id"
                                className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70"
                            >
                                Company
                            </Label>
                            <select
                                id="company_id"
                                className="w-full rounded-xl border border-white/10 bg-white/5 h-11 px-3 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-primary/40 transition-all"
                                value={form.data.company_id}
                                onChange={(e) =>
                                    form.setData('company_id', e.target.value ? Number(e.target.value) : '')
                                }
                            >
                                <option value="">Select company</option>
                                {companies.map((company) => (
                                    <option key={company.id} value={company.id}>
                                        {company.name}
                                    </option>
                                ))}
                            </select>
                            {form.errors.company_id ? (
                                <div className="text-xs font-medium text-destructive">{form.errors.company_id}</div>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <Label
                                htmlFor="name"
                                className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70"
                            >
                                Branch Name
                            </Label>
                            <Input
                                id="name"
                                placeholder="Main Office"
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
                                    htmlFor="code"
                                    className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70"
                                >
                                    Code
                                </Label>
                                <Input
                                    id="code"
                                    placeholder="DXB-HQ"
                                    className="rounded-xl border-white/10 bg-white/5 focus-visible:ring-primary/40 h-11 transition-all"
                                    value={form.data.code}
                                    onChange={(e) => form.setData('code', e.target.value)}
                                />
                                {form.errors.code ? (
                                    <div className="text-xs font-medium text-destructive">{form.errors.code}</div>
                                ) : null}
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
                                    onChange={(e) => form.setData('status', e.target.value as 'active' | 'inactive')}
                                >
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                                {form.errors.status ? (
                                    <div className="text-xs font-medium text-destructive">{form.errors.status}</div>
                                ) : null}
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
                                placeholder="Building 1, Street 2"
                                className="rounded-xl border-white/10 bg-white/5 focus-visible:ring-primary/40 h-11 transition-all"
                                value={form.data.address}
                                onChange={(e) => form.setData('address', e.target.value)}
                            />
                            {form.errors.address ? (
                                <div className="text-xs font-medium text-destructive">{form.errors.address}</div>
                            ) : null}
                        </div>

                        <div className="grid grid-cols-2 gap-4">
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
                                {form.errors.city ? (
                                    <div className="text-xs font-medium text-destructive">{form.errors.city}</div>
                                ) : null}
                            </div>

                            <div className="space-y-2">
                                <Label
                                    htmlFor="country"
                                    className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70"
                                >
                                    Country
                                </Label>
                                <select
                                    id="country"
                                    className="w-full rounded-xl border border-white/10 bg-white/5 h-11 px-3 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-primary/40 transition-all"
                                    value={form.data.country}
                                    onChange={(e) => form.setData('country', e.target.value)}
                                >
                                    <option value="">Select country</option>
                                    {countries.map((country) => (
                                        <option key={country.code} value={country.code}>
                                            {country.code} {country.name}
                                        </option>
                                    ))}
                                </select>
                                {form.errors.country ? (
                                    <div className="text-xs font-medium text-destructive">{form.errors.country}</div>
                                ) : null}
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-4">
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
                                        className="rounded-xl border-white/10 bg-white/5 focus-visible:ring-primary/40 h-11 transition-all pl-14"
                                        value={form.data.phone}
                                        onChange={(e) => form.setData('phone', e.target.value)}
                                    />
                                </div>
                                {form.errors.phone ? (
                                    <div className="text-xs font-medium text-destructive">{form.errors.phone}</div>
                                ) : null}
                            </div>

                            <div className="space-y-2">
                                <Label
                                    htmlFor="email"
                                    className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70"
                                >
                                    Email
                                </Label>
                                <Input
                                    id="email"
                                    type="email"
                                    placeholder="branch@company.com"
                                    className="rounded-xl border-white/10 bg-white/5 focus-visible:ring-primary/40 h-11 transition-all"
                                    value={form.data.email}
                                    onChange={(e) => form.setData('email', e.target.value)}
                                />
                                {form.errors.email ? (
                                    <div className="text-xs font-medium text-destructive">{form.errors.email}</div>
                                ) : null}
                            </div>
                        </div>

                        <div className="flex items-center justify-between rounded-xl border border-white/5 bg-white/5 px-4 py-3">
                            <div className="min-w-0">
                                <div className="text-sm font-semibold text-foreground">Headquarters</div>
                                <div className="text-xs text-muted-foreground/80 truncate">
                                    Mark this branch as the main office.
                                </div>
                            </div>
                            <Switch
                                checked={form.data.is_headquarters}
                                onCheckedChange={(checked) => form.setData('is_headquarters', checked)}
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
                        {branch ? 'Save Changes' : 'Create Branch'}
                    </Button>
                </div>
            </SheetContent>
        </Sheet>
    );
}

