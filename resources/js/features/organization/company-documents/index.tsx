import { Bell } from 'lucide-react';
import { useState } from 'react';
import { index as versionsIndex } from '@/actions/App/Http/Controllers/Organization/CompanyDocumentVersionController';
import { Button } from '@/components/ui/button';
import { DocumentWorkspace } from '@/features/organization/entity-documents';
import {
    bulkStore,
    destroy,
    index as companyDocumentsIndex,
    replace,
    store,
    update,
} from '@/routes/organization/companies/documents';
import { CompanyDocumentExpiryNotificationSheet } from './company-document-expiry-notification-sheet';
import type { CompanyDocumentsPageProps } from './types';

export function CompanyDocumentsContent(props: CompanyDocumentsPageProps) {
    const {
        company,
        documents,
        pagination,
        filters,
        summary,
        document_types,
        can,
        notification_setting,
        company_users,
    } = props;

    const [notificationOpen, setNotificationOpen] = useState(false);

    const routes = {
        pageUrl: companyDocumentsIndex.url(company.id),
        store: store.url(company.id),
        bulkStore: bulkStore.url(company.id),
        update: (documentId: number) => update.url([company.id, documentId]),
        destroy: (documentId: number) => destroy.url([company.id, documentId]),
        replace: (documentId: number) => replace.url([company.id, documentId]),
        versionsIndex: (documentId: number) =>
            versionsIndex.url([company.id, documentId]),
    };

    return (
        <>
            <DocumentWorkspace
                owner={company}
                ownerType="company"
                pageUrl={routes.pageUrl}
                backHref={`/organization/companies/${company.id}`}
                backLabel="Company details"
                title={`${company.name} documents`}
                description="Private compliance files, metadata, expiry tracking, and version history."
                routes={routes}
                permissions={can}
                documents={documents}
                pagination={pagination}
                filters={filters}
                summary={summary}
                documentTypes={document_types}
                emptyTitle="No company documents found."
                headerActionsExtra={
                    can.manage_notifications ? (
                        <Button
                            variant="outline"
                            className="h-11 rounded-xl"
                            onClick={() => setNotificationOpen(true)}
                        >
                            <Bell className="mr-2 h-4 w-4" />
                            Expiry notifications
                        </Button>
                    ) : null
                }
            />

            {can.manage_notifications ? (
                <CompanyDocumentExpiryNotificationSheet
                    company={company}
                    setting={notification_setting}
                    companyUsers={company_users}
                    open={notificationOpen}
                    onOpenChange={setNotificationOpen}
                />
            ) : null}
        </>
    );
}
