import {
    Loader2,
    Copy,
    Check,
    MessageCircle,
    Lock,
    Key,
    Calendar,
    Download,
    Upload,
} from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { toast } from '@/lib/toast';
import { buildFolderWhatsAppMessage } from './build-whatsapp-message';
import { fetchFolderShareLinks } from './fetch-document-share-links';
import type { FolderShareItem } from './types';

type FolderShareLinksModalProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    employeeIds: number[];
    shareLinksUrl: string;
    onComplete: () => void;
};

async function copyToClipboard(text: string): Promise<boolean> {
    if (navigator.clipboard && window.isSecureContext) {
        try {
            await navigator.clipboard.writeText(text);

            return true;
        } catch {
            // Fall through
        }
    }

    try {
        const textArea = document.createElement('textarea');
        textArea.value = text;
        textArea.style.top = '0';
        textArea.style.left = '0';
        textArea.style.position = 'fixed';
        textArea.style.opacity = '0';
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        const successful = document.execCommand('copy');
        document.body.removeChild(textArea);

        return successful;
    } catch {
        return false;
    }
}

export function FolderShareLinksModal({
    open,
    onOpenChange,
    employeeIds,
    shareLinksUrl,
    onComplete,
}: FolderShareLinksModalProps) {
    const [usePassword, setUsePassword] = useState(false);
    const [password, setPassword] = useState('');
    const [expiresAt, setExpiresAt] = useState('');
    const [canDownload, setCanDownload] = useState(true);
    const [canUpload, setCanUpload] = useState(false);
    const [isGenerating, setIsGenerating] = useState(false);
    const [shares, setShares] = useState<FolderShareItem[]>([]);
    const [copiedIndex, setCopiedIndex] = useState<number | null>(null);
    const [copiedMessage, setCopiedMessage] = useState(false);

    const handleGenerate = async () => {
        setIsGenerating(true);

        try {
            const response = await fetchFolderShareLinks(shareLinksUrl, employeeIds, {
                password: usePassword ? password : '',
                expiresAt: expiresAt || undefined,
                canDownload,
                canUpload,
            });
            setShares(response.shares);
            toast.success('Folder share links generated successfully.');
        } catch (error) {
            toast.error(
                error instanceof Error
                    ? error.message
                    : 'Failed to generate folder share links.',
            );
        } finally {
            setIsGenerating(false);
        }
    };

    const handleCopyLink = async (url: string, index: number) => {
        const successful = await copyToClipboard(url);

        if (successful) {
            setCopiedIndex(index);
            setTimeout(() => setCopiedIndex(null), 2000);
            toast.success('Link copied to clipboard.');
        } else {
            toast.error('Failed to copy link.');
        }
    };

    const handleCopyAll = async () => {
        const message = buildFolderWhatsAppMessage(shares);
        const successful = await copyToClipboard(message);

        if (successful) {
            setCopiedMessage(true);
            setTimeout(() => setCopiedMessage(false), 2000);
            toast.success('Message copied to clipboard.');
        } else {
            toast.error('Failed to copy message.');
        }
    };

    const handleWhatsAppShare = () => {
        const message = buildFolderWhatsAppMessage(shares);
        window.open(
            `https://wa.me/?text=${encodeURIComponent(message)}`,
            '_blank',
            'noopener,noreferrer',
        );
        onOpenChange(false);
        onComplete();
    };

    const handleClose = () => {
        setUsePassword(false);
        setPassword('');
        setExpiresAt('');
        setCanDownload(true);
        setCanUpload(false);
        setShares([]);
        onOpenChange(false);
    };

    const generateRandomPassword = () => {
        const chars =
            'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%';
        let pass = '';

        for (let i = 0; i < 8; i++) {
            pass += chars.charAt(Math.floor(Math.random() * chars.length));
        }

        setPassword(pass);
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(openState) => {
                if (!openState) {
                    handleClose();
                } else {
                    onOpenChange(true);
                }
            }}
        >
            <DialogContent className="border-zinc-800 bg-zinc-900/95 text-zinc-100 backdrop-blur-xl sm:max-w-md">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2 text-zinc-100">
                        <Lock className="h-5 w-5 text-zinc-400" />
                        Share Folder
                    </DialogTitle>
                </DialogHeader>

                {shares.length === 0 ? (
                    <div className="space-y-4 py-4">
                        <p className="text-sm text-zinc-400">
                            Configure what recipients can do with{' '}
                            {employeeIds.length} folder
                            {employeeIds.length === 1 ? '' : 's'}. View is always
                            enabled.
                        </p>

                        <div className="space-y-3 rounded-2xl border border-zinc-800 bg-zinc-950/40 p-4">
                            <div className="flex items-start gap-3">
                                <Checkbox
                                    id="folder-can-download"
                                    checked={canDownload}
                                    onCheckedChange={(checked) =>
                                        setCanDownload(checked === true)
                                    }
                                    className="border-zinc-700 data-[state=checked]:bg-zinc-100 data-[state=checked]:text-zinc-950"
                                />
                                <div className="space-y-1">
                                    <Label
                                        htmlFor="folder-can-download"
                                        className="flex cursor-pointer items-center gap-2 text-sm font-medium text-zinc-200"
                                    >
                                        <Download className="h-3.5 w-3.5 text-zinc-400" />
                                        Download
                                    </Label>
                                    <span className="block text-xs text-zinc-500">
                                        Allow downloading files from the folder.
                                    </span>
                                </div>
                            </div>
                            <div className="flex items-start gap-3">
                                <Checkbox
                                    id="folder-can-upload"
                                    checked={canUpload}
                                    onCheckedChange={(checked) =>
                                        setCanUpload(checked === true)
                                    }
                                    className="border-zinc-700 data-[state=checked]:bg-zinc-100 data-[state=checked]:text-zinc-950"
                                />
                                <div className="space-y-1">
                                    <Label
                                        htmlFor="folder-can-upload"
                                        className="flex cursor-pointer items-center gap-2 text-sm font-medium text-zinc-200"
                                    >
                                        <Upload className="h-3.5 w-3.5 text-zinc-400" />
                                        Upload
                                    </Label>
                                    <span className="block text-xs text-zinc-500">
                                        Allow recipients to upload into this
                                        employee folder.
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div className="space-y-4 rounded-2xl border border-zinc-800 bg-zinc-950/40 p-4">
                            <div className="flex items-start gap-3">
                                <Checkbox
                                    id="folder-use-password"
                                    checked={usePassword}
                                    onCheckedChange={(checked) => {
                                        setUsePassword(checked === true);

                                        if (checked === true && !password) {
                                            generateRandomPassword();
                                        }
                                    }}
                                    className="border-zinc-700 data-[state=checked]:bg-zinc-100 data-[state=checked]:text-zinc-950"
                                />
                                <div className="space-y-1">
                                    <Label
                                        htmlFor="folder-use-password"
                                        className="cursor-pointer text-sm font-medium text-zinc-200"
                                    >
                                        Password Protection
                                    </Label>
                                </div>
                            </div>

                            {usePassword ? (
                                <div className="flex gap-2 pl-7">
                                    <Input
                                        type="text"
                                        value={password}
                                        onChange={(e) =>
                                            setPassword(e.target.value)
                                        }
                                        placeholder="Enter password"
                                        className="h-9 rounded-xl border-zinc-800 bg-zinc-900/60 font-mono text-sm text-zinc-100 placeholder-zinc-600 focus-visible:ring-zinc-700"
                                    />
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={generateRandomPassword}
                                        className="hover:bg-zinc-850 h-9 shrink-0 gap-1 rounded-xl border-zinc-800 px-3 text-zinc-400 hover:text-zinc-200"
                                    >
                                        <Key className="h-3.5 w-3.5" />
                                        Auto
                                    </Button>
                                </div>
                            ) : null}
                        </div>

                        <div className="space-y-2 rounded-2xl border border-zinc-800 bg-zinc-950/40 p-4">
                            <div className="flex items-center gap-2 text-sm font-medium text-zinc-200">
                                <Calendar className="h-4 w-4 text-zinc-400" />
                                Expiration
                            </div>
                            <p className="text-xs text-zinc-500">
                                Defaults to 24 hours.
                            </p>
                            <Input
                                type="datetime-local"
                                value={expiresAt}
                                onChange={(e) => setExpiresAt(e.target.value)}
                                className="h-9 rounded-xl border-zinc-800 bg-zinc-900/60 text-sm text-zinc-100 placeholder-zinc-600 focus-visible:ring-zinc-700"
                            />
                        </div>
                    </div>
                ) : (
                    <div className="space-y-4 py-4">
                        {usePassword ? (
                            <div className="flex items-center gap-2 rounded-2xl border border-amber-500/20 bg-amber-500/10 p-3 text-xs text-amber-200/90">
                                <Lock className="h-4 w-4 shrink-0 text-amber-400" />
                                <div>
                                    Password:{' '}
                                    <code className="rounded border border-amber-500/20 bg-amber-950/50 px-1.5 py-0.5 font-mono text-xs text-white select-all">
                                        {password}
                                    </code>
                                </div>
                            </div>
                        ) : null}

                        <div className="max-h-56 space-y-3 overflow-y-auto rounded-2xl border border-zinc-800 bg-zinc-950/40 p-3">
                            {shares.map((share, index) => (
                                <div
                                    key={share.employee_id}
                                    className="space-y-1.5 rounded-xl border border-zinc-800/40 bg-zinc-900/50 p-2"
                                >
                                    <span className="block truncate text-xs font-medium text-zinc-200">
                                        {share.name}
                                    </span>
                                    <div className="flex items-center gap-2">
                                        <Input
                                            readOnly
                                            value={share.share_url}
                                            className="h-8 min-w-0 flex-1 rounded-lg border-zinc-800/80 bg-zinc-950 font-mono text-[11px] text-zinc-400 select-all"
                                        />
                                        <Button
                                            type="button"
                                            size="icon"
                                            variant="outline"
                                            onClick={() =>
                                                handleCopyLink(
                                                    share.share_url,
                                                    index,
                                                )
                                            }
                                            className="border-zinc-850 h-8 w-8 shrink-0 rounded-lg bg-zinc-900 text-zinc-400 hover:text-zinc-200"
                                        >
                                            {copiedIndex === index ? (
                                                <Check className="h-3.5 w-3.5 text-green-400" />
                                            ) : (
                                                <Copy className="h-3.5 w-3.5" />
                                            )}
                                        </Button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                <DialogFooter className="flex items-center gap-2 border-t border-zinc-800/60 pt-4 sm:justify-between">
                    {shares.length === 0 ? (
                        <>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={handleClose}
                                className="hover:bg-zinc-850 rounded-xl border-zinc-800 text-zinc-400 hover:text-zinc-200"
                            >
                                Cancel
                            </Button>
                            <Button
                                type="button"
                                disabled={
                                    isGenerating ||
                                    (usePassword && !password.trim())
                                }
                                onClick={handleGenerate}
                                className="rounded-xl bg-zinc-100 font-medium text-zinc-950 hover:bg-zinc-200"
                            >
                                {isGenerating ? (
                                    <>
                                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                        Generating…
                                    </>
                                ) : (
                                    'Generate Links'
                                )}
                            </Button>
                        </>
                    ) : (
                        <>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={handleCopyAll}
                                className="hover:bg-zinc-850 gap-1.5 rounded-xl border-zinc-800 text-zinc-400 hover:text-zinc-200"
                            >
                                {copiedMessage ? (
                                    <Check className="h-4 w-4 text-green-400" />
                                ) : (
                                    <Copy className="h-4 w-4" />
                                )}
                                Copy Message
                            </Button>
                            <Button
                                type="button"
                                onClick={handleWhatsAppShare}
                                className="gap-1.5 rounded-xl bg-green-600 font-medium text-white hover:bg-green-700"
                            >
                                <MessageCircle className="h-4 w-4 fill-current" />
                                Share on WhatsApp
                            </Button>
                        </>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
