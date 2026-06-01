import { Head, useForm } from '@inertiajs/react';
import { Eye, EyeOff, FileText, Lock, Settings2, ToggleLeft, Unlock } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';

type FieldConfig = { visible: boolean; required: boolean };

type Configuration = {
    version: number;
    tabs: Record<string, { visible: boolean }>;
    fields: Record<string, Record<string, FieldConfig>>;
};

type Registry = {
    tab_order: string[];
    tab_labels: Record<string, string>;
    tab_to_tables: Record<string, string[]>;
    fields_by_table: Record<string, Record<string, string>>;
};

type TemplatePayload = {
    id: number;
    name: string;
    description: string | null;
    is_active: boolean;
    configuration_json: Configuration;
} | null;

export default function EmployeeProfileTemplateForm({
    template,
    registry,
    defaultConfiguration,
}: {
    template: TemplatePayload;
    registry: Registry;
    defaultConfiguration: Configuration;
}) {
    const isEdit = template !== null;
    const [configuration, setConfiguration] = useState<Configuration>(
        template?.configuration_json ?? defaultConfiguration,
    );
    const [activeTab, setActiveTab] = useState(registry.tab_order[0] ?? 'personal');

    const form = useForm({
        name: template?.name ?? '',
        description: template?.description ?? '',
        is_active: template?.is_active ?? true,
        configuration_json: JSON.stringify(configuration),
    });

    const setTabVisible = (tabKey: string, visible: boolean) => {
        if (tabKey === 'personal') {
            return;
        }

        setConfiguration((current) => ({
            ...current,
            tabs: {
                ...current.tabs,
                [tabKey]: { visible },
            },
        }));
    };

    const setFieldConfig = (table: string, fieldKey: string, patch: Partial<FieldConfig>) => {
        setConfiguration((current) => ({
            ...current,
            fields: {
                ...current.fields,
                [table]: {
                    ...current.fields[table],
                    [fieldKey]: {
                        ...current.fields[table][fieldKey],
                        ...patch,
                    },
                },
            },
        }));
    };

    const tablesForActiveTab = useMemo(
        () => registry.tab_to_tables[activeTab] ?? [],
        [activeTab, registry.tab_to_tables],
    );

    /** Count of fields that are visible in a given tab */
    const visibleFieldCountForTab = (tabKey: string) => {
        const tables = registry.tab_to_tables[tabKey] ?? [];
        let count = 0;

        for (const table of tables) {
            for (const fieldKey of Object.keys(registry.fields_by_table[table] ?? {})) {
                const field = configuration.fields[table]?.[fieldKey];
                const isVisible = field?.visible ?? true;

                if (isVisible) {
                    count++;
                }
            }
        }

        return count;
    };

    const totalFieldCountForTab = (tabKey: string) => {
        const tables = registry.tab_to_tables[tabKey] ?? [];
        let count = 0;

        for (const table of tables) {
            count += Object.keys(registry.fields_by_table[table] ?? {}).length;
        }

        return count;
    };

    const submit = () => {
        form.transform((data) => ({
            ...data,
            configuration_json: JSON.stringify({
                ...configuration,
                version: 1,
                tabs: {
                    ...configuration.tabs,
                    personal: { visible: true },
                },
            }),
        }));

        if (isEdit && template) {
            form.put(`/organization/templates/employee-profile/${template.id}`);
        } else {
            form.post('/organization/templates/employee-profile');
        }
    };

    const isTabVisible = (tabKey: string) => configuration.tabs[tabKey]?.visible ?? true;

    return (
        <>
            <Head title={isEdit ? 'Edit profile template' : 'Create profile template'} />
            <Main>
                <PageHeader
                    kicker="Organization"
                    title={isEdit ? 'Edit profile template' : 'Create profile template'}
                    description="Configure which profile tabs and fields are visible or required."
                    right={
                        <div className="flex items-center gap-3">
                            <Button
                                type="button"
                                variant="outline"
                                className="rounded-xl h-11 px-5"
                                asChild
                            >
                                <a href="/organization/templates/employee-profile">Cancel</a>
                            </Button>
                            <Button
                                type="button"
                                className="rounded-xl h-11 px-5"
                                disabled={form.processing}
                                onClick={submit}
                            >
                                {form.processing ? 'Saving…' : 'Save template'}
                            </Button>
                        </div>
                    }
                />

                <div className="flex flex-col gap-6">
                    {/* ── Settings card ── */}
                    <Card className="border-white/5 bg-white/5">
                        <CardContent className="p-5">
                            <div className="flex items-center gap-3 mb-5">
                                <div className="w-8 h-8 rounded-xl bg-primary/10 border border-primary/20 flex items-center justify-center text-primary">
                                    <Settings2 className="w-4 h-4" />
                                </div>
                                <h3 className="text-sm font-bold uppercase tracking-widest text-muted-foreground">
                                    Template Settings
                                </h3>
                            </div>

                            <div className="grid gap-5 md:grid-cols-2">
                                {/* Name */}
                                <div className="space-y-1.5">
                                    <Label
                                        htmlFor="name"
                                        className="text-[10px] uppercase tracking-widest text-muted-foreground/60 ml-1 font-bold"
                                    >
                                        Template Name
                                    </Label>
                                    <Input
                                        id="name"
                                        value={form.data.name}
                                        onChange={(event) =>
                                            form.setData('name', event.target.value)
                                        }
                                        placeholder="e.g. Seafarer Profile"
                                        className="rounded-xl border-white/10 bg-white/5 focus-visible:ring-primary/40 h-11 text-base font-semibold transition-all px-4"
                                    />
                                    {form.errors.name ? (
                                        <p className="text-xs font-medium text-destructive mt-1">
                                            {form.errors.name}
                                        </p>
                                    ) : null}
                                </div>

                                {/* Active toggle */}
                                <div className="flex items-end pb-1">
                                    <label className="flex items-center gap-4 p-3.5 rounded-xl border border-white/5 bg-white/[0.02] cursor-pointer w-full hover:bg-white/[0.04] transition-colors">
                                        <Switch
                                            id="is_active"
                                            checked={form.data.is_active}
                                            onCheckedChange={(value) =>
                                                form.setData('is_active', value)
                                            }
                                        />
                                        <div>
                                            <p className="text-sm font-semibold text-foreground">
                                                Active
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                Make this template available for use
                                            </p>
                                        </div>
                                    </label>
                                </div>

                                {/* Description */}
                                <div className="space-y-1.5 md:col-span-2">
                                    <Label
                                        htmlFor="description"
                                        className="text-[10px] uppercase tracking-widest text-muted-foreground/60 ml-1 font-bold"
                                    >
                                        Description
                                    </Label>
                                    <Textarea
                                        id="description"
                                        value={form.data.description}
                                        onChange={(event) =>
                                            form.setData('description', event.target.value)
                                        }
                                        placeholder="Briefly describe when this template should be used…"
                                        className="rounded-xl border-white/10 bg-white/5 focus-visible:ring-primary/40 resize-none min-h-[72px] px-4 py-3 transition-all"
                                        rows={2}
                                    />
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* ── Tab + field configuration ── */}
                    <div className="grid grid-cols-1 lg:grid-cols-12 gap-6 min-h-[560px]">
                        {/* Sidebar — tab navigator */}
                        <aside className="lg:col-span-3">
                            <Card className="border-white/5 bg-white/5 h-full flex flex-col overflow-hidden">
                                <div className="p-4 border-b border-white/5 bg-white/[0.02] flex items-center justify-between">
                                    <h3 className="text-xs font-bold uppercase tracking-widest text-muted-foreground">
                                        Tabs
                                    </h3>
                                    <span className="text-[10px] font-mono opacity-50 border border-white/5 rounded px-1.5 py-0.5">
                                        {registry.tab_order.length}
                                    </span>
                                </div>
                                <ScrollArea className="flex-1">
                                    <div className="p-2 space-y-1">
                                        {registry.tab_order.map((tabKey) => {
                                            const isActive = activeTab === tabKey;
                                            const isPersonal = tabKey === 'personal';
                                            const tabVisible = isPersonal || isTabVisible(tabKey);
                                            const visibleCount = visibleFieldCountForTab(tabKey);
                                            const totalCount = totalFieldCountForTab(tabKey);

                                            return (
                                                <button
                                                    key={tabKey}
                                                    type="button"
                                                    onClick={() => setActiveTab(tabKey)}
                                                    className={cn(
                                                        'w-full flex items-center justify-between gap-3 px-4 py-3 rounded-xl transition-all text-left',
                                                        isActive
                                                            ? 'bg-primary text-primary-foreground shadow-lg shadow-primary/20'
                                                            : 'text-muted-foreground hover:bg-white/5 hover:text-foreground',
                                                        !tabVisible && !isActive && 'opacity-50',
                                                    )}
                                                >
                                                    <div className="flex items-center gap-3 overflow-hidden min-w-0">
                                                        <FileText
                                                            className={cn(
                                                                'w-4 h-4 shrink-0',
                                                                isActive
                                                                    ? 'text-primary-foreground'
                                                                    : 'text-primary',
                                                            )}
                                                        />
                                                        <span className="text-sm font-bold truncate tracking-tight">
                                                            {registry.tab_labels[tabKey] ?? tabKey}
                                                        </span>
                                                    </div>
                                                    <div className="flex items-center gap-2 shrink-0">
                                                        {!tabVisible ? (
                                                            <EyeOff
                                                                className={cn(
                                                                    'w-3.5 h-3.5',
                                                                    isActive
                                                                        ? 'text-primary-foreground/60'
                                                                        : 'text-muted-foreground/40',
                                                                )}
                                                            />
                                                        ) : null}
                                                        <span
                                                            className={cn(
                                                                'text-[10px] font-mono',
                                                                isActive
                                                                    ? 'opacity-80'
                                                                    : 'opacity-40',
                                                            )}
                                                        >
                                                            {visibleCount}/{totalCount}
                                                        </span>
                                                    </div>
                                                </button>
                                            );
                                        })}
                                    </div>
                                </ScrollArea>
                            </Card>
                        </aside>

                        {/* Main content — field configuration */}
                        <main className="lg:col-span-9">
                            <Card className="border-white/5 bg-white/5 h-full flex flex-col overflow-hidden shadow-2xl">
                                {/* Content header */}
                                <div className="p-6 border-b border-white/5 bg-white/[0.02] flex items-center justify-between sticky top-0 z-10 backdrop-blur-md">
                                    <div className="flex items-center gap-4">
                                        <div className="w-10 h-10 rounded-2xl bg-primary/10 border border-primary/20 flex items-center justify-center text-primary">
                                            <FileText className="w-5 h-5" />
                                        </div>
                                        <div>
                                            <h2 className="text-lg font-bold tracking-tight text-foreground">
                                                {registry.tab_labels[activeTab] ?? activeTab}
                                            </h2>
                                            <p className="text-xs text-muted-foreground font-medium">
                                                Configure field visibility and requirements
                                            </p>
                                        </div>
                                    </div>

                                    {/* Tab visibility toggle */}
                                    <label className="flex items-center gap-3 px-4 py-2.5 rounded-xl border border-white/5 bg-white/[0.03] cursor-pointer hover:bg-white/[0.06] transition-colors">
                                        <Switch
                                            checked={
                                                activeTab === 'personal'
                                                    ? true
                                                    : isTabVisible(activeTab)
                                            }
                                            disabled={activeTab === 'personal'}
                                            onCheckedChange={(value) =>
                                                setTabVisible(activeTab, value)
                                            }
                                        />
                                        <div>
                                            <p className="text-xs font-semibold text-foreground leading-none">
                                                Tab visible
                                            </p>
                                            <p className="text-[10px] text-muted-foreground/60 mt-0.5">
                                                {activeTab === 'personal'
                                                    ? 'Always shown'
                                                    : 'Toggle tab visibility'}
                                            </p>
                                        </div>
                                    </label>
                                </div>

                                <ScrollArea className="flex-1">
                                    <div className="p-8 space-y-8">
                                        {tablesForActiveTab.length === 0 ? (
                                            <div className="flex flex-col items-center justify-center py-20 text-center">
                                                <div className="w-16 h-16 rounded-3xl bg-white/5 border border-dashed border-white/10 flex items-center justify-center mb-4">
                                                    <ToggleLeft className="w-8 h-8 text-muted-foreground/20" />
                                                </div>
                                                <p className="text-sm text-muted-foreground">
                                                    No fields configured for this tab.
                                                </p>
                                            </div>
                                        ) : null}

                                        {tablesForActiveTab.map((table) => {
                                            const fieldEntries = Object.entries(
                                                registry.fields_by_table[table] ?? {},
                                            );

                                            return (
                                                <div key={table} className="space-y-4">
                                                    {/* Table heading */}
                                                    <div className="flex items-center gap-3">
                                                        <div className="h-5 w-1 rounded-full bg-primary" />
                                                        <h4 className="text-xs font-bold uppercase tracking-widest text-muted-foreground">
                                                            {table}
                                                        </h4>
                                                        <div className="flex-1 h-px bg-white/5" />
                                                    </div>

                                                    {/* Field rows */}
                                                    <div className="rounded-2xl border border-white/5 overflow-hidden divide-y divide-white/5">
                                                        {/* Column headers */}
                                                        <div className="grid grid-cols-12 gap-3 px-5 py-2.5 bg-white/[0.02]">
                                                            <div className="col-span-6 text-[10px] font-bold uppercase tracking-widest text-muted-foreground/40">
                                                                Field
                                                            </div>
                                                            <div className="col-span-3 text-[10px] font-bold uppercase tracking-widest text-muted-foreground/40 text-center">
                                                                Visible
                                                            </div>
                                                            <div className="col-span-3 text-[10px] font-bold uppercase tracking-widest text-muted-foreground/40 text-center">
                                                                Required
                                                            </div>
                                                        </div>

                                                        {fieldEntries.map(([fieldKey, label]) => {
                                                            const field = configuration.fields[
                                                                table
                                                            ]?.[fieldKey] ?? {
                                                                visible: true,
                                                                required: false,
                                                            };

                                                            return (
                                                                <div
                                                                    key={fieldKey}
                                                                    className={cn(
                                                                        'grid grid-cols-12 gap-3 px-5 py-3.5 items-center transition-colors',
                                                                        field.visible
                                                                            ? 'bg-white/[0.01] hover:bg-white/[0.03]'
                                                                            : 'bg-transparent opacity-60 hover:opacity-80',
                                                                    )}
                                                                >
                                                                    {/* Field label + key */}
                                                                    <div className="col-span-6 flex items-center gap-3 min-w-0">
                                                                        <div
                                                                            className={cn(
                                                                                'w-7 h-7 rounded-lg border flex items-center justify-center shrink-0 transition-colors',
                                                                                field.visible
                                                                                    ? 'bg-primary/10 border-primary/20 text-primary'
                                                                                    : 'bg-white/[0.03] border-white/5 text-muted-foreground/30',
                                                                            )}
                                                                        >
                                                                            {field.visible ? (
                                                                                <Eye className="w-3.5 h-3.5" />
                                                                            ) : (
                                                                                <EyeOff className="w-3.5 h-3.5" />
                                                                            )}
                                                                        </div>
                                                                        <div className="min-w-0">
                                                                            <p className="text-sm font-semibold text-foreground/90 truncate">
                                                                                {label}
                                                                            </p>
                                                                            <p className="text-[10px] text-muted-foreground/40 font-mono truncate">
                                                                                {fieldKey}
                                                                            </p>
                                                                        </div>
                                                                    </div>

                                                                    {/* Visible toggle */}
                                                                    <div className="col-span-3 flex justify-center">
                                                                        <Switch
                                                                            checked={field.visible}
                                                                            onCheckedChange={(
                                                                                value,
                                                                            ) =>
                                                                                setFieldConfig(
                                                                                    table,
                                                                                    fieldKey,
                                                                                    {
                                                                                        visible:
                                                                                            value,
                                                                                        // auto-clear required when hiding
                                                                                        required:
                                                                                            value
                                                                                                ? field.required
                                                                                                : false,
                                                                                    },
                                                                                )
                                                                            }
                                                                        />
                                                                    </div>

                                                                    {/* Required toggle */}
                                                                    <div className="col-span-3 flex justify-center">
                                                                        <div className="flex items-center gap-2">
                                                                            {field.required &&
                                                                            field.visible ? (
                                                                                <Lock className="w-3 h-3 text-amber-500/70" />
                                                                            ) : (
                                                                                <Unlock className="w-3 h-3 text-muted-foreground/20" />
                                                                            )}
                                                                            <Switch
                                                                                checked={
                                                                                    field.required
                                                                                }
                                                                                disabled={
                                                                                    !field.visible
                                                                                }
                                                                                onCheckedChange={(
                                                                                    value,
                                                                                ) =>
                                                                                    setFieldConfig(
                                                                                        table,
                                                                                        fieldKey,
                                                                                        {
                                                                                            required:
                                                                                                value,
                                                                                        },
                                                                                    )
                                                                                }
                                                                            />
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            );
                                                        })}
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </ScrollArea>
                            </Card>
                        </main>
                    </div>
                </div>
            </Main>
        </>
    );
}
