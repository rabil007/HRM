import { ShieldCheck, FileText, AlertTriangle, Clock } from 'lucide-react';
import { documents } from '@/routes/organization';
import { library } from '@/routes/organization/documents';
import { DashboardMetricCard } from '../components/dashboard-metric-card';
import { DashboardSection } from '../components/dashboard-section';
import type {
    DocumentCompliance,
    DocumentHealthSlice,
} from '../dashboard-types';

type ComplianceSectionProps = {
    compliance?: DocumentCompliance;
    health?: DocumentHealthSlice[];
};

export function ComplianceSection({ compliance }: ComplianceSectionProps) {
    if (!compliance) {
        return null;
    }

    const validityRate =
        compliance.uploaded_document_validity ?? compliance.compliance_rate;

    return (
        <DashboardSection
            title="Document Compliance & Health"
            description="Employee documents, expiration monitoring, and validity status"
            icon={ShieldCheck}
            actionLabel="Document Registry"
            actionHref={documents.url()}
        >
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <DashboardMetricCard
                    title="Uploaded Document Validity"
                    value={`${validityRate}%`}
                    subtitle={`${compliance.total_documents} total uploaded documents`}
                    icon={ShieldCheck}
                    iconColor={
                        validityRate >= 90
                            ? 'text-emerald-500'
                            : 'text-amber-500'
                    }
                    badgeText={validityRate >= 90 ? 'Healthy' : 'Needs Action'}
                    badgeVariant={validityRate >= 90 ? 'success' : 'warning'}
                    href={documents.url()}
                />

                <DashboardMetricCard
                    title="Expired Documents"
                    value={compliance.expired}
                    subtitle="Requires immediate renewal"
                    icon={AlertTriangle}
                    iconColor="text-rose-500"
                    badgeText={compliance.expired > 0 ? 'Critical' : 'None'}
                    badgeVariant={compliance.expired > 0 ? 'danger' : 'success'}
                    href={library.url({ query: { expiry: 'expired' } })}
                />

                <DashboardMetricCard
                    title="Expiring Within 7 Days"
                    value={compliance.expiring_7}
                    subtitle={`${compliance.expiring_30} expiring within 30 days`}
                    icon={Clock}
                    iconColor="text-amber-500"
                    badgeVariant={
                        compliance.expiring_7 > 0 ? 'warning' : 'default'
                    }
                    href={library.url({ query: { expiry: 'expiring_7' } })}
                />

                <DashboardMetricCard
                    title="Uploaded This Month"
                    value={compliance.uploaded_this_month}
                    subtitle={`Avg ${compliance.avg_per_employee} docs / employee`}
                    icon={FileText}
                    iconColor="text-blue-500"
                    href={documents.url()}
                />
            </div>
        </DashboardSection>
    );
}
