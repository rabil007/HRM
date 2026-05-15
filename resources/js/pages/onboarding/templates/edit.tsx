import { Head, router } from '@inertiajs/react';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import type { Template, DocumentTypeModel, RankOption } from '../template-form';
import { TemplateForm } from '../template-form';

export default function EditTemplate({ template, documentTypes, ranks }: { template: Template; documentTypes: DocumentTypeModel[]; ranks: RankOption[] }) {
    return (
        <Main>
            <Head title={`Edit ${template.name}`} />
            <PageHeader
                kicker="Onboarding"
                title="Edit Template"
                description={`Modifying ${template.name} configuration.`}
                className="mb-8"
            />

            <TemplateForm 
                template={template} 
                documentTypes={documentTypes}
                ranks={ranks}
                onCancel={() => router.visit('/onboarding/templates')} 
            />
        </Main>
    );
}
