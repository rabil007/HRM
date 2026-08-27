import { Head } from '@inertiajs/react';
import { DocumentsTemplatesContent } from '@/features/organization/documents/templates/documents-templates-content';

type SystemTemplate = {
    key: string;
    label: string;
    supports_esignature: boolean;
};

type Props = {
    system_templates: SystemTemplate[];
    can: {
        document_types: boolean;
        signature_placement: boolean;
    };
};

export default function DocumentsTemplates({ system_templates, can }: Props) {
    return (
        <>
            <Head title="Templates" />
            <DocumentsTemplatesContent
                systemTemplates={system_templates}
                can={can}
            />
        </>
    );
}
