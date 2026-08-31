import { FileStack, FileText } from 'lucide-react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import type { BulkDocumentTypeOption } from '../types';

export function DocumentContextHeader({
    documentTypeKey,
    documentTypeOptions,
    missingCount,
    onDocumentTypeChange,
}: {
    documentTypeKey: string;
    documentTypeOptions: BulkDocumentTypeOption[];
    missingCount: number;
    onDocumentTypeChange: (value: string) => void;
}) {
    const selectedOption = documentTypeOptions.find(
        (opt) => opt.value === documentTypeKey,
    );
    const selectedLabel = selectedOption?.label ?? 'Unknown Document';
    const category = selectedOption?.category ?? 'System Templates';
    const isCompanyTemplate = category === 'Company Templates';

    return (
        <Card className="border-border/60 bg-muted/30">
            <CardContent className="flex items-start justify-between gap-4 p-4">
                <div className="flex items-start gap-3">
                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                        {isCompanyTemplate ? (
                            <FileText className="h-5 w-5" />
                        ) : (
                            <FileStack className="h-5 w-5" />
                        )}
                    </div>
                    <div className="space-y-1">
                        <h3 className="text-sm leading-tight font-semibold text-foreground">
                            {selectedLabel}
                        </h3>
                        <div className="flex flex-wrap items-center gap-2">
                            <Badge
                                variant="secondary"
                                className="text-xs font-normal"
                            >
                                {isCompanyTemplate
                                    ? 'Company Template'
                                    : 'System Template'}
                            </Badge>
                            {selectedOption && (
                                <Badge
                                    variant="outline"
                                    className="border-dashed text-xs font-normal"
                                >
                                    {selectedOption.template_format ===
                                    'pdf_overlay'
                                        ? 'PDF'
                                        : 'Rich Text'}
                                </Badge>
                            )}
                            {missingCount > 0 ? (
                                <Badge className="bg-emerald-500/10 text-xs font-normal text-emerald-700 hover:bg-emerald-500/15 dark:text-emerald-400">
                                    Ready to generate
                                </Badge>
                            ) : (
                                <span className="text-xs text-muted-foreground">
                                    All documents generated
                                </span>
                            )}
                        </div>
                    </div>
                </div>

                <AppSelect
                    value={documentTypeKey}
                    onValueChange={onDocumentTypeChange}
                    className="h-10 w-full rounded-lg sm:w-64"
                >
                    {documentTypeOptions.map((option) => (
                        <AppSelectItem
                            key={option.value}
                            value={option.value}
                            keywords={option.category}
                        >
                            {option.category === 'Company Templates'
                                ? `📄 ${option.label}`
                                : option.label}
                        </AppSelectItem>
                    ))}
                </AppSelect>
            </CardContent>
        </Card>
    );
}
