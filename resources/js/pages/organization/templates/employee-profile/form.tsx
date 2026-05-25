import { Head, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';

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

    const setFieldConfig = (
        table: string,
        fieldKey: string,
        patch: Partial<FieldConfig>,
    ) => {
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

    return (
        <>
            <Head title={isEdit ? 'Edit profile template' : 'Create profile template'} />
            <Main>
                <PageHeader
                    kicker="Organization"
                    title={isEdit ? 'Edit employee profile template' : 'Create employee profile template'}
                    description="Configure visible tabs and required fields for employee creation and profile."
                />

                <form
                    className="space-y-6"
                    onSubmit={(event) => {
                        event.preventDefault();
                        submit();
                    }}
                >
                    <div className="grid gap-4 md:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label htmlFor="name">Name</Label>
                            <Input
                                id="name"
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                            />
                        </div>
                        <div className="flex items-center gap-3 pt-6">
                            <Switch
                                checked={form.data.is_active}
                                onCheckedChange={(value) =>
                                    form.setData('is_active', value)
                                }
                            />
                            <Label>Active</Label>
                        </div>
                        <div className="space-y-1.5 md:col-span-2">
                            <Label htmlFor="description">Description</Label>
                            <Textarea
                                id="description"
                                value={form.data.description}
                                onChange={(event) =>
                                    form.setData('description', event.target.value)
                                }
                            />
                        </div>
                    </div>

                    <Tabs value={activeTab} onValueChange={setActiveTab}>
                        <TabsList className="flex h-auto flex-wrap justify-start gap-1">
                            {registry.tab_order.map((tabKey) => (
                                <TabsTrigger key={tabKey} value={tabKey}>
                                    {registry.tab_labels[tabKey] ?? tabKey}
                                </TabsTrigger>
                            ))}
                        </TabsList>

                        {registry.tab_order.map((tabKey) => (
                            <TabsContent key={tabKey} value={tabKey} className="space-y-4">
                                <div className="flex items-center justify-between rounded-lg border border-border/60 px-4 py-3">
                                    <div>
                                        <p className="text-sm font-medium">Tab visible</p>
                                        <p className="text-xs text-muted-foreground">
                                            {tabKey === 'personal'
                                                ? 'Personal is always shown on create and profile.'
                                                : 'Hide this tab when the template is applied.'}
                                        </p>
                                    </div>
                                    <Switch
                                        checked={configuration.tabs[tabKey]?.visible ?? true}
                                        disabled={tabKey === 'personal'}
                                        onCheckedChange={(value) =>
                                            setTabVisible(tabKey, value)
                                        }
                                    />
                                </div>

                                {tablesForActiveTab.map((table) => (
                                    <div
                                        key={table}
                                        className="rounded-lg border border-border/60 overflow-hidden"
                                    >
                                        <div className="px-4 py-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground bg-muted/30">
                                            {table}
                                        </div>
                                        <div className="divide-y divide-border/60">
                                            {Object.entries(
                                                registry.fields_by_table[table] ?? {},
                                            ).map(([fieldKey, label]) => {
                                                const field =
                                                    configuration.fields[table]?.[fieldKey] ?? {
                                                        visible: true,
                                                        required: false,
                                                    };

                                                return (
                                                    <div
                                                        key={fieldKey}
                                                        className="grid grid-cols-1 gap-3 px-4 py-3 sm:grid-cols-3 sm:items-center"
                                                    >
                                                        <div className="text-sm font-medium">
                                                            {label}
                                                            <span className="ml-2 text-xs text-muted-foreground">
                                                                {fieldKey}
                                                            </span>
                                                        </div>
                                                        <label className="flex items-center gap-2 text-sm">
                                                            <Switch
                                                                checked={field.visible}
                                                                onCheckedChange={(value) =>
                                                                    setFieldConfig(table, fieldKey, {
                                                                        visible: value,
                                                                    })
                                                                }
                                                            />
                                                            Visible
                                                        </label>
                                                        <label className="flex items-center gap-2 text-sm">
                                                            <Switch
                                                                checked={field.required}
                                                                disabled={!field.visible}
                                                                onCheckedChange={(value) =>
                                                                    setFieldConfig(table, fieldKey, {
                                                                        required: value,
                                                                    })
                                                                }
                                                            />
                                                            Required
                                                        </label>
                                                    </div>
                                                );
                                            })}
                                        </div>
                                    </div>
                                ))}
                            </TabsContent>
                        ))}
                    </Tabs>

                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="outline" asChild>
                            <a href="/organization/templates/employee-profile">Cancel</a>
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? 'Saving…' : 'Save template'}
                        </Button>
                    </div>
                </form>
            </Main>
        </>
    );
}
