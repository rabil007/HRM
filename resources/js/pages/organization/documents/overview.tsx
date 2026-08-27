import { Head } from '@inertiajs/react';
import { DocumentsOverviewContent } from '@/features/organization/documents/overview/documents-overview-content';
import type {
    DocumentExpirySummary,
    DocumentRequirementSummary,
} from '@/features/organization/documents/shared/types';

type AttentionItem = {
    key: string;
    label: string;
    count: number;
    query: Record<string, string>;
};

type Props = {
    summary: DocumentExpirySummary;
    requirement_summary: DocumentRequirementSummary;
    attention: AttentionItem[];
    sections: {
        overview: boolean;
        library: boolean;
        generate: boolean;
        requests: boolean;
        templates: boolean;
        activity: boolean;
    };
};

export default function DocumentsOverview({
    summary,
    requirement_summary,
    attention,
    sections,
}: Props) {
    return (
        <>
            <Head title="Overview" />
            <DocumentsOverviewContent
                summary={summary}
                requirementSummary={requirement_summary}
                attention={attention}
                sections={sections}
            />
        </>
    );
}
