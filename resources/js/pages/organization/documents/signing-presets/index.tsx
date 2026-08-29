import { DocumentSigningPresetsContent } from '@/features/organization/documents/signing/document-signing-presets-content';
import type { DocumentSigningPresetsIndexProps } from '@/features/organization/documents/signing/types';

export default function DocumentSigningPresetsIndex(
    props: DocumentSigningPresetsIndexProps,
) {
    return <DocumentSigningPresetsContent {...props} />;
}
