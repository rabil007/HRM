import { Head, router } from '@inertiajs/react';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import type { Template} from '../template-form';
import { TemplateForm } from '../template-form';

export default function EditTemplate({ template }: { template: Template }) {
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
                onCancel={() => router.visit('/onboarding/templates')} 
            />
        </Main>
    );
}
