import {
    emailMaxAttachmentLabel,
    totalAttachmentBytes,
} from '@/features/organization/documents/email-send/email-utils';
import type { EmailDocumentItem } from '@/features/organization/documents/email-send/types';
import { formatBytes } from '@/lib/utils';


function fileTypeLabel(mimeType: string | null): string {
    if (!mimeType) {
        return 'File';
    }

    if (mimeType === 'application/pdf') {
        return 'PDF';
    }

    if (mimeType.startsWith('image/')) {
        return 'Image';
    }

    if (mimeType.includes('word')) {
        return 'Word';
    }

    if (mimeType.includes('sheet') || mimeType.includes('excel')) {
        return 'Excel';
    }

    return 'Document';
}

export function EmailAttachmentList({ documents }: { documents: EmailDocumentItem[] }) {
    const totalBytes = totalAttachmentBytes(documents);

    return (
        <div className="space-y-2">
            <div className="flex items-center justify-between">
                <p className="text-sm font-medium text-zinc-200">Attachments</p>
                <p className="text-xs text-zinc-400">
                    {formatBytes(totalBytes)} / {emailMaxAttachmentLabel()} max
                </p>
            </div>
            <ul className="max-h-40 space-y-2 overflow-y-auto rounded-lg border border-white/10 bg-zinc-950/40 p-3">
                {documents.map((document) => (
                    <li
                        key={document.id}
                        className="flex items-start gap-2 text-sm text-zinc-300"
                    >
                        <span className="mt-0.5 text-zinc-500">•</span>
                        <span className="min-w-0 flex-1">
                            <span className="font-medium text-zinc-100">
                                {document.document_name}
                            </span>
                            <span className="text-zinc-500">
                                {' '}
                                — {formatBytes(document.size_bytes)} ·{' '}
                                {fileTypeLabel(document.mime_type)}
                            </span>
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}
