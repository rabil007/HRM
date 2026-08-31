import { Head } from '@inertiajs/react';
import { TemplatePdfUploadFormPage } from '@/features/organization/documents/templates/components/template-pdf-upload-form-page';
import type { DocumentTypeOption } from '@/features/organization/documents/templates/types';

type Props = {
    document_types: DocumentTypeOption[];
};

export default function DocumentTemplateCreatePdfPage({
    document_types = [],
}: Props) {
    return (
        <>
            <Head title="Upload PDF Template" />
            <TemplatePdfUploadFormPage documentTypes={document_types} />
        </>
    );
}
