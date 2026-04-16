import { Head, router } from '@inertiajs/react';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { TemplateForm } from '../template-form';

export default function CreateTemplate() {
    return (
        <Main>
            <Head title="Create Onboarding Template" />
            <PageHeader
                kicker="Onboarding"
                title="New Template"
                description="Design the workflow and data requirements for your new employees."
                className="mb-8"
            />

            <TemplateForm onCancel={() => router.visit('/onboarding/templates')} />
        </Main>
    );
}
