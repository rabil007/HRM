import { Head, router } from '@inertiajs/react';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { TemplateForm  } from '../template-form';
import type {DocumentTypeModel} from '../template-form';

export default function CreateTemplate({ documentTypes }: { documentTypes: DocumentTypeModel[] }) {
    return (
        <Main>
            <Head title="Create Onboarding Template" />
            <PageHeader
                kicker="Onboarding"
                title="New Template"
                description="Design the workflow and data requirements for your new employees."
                className="mb-8"
            />

            <TemplateForm documentTypes={documentTypes} onCancel={() => router.visit('/onboarding/templates')} />
        </Main>
    );
}
