import { Head, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Bell, FileStack, Plus, Power, Users } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import type { ReactElement, ReactNode } from 'react';
import {
    destroy as destroyRule,
    store as storeRule,
    toggle as toggleRule,
    update as updateRule,
} from '@/actions/App/Http/Controllers/Organization/DocumentExpiryNotificationRuleController';
import { ConfirmDeleteDialog } from '@/components/confirm-delete-dialog';
import {
    DataTableHead,
    DataTableHeaderRow,
    OrganizationDataTable,
    dataTableActionsCellClass,
    dataTableBodyRowClass,
    dataTableCellClass,
    dataTableCellPrimaryClass,
} from '@/components/data-table';
import { EmptyState } from '@/components/empty-state';
import { Main } from '@/components/layout/main';
import { ListTableCrudActions } from '@/components/list-table-actions';
import {
    MobileRecordCard,
    MobileRecordList,
} from '@/components/mobile-record-list';
import type { MobileRecordOverflowAction } from '@/components/mobile-record-list';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    TableBody,
    TableCell,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { NotificationRoutingFormSheet } from '@/features/organization/documents/configuration/notification-routing-form-sheet';
import {
    emptyNotificationRoutingFormData,
    ruleToFormData,
} from '@/features/organization/documents/configuration/notification-routing-types';
import type {
    NotificationRoutingCompanyUser,
    NotificationRoutingDocumentTypeOption,
    NotificationRoutingFormData,
    NotificationRoutingRecipient,
    NotificationRoutingRule,
} from '@/features/organization/documents/configuration/notification-routing-types';
import { DocumentsBreadcrumbs } from '@/features/organization/documents/documents-breadcrumbs';
import { cn } from '@/lib/utils';
import { documents as documentsOverview } from '@/routes/organization';
import { configuration as documentsConfiguration } from '@/routes/organization/documents';

const ALL_RULES_URL =
    '/organization/documents/configuration/notification-routing';

function RecipientChipList({
    recipients,
    emptyLabel = '—',
}: {
    recipients: NotificationRoutingRecipient[];
    emptyLabel?: string;
}): ReactElement {
    if (recipients.length === 0) {
        return (
            <span className="text-sm text-muted-foreground">{emptyLabel}</span>
        );
    }

    return (
        <div className="flex flex-wrap gap-1.5">
            {recipients.map((recipient, index) => (
                <Badge
                    key={`${recipient.kind}-${recipient.user_id ?? recipient.email ?? index}`}
                    variant={
                        recipient.kind === 'email' ? 'outline' : 'secondary'
                    }
                    className={cn(
                        'max-w-[11rem] font-normal',
                        recipient.kind === 'email' &&
                            'border-amber-500/40 bg-amber-500/5 text-amber-950 dark:text-amber-100',
                    )}
                    title={recipient.email ?? recipient.label}
                >
                    <span className="truncate">{recipient.label}</span>
                </Badge>
            ))}
        </div>
    );
}

function SummaryStat({
    icon,
    label,
    value,
}: {
    icon: ReactNode;
    label: string;
    value: number;
}): ReactElement {
    return (
        <div className="flex items-center gap-3 rounded-2xl border border-border/70 bg-card/50 px-4 py-3">
            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-muted/60 text-muted-foreground">
                {icon}
            </div>
            <div className="min-w-0">
                <p className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                    {label}
                </p>
                <p className="text-xl font-semibold tracking-tight">{value}</p>
            </div>
        </div>
    );
}

function DocumentTypeChips({
    rule,
}: {
    rule: NotificationRoutingRule;
}): ReactElement {
    if (rule.all_document_types) {
        return (
            <Badge variant="secondary" className="font-normal">
                All document types
            </Badge>
        );
    }

    if (rule.document_types.length === 0) {
        return <span className="text-sm text-muted-foreground">—</span>;
    }

    const visible = rule.document_types.slice(0, 3);
    const remaining = rule.document_types.length - visible.length;

    return (
        <div className="flex flex-wrap gap-1.5">
            {visible.map((type) => (
                <Badge
                    key={type.id}
                    variant="secondary"
                    className="max-w-[10rem] font-normal"
                    title={type.title}
                >
                    <span className="truncate">{type.title}</span>
                </Badge>
            ))}
            {remaining > 0 ? (
                <Badge variant="outline" className="font-normal">
                    +{remaining} more
                </Badge>
            ) : null}
        </div>
    );
}

