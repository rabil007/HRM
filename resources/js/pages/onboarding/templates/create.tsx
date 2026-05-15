import { Head, router } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { TemplateForm } from '../template-form';
import type { DocumentTypeModel, RankOption } from '../template-form';

export default function CreateTemplate({ documentTypes, ranks }: { documentTypes: DocumentTypeModel[]; ranks: RankOption[] }) {
    return (
        <Main className="p-0">
            <Head title="Create Onboarding Template" />
            <div className="w-full px-4 py-5 md:px-6 md:py-6 xl:px-8">
                <PageHeader
                    kicker="Onboarding"
                    title="New template"
                    description="Name your template, pick which ranks it applies to, then build one or more steps. Each step can ask for different employee, contract, bank, document, or maritime health fields."
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

                <TemplateForm documentTypes={documentTypes} ranks={ranks} onCancel={() => router.visit('/onboarding/templates')} />
            </div>
        </Main>
    );
}
