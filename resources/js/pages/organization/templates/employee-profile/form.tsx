import { Head, useForm } from '@inertiajs/react';
import { Eye, EyeOff, FileText, Lock, Search, Settings2, ToggleLeft, Unlock, X } from 'lucide-react';
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
    const [fieldQuery, setFieldQuery] = useState('');

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

    /** Normalised search query */
    const trimmedQuery = fieldQuery.trim().toLowerCase();

    /**
     * For the active tab: only tables/fields that match the query.
     * When query is empty, returns all tables/fields as-is.
     */
    const filteredTablesForActiveTab = useMemo(() => {
        if (!trimmedQuery) {
            return tablesForActiveTab.map((table) => ({
                table,
                fieldEntries: Object.entries(registry.fields_by_table[table] ?? {}),
            }));
        }

        return tablesForActiveTab
            .map((table) => ({
                table,
                fieldEntries: Object.entries(registry.fields_by_table[table] ?? {}).filter(
                    ([fieldKey, label]) =>
                        label.toLowerCase().includes(trimmedQuery) ||
                        fieldKey.toLowerCase().includes(trimmedQuery),
                ),
            }))
            .filter(({ fieldEntries }) => fieldEntries.length > 0);
    }, [trimmedQuery, tablesForActiveTab, registry.fields_by_table]);

    /**
     * Cross-tab search results — other tabs (not the active one) that have matching fields.
     * Only computed when there is a non-empty query.
     */
    const crossTabResults = useMemo(() => {
        if (!trimmedQuery) {
            return [];
        }

        return registry.tab_order
            .filter((tabKey) => tabKey !== activeTab)
            .flatMap((tabKey) => {
                const tables = registry.tab_to_tables[tabKey] ?? [];
                const matched = tables
                    .map((table) => ({
                        table,
                        fieldEntries: Object.entries(
                            registry.fields_by_table[table] ?? {},
                        ).filter(
                            ([fieldKey, label]) =>
                                label.toLowerCase().includes(trimmedQuery) ||
                                fieldKey.toLowerCase().includes(trimmedQuery),
                        ),
                    }))
                    .filter(({ fieldEntries }) => fieldEntries.length > 0);

                if (matched.length === 0) {
                    return [];
                }

                return [{ tabKey, tabLabel: registry.tab_labels[tabKey] ?? tabKey, matched }];
            });
    }, [trimmedQuery, activeTab, registry]);

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
                    <Card className="border-border bg-card dark:border-white/5 dark:bg-white/5">
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
                                        className="rounded-xl border-input bg-background/50 focus-visible:ring-primary/40 h-11 text-base font-semibold transition-all px-4 dark:border-white/10 dark:bg-white/5"
                                    />
                                    {form.errors.name ? (
                                        <p className="text-xs font-medium text-destructive mt-1">
                                            {form.errors.name}
                                        </p>
                                    ) : null}
                                </div>

                                {/* Active toggle */}
                                <div className="flex items-end pb-1">
                                    <label className="flex items-center gap-4 p-3.5 rounded-xl border border-border bg-muted/20 cursor-pointer w-full hover:bg-muted/40 transition-colors dark:border-white/5 dark:bg-white/[0.02] dark:hover:bg-white/[0.04]">
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
                                        className="rounded-xl border-input bg-background/50 focus-visible:ring-primary/40 resize-none min-h-[72px] px-4 py-3 transition-all dark:border-white/10 dark:bg-white/5"
                                        rows={2}
                                    />
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* ── Tab + field configuration ── */}
                    <div className="grid grid-cols-1 lg:grid-cols-12 gap-6 min-h-[560px]">
                        {/* Sidebar — tab navigator */}
                        <aside className="lg:col-span-3 lg:sticky lg:top-6 lg:self-start">
                            <Card className="border-border bg-card flex flex-col overflow-hidden max-h-[calc(100vh-6rem)] dark:border-white/5 dark:bg-white/5">
                                <div className="p-4 border-b border-border bg-muted/20 flex items-center justify-between dark:border-white/5 dark:bg-white/[0.02]">
                                    <h3 className="text-xs font-bold uppercase tracking-widest text-muted-foreground">
                                        Tabs
                                    </h3>
                                    <span className="text-[10px] font-mono opacity-50 border border-border rounded px-1.5 py-0.5 dark:border-white/5">
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
                                                            : 'text-muted-foreground hover:bg-muted hover:text-foreground dark:hover:bg-white/5',
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
                            <Card className="border-border bg-card h-full flex flex-col overflow-hidden shadow-2xl dark:border-white/5 dark:bg-white/5">
                                {/* Content header */}
                                <div className="border-b border-border bg-muted/20 sticky top-0 z-10 backdrop-blur-md dark:border-white/5 dark:bg-white/[0.02]">
                                    {/* Top row: title + tab toggle */}
                                    <div className="px-6 pt-5 pb-4 flex items-center justify-between gap-4">
                                        <div className="flex items-center gap-4 min-w-0">
                                            <div className="w-10 h-10 rounded-2xl bg-primary/10 border border-primary/20 flex items-center justify-center text-primary shrink-0">
                                                <FileText className="w-5 h-5" />
                                            </div>
                                            <div className="min-w-0">
                                                <h2 className="text-lg font-bold tracking-tight text-foreground truncate">
                                                    {registry.tab_labels[activeTab] ?? activeTab}
                                                </h2>
                                                <p className="text-xs text-muted-foreground font-medium">
                                                    Configure field visibility and requirements
                                                </p>
                                            </div>
                                        </div>

                                        {/* Tab visibility toggle */}
                                        <label className="flex items-center gap-3 px-4 py-2.5 rounded-xl border border-border bg-muted/20 cursor-pointer hover:bg-muted/40 transition-colors shrink-0 dark:border-white/5 dark:bg-white/[0.03] dark:hover:bg-white/[0.06]">
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

                                    {/* Search bar row */}
                                    <div className="px-6 pb-4">
                                        <div className="relative group">
                                            <Search className="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground/40 group-focus-within:text-primary transition-colors pointer-events-none" />
                                            <Input
                                                id="field-search"
                                                value={fieldQuery}
                                                onChange={(e) => setFieldQuery(e.target.value)}
                                                placeholder="Search fields by name or key…"
                                                className="rounded-xl border-input bg-background/50 focus-visible:ring-primary/40 h-10 pl-10 pr-10 text-sm transition-all dark:border-white/10 dark:bg-white/5"
                                            />
                                            {fieldQuery ? (
                                                <button
                                                    type="button"
                                                    aria-label="Clear search"
                                                    onClick={() => setFieldQuery('')}
                                                    className="absolute right-3 top-1/2 -translate-y-1/2 w-5 h-5 flex items-center justify-center rounded-md text-muted-foreground/50 hover:text-foreground hover:bg-muted transition-colors dark:hover:bg-white/10"
                                                >
                                                    <X className="w-3.5 h-3.5" />
                                                </button>
                                            ) : null}
                                        </div>
                                        {trimmedQuery ? (
                                            <p className="text-[10px] text-muted-foreground/50 mt-1.5 ml-1">
                                                {filteredTablesForActiveTab.reduce(
                                                    (acc, { fieldEntries }) =>
                                                        acc + fieldEntries.length,
                                                    0,
                                                )}{' '}
                                                match
                                                {filteredTablesForActiveTab.reduce(
                                                    (acc, { fieldEntries }) =>
                                                        acc + fieldEntries.length,
                                                    0,
                                                ) !== 1
                                                    ? 'es'
                                                    : ''}{' '}
                                                in this tab
                                                {crossTabResults.length > 0
                                                    ? `, ${crossTabResults.reduce((acc, { matched }) => acc + matched.reduce((a, { fieldEntries }) => a + fieldEntries.length, 0), 0)} more across other tabs`
                                                    : ''}
                                            </p>
                                        ) : null}
                                    </div>
                                </div>

                                <ScrollArea className="flex-1">
                                    <div className="p-8 space-y-8">
                                        {/* Empty state — no tables at all */}
                                        {tablesForActiveTab.length === 0 ? (
                                            <div className="flex flex-col items-center justify-center py-20 text-center">
                                                <div className="w-16 h-16 rounded-3xl bg-muted/30 border border-dashed border-border flex items-center justify-center mb-4 dark:bg-white/5 dark:border-white/10">
                                                    <ToggleLeft className="w-8 h-8 text-muted-foreground/20" />
                                                </div>
                                                <p className="text-sm text-muted-foreground">
                                                    No fields configured for this tab.
                                                </p>
                                            </div>
                                        ) : null}

                                        {/* Empty state — search returned nothing in active tab */}
                                        {tablesForActiveTab.length > 0 &&
                                        trimmedQuery &&
                                        filteredTablesForActiveTab.length === 0 ? (
                                            <div className="flex flex-col items-center justify-center py-16 text-center">
                                                <div className="w-14 h-14 rounded-2xl bg-muted/30 border border-dashed border-border flex items-center justify-center mb-4 dark:bg-white/5 dark:border-white/10">
                                                    <Search className="w-6 h-6 text-muted-foreground/20" />
                                                </div>
                                                <p className="text-sm font-medium text-foreground/70">
                                                    No fields match &ldquo;{fieldQuery}&rdquo;
                                                </p>
                                                <p className="text-xs text-muted-foreground mt-1">
                                                    Try searching in other tabs below
                                                </p>
                                            </div>
                                        ) : null}

                                        {/* Field tables for the active tab */}
                                        {filteredTablesForActiveTab.map(({ table, fieldEntries }) => (
                                            <FieldTableBlock
                                                key={table}
                                                table={table}
                                                fieldEntries={fieldEntries}
                                                configuration={configuration}
                                                setFieldConfig={setFieldConfig}
                                                searchQuery={trimmedQuery}
                                            />
                                        ))}

                                        {/* Cross-tab results */}
                                        {crossTabResults.length > 0 ? (
                                            <div className="space-y-6 pt-2">
                                                <div className="flex items-center gap-3">
                                                    <div className="flex-1 h-px bg-border dark:bg-white/5" />
                                                    <span className="text-[10px] font-bold uppercase tracking-widest text-muted-foreground/40 px-2">
                                                        Results in other tabs
                                                    </span>
                                                    <div className="flex-1 h-px bg-border dark:bg-white/5" />
                                                </div>

                                                {crossTabResults.map(({ tabKey, tabLabel, matched }) => (
                                                    <div key={tabKey} className="space-y-4">
                                                        {/* Tab badge header */}
                                                        <div className="flex items-center gap-3">
                                                            <button
                                                                type="button"
                                                                onClick={() => {
                                                                    setActiveTab(tabKey);
                                                                    setFieldQuery('');
                                                                }}
                                                                className="flex items-center gap-2 px-3 py-1.5 rounded-lg bg-primary/10 border border-primary/20 text-primary hover:bg-primary/20 transition-colors"
                                                            >
                                                                <FileText className="w-3.5 h-3.5" />
                                                                <span className="text-xs font-bold">
                                                                    {tabLabel}
                                                                </span>
                                                            </button>
                                                            <div className="flex-1 h-px bg-border dark:bg-white/5" />
                                                        </div>

                                                        {matched.map(({ table, fieldEntries }) => (
                                                            <FieldTableBlock
                                                                key={table}
                                                                table={table}
                                                                fieldEntries={fieldEntries}
                                                                configuration={configuration}
                                                                setFieldConfig={setFieldConfig}
                                                                searchQuery={trimmedQuery}
                                                            />
                                                        ))}
                                                    </div>
                                                ))}
                                            </div>
                                        ) : null}
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

