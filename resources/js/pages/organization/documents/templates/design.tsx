import { Head, router } from '@inertiajs/react';
import { Main } from '@/components/layout/main';
import { TemplatePdfDesignerDialog } from '@/features/organization/documents/templates/designer/template-pdf-designer-dialog';
import type {
    CustomTemplate,
    MergeField,
    TemplateVersionListItem,
    TemplateVersionSummary,
    VersionChangeSummary,
} from '@/features/organization/documents/templates/types';
import { templates } from '@/routes/organization/documents';

type Props = {
    template: CustomTemplate;
    initial_version: TemplateVersionSummary;
    initial_change_summary: VersionChangeSummary | null;
    all_versions: TemplateVersionListItem[];
    merge_fields: MergeField[];
    can: { create_draft: boolean; update: boolean; preview_employee?: boolean };
};

export default function DocumentTemplateDesignPage({
    template,
    initial_version,
    initial_change_summary = null,
    all_versions = [],
    merge_fields = [],
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
                    can={can}
                />
            </Main>
        </>
    );
}
