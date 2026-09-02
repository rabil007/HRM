import { Head, router } from '@inertiajs/react';
import { Main } from '@/components/layout/main';
import type { SigningPresetFormOptions } from '@/features/organization/documents/signing/types';
import { TemplatePdfDesignerDialog } from '@/features/organization/documents/templates/designer/template-pdf-designer-dialog';
import type {
    CustomTemplate,
    DesignerSigningPreset,
    DesignerWorkflowPreset,
    MergeField,
    TemplateReadiness,
    TemplateVersionListItem,
    TemplateVersionSummary,
    VersionChangeSummary,
} from '@/features/organization/documents/templates/types';
import type { WorkflowPresetFormOptions } from '@/features/organization/documents/workflow/types';
import { templates } from '@/routes/organization/documents';

type Props = {
    template: CustomTemplate;
    initial_version: TemplateVersionSummary;
    initial_change_summary: VersionChangeSummary | null;
    all_versions: TemplateVersionListItem[];
    merge_fields: MergeField[];
    workflow_presets: DesignerWorkflowPreset[];
    signing_presets: DesignerSigningPreset[];
    workflow_form_options: WorkflowPresetFormOptions | null;
    signing_form_options: SigningPresetFormOptions | null;
    readiness: TemplateReadiness | null;
    can: {
        create_draft: boolean;
        update: boolean;
        preview_employee?: boolean;
        create_workflow_presets?: boolean;
        create_signing_presets?: boolean;
    };
};

export default function DocumentTemplateDesignPage({
    template,
    initial_version,
    initial_change_summary = null,
    all_versions = [],
    merge_fields = [],
    workflow_presets = [],
    signing_presets = [],
    workflow_form_options = null,
    signing_form_options = null,
    readiness = null,
    can,
}: Props) {
    return (
        <>
            <Head title={`Design ${template.name}`} />
            <Main fixed className="!p-0 sm:!p-0">
                <TemplatePdfDesignerDialog
                    mode="page"
                    open
                    onOpenChange={(nextOpen) => {
                        if (!nextOpen) {
                            router.visit(templates.url());
                        }
                    }}
                    template={template}
                    initialVersion={initial_version}
                    initialChangeSummary={initial_change_summary}
                    allVersions={all_versions}
                    mergeFields={merge_fields}
                    workflowPresets={workflow_presets}
                    signingPresets={signing_presets}
                    workflowFormOptions={workflow_form_options}
                    signingFormOptions={signing_form_options}
                    readiness={readiness}
                    can={can}
                />
            </Main>
        </>
    );
}
