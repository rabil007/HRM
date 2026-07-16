import {
    Loader2,
    Copy,
    Check,
    MessageCircle,
    Lock,
    Key,
    Calendar,
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
import { buildWhatsAppMessage } from './build-whatsapp-message';
import { fetchDocumentShareLinks } from './fetch-document-share-links';
import type { ShareLinkDocument } from './types';

type ShareLinksModalProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    employee: { id: number; name: string };
    documentIds: number[];
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

export function ShareLinksModal({
    open,
    onOpenChange,
    employee,
    documentIds,
    shareLinksUrl,
    onComplete,
}: ShareLinksModalProps) {
    const [usePassword, setUsePassword] = useState(false);
    const [password, setPassword] = useState('');
    const [expiresAt, setExpiresAt] = useState('');
    const [isGenerating, setIsGenerating] = useState(false);
    const [shareUrl, setShareUrl] = useState<string | null>(null);
    const [documents, setDocuments] = useState<ShareLinkDocument[]>([]);
    const [copied, setCopied] = useState(false);
    const [copiedMessage, setCopiedMessage] = useState(false);

    const handleGenerate = async () => {
        setIsGenerating(true);

        try {
            const response = await fetchDocumentShareLinks(
                shareLinksUrl,
                documentIds,
                usePassword ? password : '',
                expiresAt || undefined,
            );
            setShareUrl(response.share_url);
            setDocuments(response.documents);
            toast.success('Share link generated successfully.');
        } catch (error) {
            toast.error(
                error instanceof Error
                    ? error.message
                    : 'Failed to generate share links.',
            );
        } finally {
            setIsGenerating(false);
        }
    };

    const handleCopyLink = async () => {
        if (!shareUrl) {
            return;
        }

        const successful = await copyToClipboard(shareUrl);

        if (successful) {
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
            toast.success('Link copied to clipboard.');
        } else {
            toast.error('Failed to copy link.');
        }
    };

    const handleCopyAll = async () => {
        if (!shareUrl) {
            return;
        }

        const message = buildWhatsAppMessage(
            employee.name,
            documents,
            shareUrl,
        );
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
        if (!shareUrl) {
            return;
        }

        const message = buildWhatsAppMessage(
            employee.name,
            documents,
            shareUrl,
        );
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
        setShareUrl(null);
        setDocuments([]);
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
                        Generate Share Link
                    </DialogTitle>
                </DialogHeader>

                {shareUrl === null ? (
                    <div className="space-y-4 py-4">
                        <p className="text-sm text-zinc-400">
                            Configure security for {documentIds.length} selected
                            document(s). Recipients open one link to view them.
                        </p>

                        <div className="space-y-4 rounded-2xl border border-zinc-800 bg-zinc-950/40 p-4">
                            <div className="flex items-start gap-3">
                                <Checkbox
                                    id="use-password"
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
                                        htmlFor="use-password"
                                        className="cursor-pointer text-sm font-medium text-zinc-200"
                                    >
                                        Password Protection
                                    </Label>
                                    <span className="block text-xs text-zinc-500">
                                        Require a password to access the
                                        documents.
                                    </span>
                                </div>
                            </div>

                            {usePassword ? (
                                <div className="animate-in space-y-2 pl-7 duration-200 fade-in slide-in-from-top-2">
                                    <div className="flex gap-2">
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
                                </div>
                            ) : null}
                        </div>

                        <div className="space-y-2 rounded-2xl border border-zinc-800 bg-zinc-950/40 p-4">
                            <div className="flex items-center gap-2 text-sm font-medium text-zinc-200">
                                <Calendar className="h-4 w-4 text-zinc-400" />
                                Custom Expiration Date
                            </div>
                            <p className="pl-6 text-xs text-zinc-500">
                                Defaults to 24 hours.
                            </p>
                            <div className="pt-1 pl-6">
                                <Input
                                    type="datetime-local"
                                    value={expiresAt}
                                    onChange={(e) =>
                                        setExpiresAt(e.target.value)
                                    }
                                    className="h-9 rounded-xl border-zinc-800 bg-zinc-900/60 text-sm text-zinc-100 placeholder-zinc-600 focus-visible:ring-zinc-700"
                                />
                            </div>
                        </div>
                    </div>
                ) : (
                    <div className="space-y-4 py-4">
                        <p className="text-sm text-zinc-400">
                            Share this link for {employee.name}.
                        </p>

                        {usePassword ? (
                            <div className="flex items-center gap-2 rounded-2xl border border-amber-500/20 bg-amber-500/10 p-3 text-xs text-amber-200/90">
                                <Lock className="h-4 w-4 shrink-0 text-amber-400" />
                                <div>
                                    <span className="font-semibold">
                                        Password:
                                    </span>{' '}
                                    <code className="rounded border border-amber-500/20 bg-amber-950/50 px-1.5 py-0.5 font-mono text-xs text-white select-all">
                                        {password}
                                    </code>
                                </div>
                            </div>
                        ) : null}

                        <div className="space-y-2 rounded-2xl border border-zinc-800 bg-zinc-950/40 p-3">
                            <ul className="mb-2 max-h-28 space-y-1 overflow-y-auto text-xs text-zinc-300">
                                {documents.map((doc) => (
                                    <li key={doc.id} className="truncate">
                                        • {doc.name}
                                    </li>
                                ))}
                            </ul>
                            <div className="flex items-center gap-2">
                                <Input
                                    readOnly
                                    value={shareUrl}
                                    className="h-8 min-w-0 flex-1 rounded-lg border-zinc-800/80 bg-zinc-950 font-mono text-[11px] text-zinc-400 select-all"
                                />
                                <Button
                                    type="button"
                                    size="icon"
                                    variant="outline"
                                    onClick={handleCopyLink}
                                    className="border-zinc-850 h-8 w-8 shrink-0 rounded-lg bg-zinc-900 text-zinc-400 hover:text-zinc-200"
                                >
                                    {copied ? (
                                        <Check className="h-3.5 w-3.5 text-green-400" />
                                    ) : (
                                        <Copy className="h-3.5 w-3.5" />
                                    )}
                                </Button>
                            </div>
                        </div>
                    </div>
                )}

                <DialogFooter className="flex items-center gap-2 border-t border-zinc-800/60 pt-4 sm:justify-between">
                    {shareUrl === null ? (
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
                                    'Generate Link'
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
