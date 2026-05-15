import { Head, router } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import type { Template, DocumentTypeModel } from '../template-form';
import { TemplateForm } from '../template-form';

export default function EditTemplate({ template, documentTypes }: { template: Template; documentTypes: DocumentTypeModel[] }) {
    return (
        <Main className="p-0">
            <Head title={`Edit ${template.name}`} />
            <div className="w-full px-4 py-5 md:px-6 md:py-6 xl:px-8">
                <PageHeader
                    kicker="Onboarding"
                    title={template.name}
                    description="Adjust steps, fields, and documents new hires will complete. Changes apply to future onboarding using this template."
                    right={
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="gap-2"
                            onClick={() => router.visit('/onboarding/templates')}
                        >
                            <ArrowLeft className="size-4" />
                            All templates
                        </Button>
                    }
                />

                <TemplateForm
                    template={template}
                    documentTypes={documentTypes}
                    onCancel={() => router.visit('/onboarding/templates')}
                />
            </div>
        </Main>
    );
}
