import { Head } from '@inertiajs/react';
import { DocumentsTemplatesContent } from '@/features/organization/documents/templates/documents-templates-content';
import type {
    CustomTemplate,
    DocumentTypeOption,
    MergeField,
    SystemTemplate,
    TemplatesPermissions,
} from '@/features/organization/documents/templates/types';

type Props = {
    custom_templates: CustomTemplate[];
    merge_fields: MergeField[];
    document_types: DocumentTypeOption[];
    system_templates: SystemTemplate[];
    can: TemplatesPermissions;
};

export default function DocumentsTemplates({
    custom_templates = [],
    merge_fields = [],
    document_types = [],
    system_templates = [],
    can,
}: Props) {
    return (
        <>
            <Head title="Templates" />
            <DocumentsTemplatesContent
                customTemplates={custom_templates}
                mergeFields={merge_fields}
                documentTypes={document_types}
                systemTemplates={system_templates}
                can={can}
            />
        </>
    );
}
