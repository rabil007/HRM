import { Head } from '@inertiajs/react';
import { TemplateCreateChoiceContent } from '@/features/organization/documents/templates/components/template-create-choice-content';

export default function DocumentTemplateCreatePage() {
    return (
        <>
            <Head title="Create Document Template" />
            <TemplateCreateChoiceContent />
        </>
    );
}
