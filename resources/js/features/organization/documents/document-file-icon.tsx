import type { LucideIcon } from 'lucide-react';
import {
    Archive,
    FileAudio,
    FileImage,
    FileSpreadsheet,
    FileText,
    FileType,
    FileVideo,
} from 'lucide-react';
import { cn } from '@/lib/utils';

export type DocumentFileKind =
    | 'image'
    | 'pdf'
    | 'word'
    | 'spreadsheet'
    | 'archive'
    | 'video'
    | 'audio'
    | 'default';

function fileExtension(fileName: string | null | undefined): string | null {
    if (!fileName) {
        return null;
    }

    const index = fileName.lastIndexOf('.');

    if (index <= 0 || index === fileName.length - 1) {
        return null;
    }

    return fileName.slice(index + 1).toLowerCase();
}

export function resolveDocumentFileKind(
    mimeType: string | null | undefined,
    fileName?: string | null,
): DocumentFileKind {
    if (mimeType?.startsWith('image/')) {
        return 'image';
    }

    if (mimeType === 'application/pdf') {
        return 'pdf';
    }

    if (
        mimeType?.includes('spreadsheet') ||
        mimeType?.includes('excel') ||
        mimeType === 'text/csv'
    ) {
        return 'spreadsheet';
    }

    if (mimeType?.includes('word') || mimeType === 'application/msword') {
        return 'word';
    }

    if (mimeType?.startsWith('video/')) {
        return 'video';
    }

    if (mimeType?.startsWith('audio/')) {
        return 'audio';
    }

    if (
        mimeType?.includes('zip') ||
        mimeType?.includes('compressed') ||
        mimeType?.includes('archive')
    ) {
        return 'archive';
    }

    const extension = fileExtension(fileName);

    if (!extension) {
        return 'default';
    }

    if (extension === 'pdf') {
        return 'pdf';
    }

    if (['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'bmp', 'heic'].includes(extension)) {
        return 'image';
    }

    if (['doc', 'docx', 'rtf', 'odt'].includes(extension)) {
        return 'word';
    }

    if (['xls', 'xlsx', 'csv', 'ods'].includes(extension)) {
        return 'spreadsheet';
    }

    if (['zip', 'rar', '7z', 'tar', 'gz'].includes(extension)) {
        return 'archive';
    }

    if (['mp4', 'mov', 'avi', 'mkv', 'webm'].includes(extension)) {
        return 'video';
    }

    if (['mp3', 'wav', 'ogg', 'm4a'].includes(extension)) {
        return 'audio';
    }

    return 'default';
}

const FILE_KIND_CONFIG: Record<
    DocumentFileKind,
    { icon: LucideIcon; className: string }
> = {
    image: { icon: FileImage, className: 'text-sky-400/90' },
    pdf: { icon: FileType, className: 'text-red-400/90' },
    word: { icon: FileText, className: 'text-blue-400/90' },
    spreadsheet: { icon: FileSpreadsheet, className: 'text-emerald-400/90' },
    archive: { icon: Archive, className: 'text-amber-400/90' },
    video: { icon: FileVideo, className: 'text-violet-400/90' },
    audio: { icon: FileAudio, className: 'text-pink-400/90' },
    default: { icon: FileText, className: 'text-muted-foreground/80' },
};

export function DocumentFileIcon({
    mimeType,
    fileName,
    className,
}: {
    mimeType: string | null | undefined;
    fileName?: string | null;
    className?: string;
}) {
    const kind = resolveDocumentFileKind(mimeType, fileName);
    const { icon: Icon, className: kindClassName } = FILE_KIND_CONFIG[kind];

    return <Icon className={cn('h-5 w-5 shrink-0', kindClassName, className)} aria-hidden />;
}
