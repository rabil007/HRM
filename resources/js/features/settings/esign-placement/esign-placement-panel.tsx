import { useState } from 'react';
import { FabricSignaturePlacementEditor } from '@/features/settings/esign-placement/fabric-signature-placement-editor';
import type {
    EditorRect,
    SignaturePlacementConfig,
} from '@/features/settings/esign-placement/esign-placement-coordinates';
import {
    resetSignaturePlacement,
    saveSignaturePlacement,
} from '@/features/settings/esign-placement/esign-placement-api';
import { esignPreview } from '@/routes/application';
import { toast } from '@/lib/toast';

type Props = {
    documentType: string;
    label: string;
    placement: SignaturePlacementConfig;
    canEdit: boolean;
    onPlacementChange: (placement: SignaturePlacementConfig) => void;
};

export function EsignPlacementPanel({
    documentType,
    label,
    placement,
    canEdit,
    onPlacementChange,
}: Props) {
    const [isSaving, setIsSaving] = useState(false);
    const [isResetting, setIsResetting] = useState(false);

    const previewUrl = esignPreview.url(documentType);

    const handleSave = async (payload: {
        page: number;
        canvas_width: number;
        canvas_height: number;
        signature: EditorRect;
        date: EditorRect;
        signature_ar: EditorRect;
        date_ar: EditorRect;
    }) => {
        setIsSaving(true);

        try {
            const result = await saveSignaturePlacement(documentType, payload);
            toast.success(result.message);
            onPlacementChange(result.placement);
        } catch (error) {
            toast.error(
                error instanceof Error
                    ? error.message
                    : 'Failed to save signature placement.',
            );
        } finally {
            setIsSaving(false);
        }
    };

    const handleReset = async () => {
        setIsResetting(true);

        try {
            const result = await resetSignaturePlacement(documentType);
            toast.success(result.message);
            onPlacementChange(result.placement);
        } catch (error) {
            toast.error(
                error instanceof Error
                    ? error.message
                    : 'Failed to reset signature placement.',
            );
        } finally {
            setIsResetting(false);
        }
    };

    return (
        <div className="space-y-6">
            <div>
                <h2 className="text-base font-bold tracking-tight text-foreground">
                    {label}
                </h2>
                <p className="mt-1 text-sm text-muted-foreground">
                    Position the English and Arabic signature overlays and stamped
                    dates for bulk salary declaration e-signatures.
                </p>
            </div>

            <FabricSignaturePlacementEditor
                key={`${documentType}-${JSON.stringify(placement)}`}
                pdfUrl={previewUrl}
                placement={placement}
                canEdit={canEdit}
                onSave={handleSave}
                onReset={handleReset}
                isSaving={isSaving}
                isResetting={isResetting}
            />
        </div>
    );
}
