import { useState } from 'react';
import {
    resetSignaturePlacement,
    saveSignaturePlacement,
} from '@/features/settings/esign-placement/esign-placement-api';
import type {
    EditorRect,
    SignaturePlacementConfig,
} from '@/features/settings/esign-placement/esign-placement-coordinates';
import { FabricSignaturePlacementEditor } from '@/features/settings/esign-placement/fabric-signature-placement-editor';
import { toast } from '@/lib/toast';
import { esignPreview } from '@/routes/application';

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
    const samplePdfUrl = esignPreview.url(documentType, {
        query: { guides: '0' },
    });

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

        // #region agent log
        fetch('http://127.0.0.1:7482/ingest/d3b1b2aa-09dd-440b-8cc6-35eab404e1c8',{method:'POST',headers:{'Content-Type':'application/json','X-Debug-Session-Id':'1787de'},body:JSON.stringify({sessionId:'1787de',runId:'post-fix',hypothesisId:'C',location:'esign-placement-panel.tsx:handleSave',message:'frontend save payload rects',data:{signature:payload.signature,date:payload.date,signature_ar:payload.signature_ar,date_ar:payload.date_ar,canvas_width:payload.canvas_width,canvas_height:payload.canvas_height},timestamp:Date.now()})}).catch(()=>{});
        // #endregion

        try {
            const result = await saveSignaturePlacement(documentType, payload);
            // #region agent log
            fetch('http://127.0.0.1:7482/ingest/d3b1b2aa-09dd-440b-8cc6-35eab404e1c8',{method:'POST',headers:{'Content-Type':'application/json','X-Debug-Session-Id':'1787de'},body:JSON.stringify({sessionId:'1787de',runId:'post-fix',hypothesisId:'D',location:'esign-placement-panel.tsx:handleSave:response',message:'save response placement stamps',data:{overlay:result.placement.overlay,stamps:result.placement.stamps,payload_ar_top:payload.signature_ar.top,stamp_ar_y:result.placement.stamps[1]?.y,stamp_en_y:result.placement.stamps[0]?.y},timestamp:Date.now()})}).catch(()=>{});
            // #endregion
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
                samplePdfUrl={samplePdfUrl}
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