export function NotificationRoutingContent({
    rules,
    documentTypes,
    companyUsers,
    highlightDocumentTypeId = null,
    highlightDocumentTypeTitle = null,
    filteredByDocumentType = false,
    can,
}: {
    rules: NotificationRoutingRule[];
    documentTypes: NotificationRoutingDocumentTypeOption[];
    companyUsers: NotificationRoutingCompanyUser[];
    highlightDocumentTypeId?: number | null;
    highlightDocumentTypeTitle?: string | null;
    filteredByDocumentType?: boolean;
    can: { view: boolean; update: boolean };
}): ReactElement {
    const [sheetOpen, setSheetOpen] = useState(false);
    const [currentRule, setCurrentRule] =
        useState<NotificationRoutingRule | null>(null);
    const [deleteRule, setDeleteRule] =
        useState<NotificationRoutingRule | null>(null);

    const form = useForm<NotificationRoutingFormData>(
        emptyNotificationRoutingFormData(),
    );

    useEffect(() => {
        if (
            filteredByDocumentType &&
            highlightDocumentTypeId &&
            can.update &&
            rules.length === 0
        ) {
            setCurrentRule(null);
            form.setData(ruleToFormData(null, highlightDocumentTypeId));
            setSheetOpen(true);
        }
        // Open once when arriving from Document Type "Manage Notifications"
        // and no matching rules exist for that type.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [filteredByDocumentType, highlightDocumentTypeId]);

    const summary = useMemo(() => {
        const active = rules.filter((rule) => rule.enabled).length;

        return {
            total: rules.length,
            active,
            inactive: rules.length - active,
            allTypes: rules.filter((rule) => rule.all_document_types).length,
        };
    }, [rules]);

    const openCreate = () => {
        setCurrentRule(null);
        form.reset();
        form.clearErrors();
        form.setData(ruleToFormData(null, highlightDocumentTypeId));
        setSheetOpen(true);
    };

    const openEdit = (rule: NotificationRoutingRule) => {
        setCurrentRule(rule);
        form.reset();
        form.clearErrors();
        form.setData(ruleToFormData(rule));
        setSheetOpen(true);
    };

    const submit = () => {
        if (currentRule) {
            form.put(updateRule.url(currentRule.id), {
                preserveScroll: true,
                onSuccess: () => setSheetOpen(false),
            });

            return;
        }

        form.post(storeRule.url(), {
            preserveScroll: true,
            onSuccess: () => setSheetOpen(false),
        });
    };

    const toggleEnabled = (rule: NotificationRoutingRule) => {
        router.put(
            toggleRule.url(rule.id),
            {},
            {
                preserveScroll: true,
            },
        );
    };

    const ruleOverflowActions = (
        rule: NotificationRoutingRule,
    ): MobileRecordOverflowAction[] => {
        if (!can.update) {
            return [];
        }

        return [
            {
                key: 'edit',
                label: 'Edit',
                onSelect: () => openEdit(rule),
            },
            {
                key: 'toggle',
                label: rule.enabled ? 'Disable' : 'Enable',
                onSelect: () => toggleEnabled(rule),
            },
            {
                key: 'delete',
                label: 'Delete',
                destructive: true,
                onSelect: () => setDeleteRule(rule),
            },
        ];
    };

    return (
        <Main>
            <DocumentsBreadcrumbs
                items={[
                    {
                        title: 'Documents',
                        href: documentsOverview.url(),
                    },
                    {
                        title: 'Configuration',
                        href: documentsConfiguration.url(),
                    },
                    { title: 'Notification Routing' },
                ]}
            />

            <PageHeader
                kicker="Documents"
                title="Notification Routing"
                description="Decide which employee document types send expiry alerts to which people. Company and Branch document expiry settings stay under Company Documents."
                right={
                    can.update ? (
                        <Button
                            type="button"
                            className="h-12 rounded-xl px-6"
                            onClick={openCreate}
                        >
                            <Plus className="mr-2 h-4 w-4" />
                            Create rule
                        </Button>
                    ) : null
                }
            />

            {filteredByDocumentType && highlightDocumentTypeTitle ? (
                <div className="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-primary/20 bg-primary/5 px-4 py-3.5">
                    <div className="min-w-0 space-y-1">
                        <p className="text-xs font-semibold tracking-wider text-primary/80 uppercase">
                            Filtered view
                        </p>
                        <p className="text-sm text-foreground">
                            Showing rules for{' '}
                            <span className="font-semibold">
                                {highlightDocumentTypeTitle}
                            </span>
                            , including rules that apply to all document types.
                        </p>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        className="h-10 rounded-xl"
                        onClick={() => router.get(ALL_RULES_URL)}
                    >
                        <ArrowLeft className="mr-2 h-4 w-4" />
                        Show all rules
                    </Button>
                </div>
            ) : (
                <div className="mb-5 grid gap-3 sm:grid-cols-3">
                    <SummaryStat
                        icon={<Bell className="h-4 w-4" />}
                        label="Active rules"
                        value={summary.active}
                    />
                    <SummaryStat
                        icon={<Users className="h-4 w-4" />}
                        label="Inactive rules"
                        value={summary.inactive}
                    />
                    <SummaryStat
                        icon={<FileStack className="h-4 w-4" />}
                        label="All-types rules"
                        value={summary.allTypes}
                    />
                </div>
            )}

            {!filteredByDocumentType ? (
                <div className="mb-5 rounded-2xl border border-border/70 bg-muted/20 px-4 py-3 text-sm text-muted-foreground">
                    <span className="font-medium text-foreground">
                        How routing works:
                    </span>{' '}
                    Document Type → Notification Rule → TO / CC recipients.
                    Internal users only see employees they are allowed to view;
                    external emails are trusted admin-configured addresses.
                </div>
            ) : null}

            {rules.length === 0 ? (
                <EmptyState
                    icon={
                        <Bell className="mx-auto mb-3 h-8 w-8 text-muted-foreground/60" />
                    }
                    title={
                        filteredByDocumentType
                            ? 'No matching notification rules'
                            : 'No notification routing rules yet'
                    }
                    description={
                        filteredByDocumentType
                            ? 'No routing rules currently cover this document type. Create one to control who receives its employee expiry alerts.'
                            : 'Create a rule to map document types to TO and CC recipients for employee document expiry alerts.'
                    }
                    action={
                        can.update ? (
                            <Button
                                type="button"
                                className="rounded-xl"
                                onClick={openCreate}
                            >
                                <Plus className="mr-2 h-4 w-4" />
                                Create rule
                            </Button>
                        ) : null
                    }
                />
            ) : (
                <>
                    <div className="md:hidden">
                        <MobileRecordList labelledBy="notification-routing-mobile-heading">
                            {rules.map((rule) => {
                                const meta = [
                                    rule.document_types_summary,
                                    `TO: ${rule.to_summary}`,
                                    `CC: ${rule.cc_summary}`,
                                ];

                                return (
                                    <MobileRecordCard
                                        key={rule.id}
                                        title={rule.name}
                                        subtitle={
                                            rule.enabled
                                                ? 'Active rule'
                                                : 'Inactive rule'
                                        }
                                        meta={meta}
                                        status={
                                            <Badge
                                                variant={
                                                    rule.enabled
                                                        ? 'success'
                                                        : 'secondary'
                                                }
                                            >
                                                {rule.status_label}
                                            </Badge>
                                        }
                                        overflowActions={ruleOverflowActions(
                                            rule,
                                        )}
                                        primaryAction={
                                            can.update
                                                ? {
                                                      label: 'Edit',
                                                      onClick: () =>
                                                          openEdit(rule),
                                                  }
                                                : undefined
                                        }
                                    />
                                );
                            })}
                        </MobileRecordList>
                    </div>

                    <div className="hidden md:block">
                        <OrganizationDataTable
                            minWidth="min-w-[1080px]"
                            compact
                        >
                            <TableHeader>
                                <DataTableHeaderRow>
                                    <DataTableHead>Rule</DataTableHead>
                                    <DataTableHead>
                                        Document types
                                    </DataTableHead>
                                    <DataTableHead>TO</DataTableHead>
                                    <DataTableHead>CC</DataTableHead>
                                    <DataTableHead>Status</DataTableHead>
                                    <DataTableHead className="text-right">
                                        Actions
                                    </DataTableHead>
                                </DataTableHeaderRow>
                            </TableHeader>
                            <TableBody>
                                {rules.map((rule) => (
                                    <TableRow
                                        key={rule.id}
                                        className={cn(
                                            dataTableBodyRowClass(false),
                                            can.update && 'cursor-pointer',
                                        )}
                                        onClick={() => {
                                            if (can.update) {
                                                openEdit(rule);
                                            }
                                        }}
                                    >
                                        <TableCell
                                            className={dataTableCellPrimaryClass()}
                                        >
                                            <div className="min-w-0 space-y-1">
                                                <p className="truncate font-medium">
                                                    {rule.name}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {rule.to.length} TO ·{' '}
                                                    {rule.cc.length} CC
                                                </p>
                                            </div>
                                        </TableCell>
                                        <TableCell
                                            className={dataTableCellClass()}
                                        >
                                            <DocumentTypeChips rule={rule} />
                                        </TableCell>
                                        <TableCell
                                            className={cn(
                                                dataTableCellClass(),
                                                'max-w-[240px]',
                                            )}
                                        >
                                            <RecipientChipList
                                                recipients={rule.to}
                                            />
                                        </TableCell>
                                        <TableCell
                                            className={cn(
                                                dataTableCellClass(),
                                                'max-w-[240px]',
                                            )}
                                        >
                                            <RecipientChipList
                                                recipients={rule.cc}
                                            />
                                        </TableCell>
                                        <TableCell
                                            className={dataTableCellClass()}
                                        >
                                            <Badge
                                                variant={
                                                    rule.enabled
                                                        ? 'success'
                                                        : 'secondary'
                                                }
                                            >
                                                {rule.status_label}
                                            </Badge>
                                        </TableCell>
                                        <TableCell
                                            className={dataTableActionsCellClass()}
                                            onClick={(event) =>
                                                event.stopPropagation()
                                            }
                                        >
                                            {can.update ? (
                                                <div className="flex items-center justify-end gap-1">
                                                    <ListTableCrudActions
                                                        showView={false}
                                                        onEdit={(event) => {
                                                            event.stopPropagation();
                                                            openEdit(rule);
                                                        }}
                                                        onDelete={(event) => {
                                                            event.stopPropagation();
                                                            setDeleteRule(rule);
                                                        }}
                                                    />
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon"
                                                        className="h-9 w-9 rounded-xl"
                                                        title={
                                                            rule.enabled
                                                                ? 'Disable rule'
                                                                : 'Enable rule'
                                                        }
                                                        aria-label={
                                                            rule.enabled
                                                                ? `Disable ${rule.name}`
                                                                : `Enable ${rule.name}`
                                                        }
                                                        onClick={() =>
                                                            toggleEnabled(rule)
                                                        }
                                                    >
                                                        <Power className="h-4 w-4" />
                                                    </Button>
                                                </div>
                                            ) : (
                                                <span className="text-sm text-muted-foreground">
                                                    —
                                                </span>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </OrganizationDataTable>
                    </div>
                </>
            )}

            <NotificationRoutingFormSheet
                open={sheetOpen}
                onOpenChange={setSheetOpen}
                rule={currentRule}
                form={form}
                documentTypes={documentTypes}
                companyUsers={companyUsers}
                canUpdate={can.update}
                onSubmit={submit}
            />

            <ConfirmDeleteDialog
                open={deleteRule !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setDeleteRule(null);
                    }
                }}
                title="Delete notification rule?"
                description="This removes the routing rule. It will not revive legacy email template recipients."
                confirmText="Delete"
                onConfirm={() => {
                    if (!deleteRule) {
                        return;
                    }

                    router.delete(destroyRule.url(deleteRule.id), {
                        preserveScroll: true,
                        onFinish: () => setDeleteRule(null),
                    });
                }}
            />
        </Main>
    );
}

export default function NotificationRoutingPage({
    rules,
    document_types,
    company_users,
    highlight_document_type_id = null,
    highlight_document_type_title = null,
    filtered_by_document_type = false,
    can,
}: {
    rules: NotificationRoutingRule[];
    document_types: NotificationRoutingDocumentTypeOption[];
    company_users: NotificationRoutingCompanyUser[];
    highlight_document_type_id?: number | null;
    highlight_document_type_title?: string | null;
    filtered_by_document_type?: boolean;
    can: { view: boolean; update: boolean };
}): ReactElement {
    return (
        <>
            <Head title="Notification Routing" />
            <NotificationRoutingContent
                rules={rules}
                documentTypes={document_types}
                companyUsers={company_users}
                highlightDocumentTypeId={highlight_document_type_id}
                highlightDocumentTypeTitle={highlight_document_type_title}
                filteredByDocumentType={filtered_by_document_type}
                can={can}
            />
        </>
    );
}
