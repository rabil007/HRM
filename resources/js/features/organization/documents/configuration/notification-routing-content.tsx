import { Head, router, useForm } from '@inertiajs/react';
import {
    Bell,
    MoreHorizontal,
    Pencil,
    Plus,
    Power,
    Trash2,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import type { ReactElement } from 'react';
import {
    destroy as destroyRule,
    store as storeRule,
    toggle as toggleRule,
    update as updateRule,
} from '@/actions/App/Http/Controllers/Organization/DocumentExpiryNotificationRuleController';
import { ConfirmDeleteDialog } from '@/components/confirm-delete-dialog';
import { EmptyState } from '@/components/empty-state';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
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
    NotificationRoutingRule,
} from '@/features/organization/documents/configuration/notification-routing-types';
import { DocumentsBreadcrumbs } from '@/features/organization/documents/documents-breadcrumbs';
import { documents as documentsOverview } from '@/routes/organization';
import { configuration as documentsConfiguration } from '@/routes/organization/documents';

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
                description="Configure which employee document types send expiry alerts to which recipients. Company and Branch document expiry settings are managed separately under Company Documents."
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
                <div className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-primary/20 bg-primary/5 px-4 py-3 text-sm">
                    <p>
                        Showing rules for document type{' '}
                        <span className="font-semibold">
                            {highlightDocumentTypeTitle}
                        </span>{' '}
                        (including rules that apply to all document types).
                    </p>
                    <Button
                        type="button"
                        variant="outline"
                        className="h-9 rounded-xl"
                        onClick={() =>
                            router.get(
                                '/organization/documents/configuration/notification-routing',
                            )
                        }
                    >
                        Show all rules
                    </Button>
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
                            : 'No notification routing rules'
                    }
                    description={
                        filteredByDocumentType
                            ? 'No routing rules currently cover this document type. Create one to control who receives its employee expiry alerts.'
                            : 'Create a rule to decide who receives employee document expiry alerts for selected document types.'
                    }
                    action={
                        can.update ? (
                            <Button
                                type="button"
                                className="rounded-xl"
                                onClick={openCreate}
                            >
                                Create rule
                            </Button>
                        ) : null
                    }
                />
            ) : (
                <div className="overflow-hidden rounded-2xl border border-border/70 bg-card/40">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Rule</TableHead>
                                <TableHead>Document types</TableHead>
                                <TableHead>TO</TableHead>
                                <TableHead>CC</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="w-[72px]" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rules.map((rule) => {
                                return (
                                    <TableRow key={rule.id}>
                                        <TableCell className="font-medium">
                                            {rule.name}
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex flex-wrap gap-1.5">
                                                {rule.all_document_types ? (
                                                    <Badge variant="secondary">
                                                        All document types
                                                    </Badge>
                                                ) : (
                                                    rule.document_types.map(
                                                        (type) => (
                                                            <Badge
                                                                key={type.id}
                                                                variant="secondary"
                                                                className="font-normal"
                                                            >
                                                                {type.title}
                                                            </Badge>
                                                        ),
                                                    )
                                                )}
                                            </div>
                                        </TableCell>
                                        <TableCell className="max-w-[220px] text-sm text-muted-foreground">
                                            {rule.to_summary}
                                        </TableCell>
                                        <TableCell className="max-w-[220px] text-sm text-muted-foreground">
                                            {rule.cc_summary}
                                        </TableCell>
                                        <TableCell>
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
                                        <TableCell>
                                            {can.update ? (
                                                <DropdownMenu>
                                                    <DropdownMenuTrigger
                                                        asChild
                                                    >
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="icon"
                                                            className="h-9 w-9 rounded-xl"
                                                            aria-label={`Actions for ${rule.name}`}
                                                        >
                                                            <MoreHorizontal className="h-4 w-4" />
                                                        </Button>
                                                    </DropdownMenuTrigger>
                                                    <DropdownMenuContent align="end">
                                                        <DropdownMenuItem
                                                            onClick={() =>
                                                                openEdit(rule)
                                                            }
                                                        >
                                                            <Pencil className="mr-2 h-4 w-4" />
                                                            Edit
                                                        </DropdownMenuItem>
                                                        <DropdownMenuItem
                                                            onClick={() =>
                                                                router.put(
                                                                    toggleRule.url(
                                                                        rule.id,
                                                                    ),
                                                                    {},
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            <Power className="mr-2 h-4 w-4" />
                                                            {rule.enabled
                                                                ? 'Disable'
                                                                : 'Enable'}
                                                        </DropdownMenuItem>
                                                        <DropdownMenuItem
                                                            className="text-destructive focus:text-destructive"
                                                            onClick={() =>
                                                                setDeleteRule(
                                                                    rule,
                                                                )
                                                            }
                                                        >
                                                            <Trash2 className="mr-2 h-4 w-4" />
                                                            Delete
                                                        </DropdownMenuItem>
                                                    </DropdownMenuContent>
                                                </DropdownMenu>
                                            ) : null}
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                </div>
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
