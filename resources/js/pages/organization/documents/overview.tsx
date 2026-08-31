import { Head } from '@inertiajs/react';
import { DocumentsOverviewContent } from '@/features/organization/documents/overview/documents-overview-content';
import type {
    OverviewAttentionItem,
    OverviewComplianceType,
    OverviewSections,
} from '@/features/organization/documents/overview/types';
import type {
    DocumentExpirySummary,
    DocumentRequirementSummary,
} from '@/features/organization/documents/shared/types';

type Props = {
    summary: DocumentExpirySummary;
    requirement_summary: DocumentRequirementSummary;
    attention: OverviewAttentionItem[];
    compliance_types?: OverviewComplianceType[];
    sections: OverviewSections;
};

export default function DocumentsOverview({
    summary,
    requirement_summary,
    attention,
    compliance_types = [],
    sections,
}: Props) {
    return (
        <>
            <Head title="Overview" />
            <DocumentsOverviewContent
                summary={summary}
                requirementSummary={requirement_summary}
                attention={attention}
                complianceTypes={compliance_types}
                sections={sections}
            />
        </>
    );
}