/** Highlights a substring match within text. */
function HighlightMatch({ text, query }: { text: string; query: string }): React.ReactElement {
    if (!query) {
        return <>{text}</>;
    }

    const index = text.toLowerCase().indexOf(query.toLowerCase());

    if (index === -1) {
        return <>{text}</>;
    }

    return (
        <>
            {text.slice(0, index)}
            <mark className="bg-primary/20 text-primary rounded px-0.5">{text.slice(index, index + query.length)}</mark>
            {text.slice(index + query.length)}
        </>
    );
}

/** Reusable table block for a group of fields. */
function FieldTableBlock({
    table,
    fieldEntries,
    configuration,
    setFieldConfig,
    searchQuery,
}: {
    table: string;
    fieldEntries: [string, string][];
    configuration: Configuration;
    setFieldConfig: (table: string, fieldKey: string, patch: Partial<FieldConfig>) => void;
    searchQuery: string;
}): React.ReactElement {
    return (
        <div className="space-y-4">
            {/* Table heading */}
            <div className="flex items-center gap-3">
                <div className="h-5 w-1 rounded-full bg-primary" />
                <h4 className="text-xs font-bold uppercase tracking-widest text-muted-foreground">
                    {table}
                </h4>
                <div className="flex-1 h-px bg-border dark:bg-white/5" />
            </div>

            {/* Field rows */}
            <div className="rounded-2xl border border-border overflow-hidden divide-y divide-border dark:border-white/5 dark:divide-white/5">
                {/* Column headers */}
                <div className="grid grid-cols-12 gap-3 px-5 py-2.5 bg-muted/20 dark:bg-white/[0.02]">
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
                    const field = configuration.fields[table]?.[fieldKey] ?? {
                        visible: true,
                        required: false,
                    };

                    return (
                        <div
                            key={fieldKey}
                            className={cn(
                                'grid grid-cols-12 gap-3 px-5 py-3.5 items-center transition-colors',
                                field.visible
                                    ? 'bg-muted/10 hover:bg-muted/30 dark:bg-white/[0.01] dark:hover:bg-white/[0.03]'
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
                                            : 'bg-muted/20 border-border text-muted-foreground/30 dark:bg-white/[0.03] dark:border-white/5',
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
                                        <HighlightMatch text={label} query={searchQuery} />
                                    </p>
                                    <p className="text-[10px] text-muted-foreground/40 font-mono truncate">
                                        <HighlightMatch text={fieldKey} query={searchQuery} />
                                    </p>
                                </div>
                            </div>

                            {/* Visible toggle */}
                            <div className="col-span-3 flex justify-center">
                                <Switch
                                    checked={field.visible}
                                    onCheckedChange={(value) =>
                                        setFieldConfig(table, fieldKey, {
                                            visible: value,
                                            required: value ? field.required : false,
                                        })
                                    }
                                />
                            </div>

                            {/* Required toggle */}
                            <div className="col-span-3 flex justify-center">
                                <div className="flex items-center gap-2">
                                    {field.required && field.visible ? (
                                        <Lock className="w-3 h-3 text-amber-500/70" />
                                    ) : (
                                        <Unlock className="w-3 h-3 text-muted-foreground/20" />
                                    )}
                                    <Switch
                                        checked={field.required}
                                        disabled={!field.visible}
                                        onCheckedChange={(value) =>
                                            setFieldConfig(table, fieldKey, { required: value })
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
}
