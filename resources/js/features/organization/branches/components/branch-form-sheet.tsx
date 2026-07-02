import type { InertiaFormProps } from '@inertiajs/react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
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
import type { Branch, BranchFormData, Country } from '../types';

export function BranchFormSheet({
    open,
    onOpenChange,
    branch,
    countries,
    form,
    onSubmit,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    branch: Branch | null;
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
                className="flex w-full flex-col rounded-none glass-card p-0 sm:max-w-md"
            >
                <SheetHeader className="border-b border-border/60 p-8 pb-6">
                    <SheetTitle className="text-xl font-bold tracking-tight">
                        {branch ? 'Edit Branch' : 'New Branch'}
                    </SheetTitle>
                    <SheetDescription className="mt-1 text-sm text-muted-foreground/80">
                        {branch
                            ? 'Update branch details.'
                            : 'Add a new branch to a company.'}
                    </SheetDescription>
                </SheetHeader>

                <div className="flex-1 space-y-8 overflow-y-auto p-8">
                    <div className="space-y-5">
                        <div className="space-y-2">
                            <Label
                                htmlFor="name"
                                className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                            >
                                Branch Name
                            </Label>
                            <Input
                                id="name"
                                placeholder="Main Office"
                                className="h-11 rounded-xl border-border bg-card transition-all focus-visible:ring-primary/40"
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                            />
                            {form.errors.name ? (
                                <div className="text-xs font-medium text-destructive">
                                    {form.errors.name}
                                </div>
                            ) : null}
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <Label
                                    htmlFor="code"
                                    className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                                >
                                    Code
                                </Label>
                                <Input
                                    id="code"
                                    placeholder="DXB-HQ"
                                    className="h-11 rounded-xl border-border bg-card transition-all focus-visible:ring-primary/40"
                                    value={form.data.code}
                                    onChange={(e) =>
                                        form.setData('code', e.target.value)
                                    }
                                />
                                {form.errors.code ? (
                                    <div className="text-xs font-medium text-destructive">
                                        {form.errors.code}
                                    </div>
                                ) : null}
                            </div>

                            <div className="space-y-2">
                                <Label
                                    htmlFor="status"
                                    className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                                >
                                    Status
                                </Label>
                                <AppSelect
                                    value={form.data.status}
                                    onValueChange={(v) =>
                                        form.setData(
                                            'status',
                                            v as 'active' | 'inactive',
                                        )
                                    }
                                    variant="card"
                                >
                                    <AppSelectItem value="active">
                                        Active
                                    </AppSelectItem>
                                    <AppSelectItem value="inactive">
                                        Inactive
                                    </AppSelectItem>
                                </AppSelect>
                                {form.errors.status ? (
                                    <div className="text-xs font-medium text-destructive">
                                        {form.errors.status}
                                    </div>
                                ) : null}
                            </div>
                        </div>
                    </div>

                    <div className="space-y-5 border-t border-border/60 pt-4">
                        <div className="space-y-2">
                            <Label
                                htmlFor="address"
                                className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                            >
                                Address
                            </Label>
                            <Input
                                id="address"
                                placeholder="Building 1, Street 2"
                                className="h-11 rounded-xl border-border bg-card transition-all focus-visible:ring-primary/40"
                                value={form.data.address}
                                onChange={(e) =>
                                    form.setData('address', e.target.value)
                                }
                            />
                            {form.errors.address ? (
                                <div className="text-xs font-medium text-destructive">
                                    {form.errors.address}
                                </div>
                            ) : null}
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <Label
                                    htmlFor="city"
                                    className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                                >
                                    City
                                </Label>
                                <Input
                                    id="city"
                                    placeholder="Dubai"
                                    className="h-11 rounded-xl border-border bg-card transition-all focus-visible:ring-primary/40"
                                    value={form.data.city}
                                    onChange={(e) =>
                                        form.setData('city', e.target.value)
                                    }
                                />
                                {form.errors.city ? (
                                    <div className="text-xs font-medium text-destructive">
                                        {form.errors.city}
                                    </div>
                                ) : null}
                            </div>

                            <div className="space-y-2">
                                <Label
                                    htmlFor="country"
                                    className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                                >
                                    Country
                                </Label>
                                <AppSelect
                                    value={form.data.country}
                                    onValueChange={(v) =>
                                        form.setData('country', v)
                                    }
                                    variant="card"
                                    placeholder="Select country"
                                >
                                    <AppSelectItem value="">
                                        Select country
                                    </AppSelectItem>
                                    {countries.map((country) => (
                                        <AppSelectItem
                                            key={country.code}
                                            value={country.code}
                                        >
                                            {country.code} {country.name}
                                        </AppSelectItem>
                                    ))}
                                </AppSelect>
                                {form.errors.country ? (
                                    <div className="text-xs font-medium text-destructive">
                                        {form.errors.country}
                                    </div>
                                ) : null}
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <Label
                                    htmlFor="phone"
                                    className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                                >
                                    Phone
                                </Label>
                                <div className="relative">
                                    {dialCode ? (
                                        <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                                            <span className="text-sm text-muted-foreground/80">
                                                {dialCode}
                                            </span>
                                        </div>
                                    ) : null}
                                    <Input
                                        id="phone"
                                        placeholder="Phone number"
                                        className="h-11 rounded-xl border-border bg-card pl-14 transition-all focus-visible:ring-primary/40"
                                        value={form.data.phone}
                                        onChange={(e) =>
                                            form.setData(
                                                'phone',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                                {form.errors.phone ? (
                                    <div className="text-xs font-medium text-destructive">
                                        {form.errors.phone}
                                    </div>
                                ) : null}
                            </div>

                            <div className="space-y-2">
                                <Label
                                    htmlFor="email"
                                    className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                                >
                                    Email
                                </Label>
                                <Input
                                    id="email"
                                    type="email"
                                    placeholder="branch@company.com"
                                    className="h-11 rounded-xl border-border bg-card transition-all focus-visible:ring-primary/40"
                                    value={form.data.email}
                                    onChange={(e) =>
                                        form.setData('email', e.target.value)
                                    }
                                />
                                {form.errors.email ? (
                                    <div className="text-xs font-medium text-destructive">
                                        {form.errors.email}
                                    </div>
                                ) : null}
                            </div>
                        </div>

                        <div className="flex items-center justify-between rounded-xl border border-border/60 bg-muted/30 px-4 py-3">
                            <div className="min-w-0">
                                <div className="text-sm font-semibold text-foreground">
                                    Headquarters
                                </div>
                                <div className="truncate text-xs text-muted-foreground/80">
                                    Mark this branch as the main office.
                                </div>
                            </div>
                            <Switch
                                checked={form.data.is_headquarters}
                                onCheckedChange={(checked) =>
                                    form.setData('is_headquarters', checked)
                                }
                            />
                        </div>
                    </div>
                </div>

                <div className="flex gap-3 border-t border-border/60 bg-background/40 p-6">
                    <Button
                        variant="ghost"
                        onClick={() => onOpenChange(false)}
                        className="h-11 flex-1 rounded-xl px-6 text-muted-foreground"
                    >
                        Cancel
                    </Button>
                    <Button
                        className="h-11 flex-1 rounded-xl px-8 font-semibold"
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
