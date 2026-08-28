import { DocumentWorkflowPresetsContent } from '@/features/organization/documents/workflow/document-workflow-presets-content';
import type { DocumentWorkflowPresetsIndexProps } from '@/features/organization/documents/workflow/types';

export default function DocumentWorkflowPresetsIndex(
    props: DocumentWorkflowPresetsIndexProps,
) {
    return <DocumentWorkflowPresetsContent {...props} />;
}
