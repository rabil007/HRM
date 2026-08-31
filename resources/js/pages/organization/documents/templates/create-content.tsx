import { Head } from '@inertiajs/react';
import { TemplateContentFormPage } from '@/features/organization/documents/templates/components/template-content-form-page';
import type {
    CustomTemplate,
    DocumentTypeOption,
    MergeField,
} from '@/features/organization/documents/templates/types';

type Props = {
    template: CustomTemplate | null;
    document_types: DocumentTypeOption[];
    merge_fields: MergeField[];
};

export default function DocumentTemplateCreateContentPage({
    template = null,
    document_types = [],
    merge_fields = [],
}: Props) {
    return (
        <>
            <Head title="New Content Template" />
            <TemplateContentFormPage
                template={template}
                documentTypes={document_types}
                mergeFields={merge_fields}
            />
        </>
    );
}
