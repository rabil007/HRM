import { Head } from '@inertiajs/react';
import { DocumentsTemplatesContent } from '@/features/organization/documents/templates/documents-templates-content';
import type {
    CustomTemplate,
    SystemTemplate,
    TemplatesPermissions,
} from '@/features/organization/documents/templates/types';

type Props = {
    custom_templates: CustomTemplate[];
    system_templates: SystemTemplate[];
    can: TemplatesPermissions;
};

export default function DocumentsTemplates({
    custom_templates = [],
    system_templates = [],
    can,
}: Props) {
    return (
        <>
            <Head title="Templates" />
            <DocumentsTemplatesContent
                customTemplates={custom_templates}
                systemTemplates={system_templates}
                can={can}
            />
        </>
    );
}
