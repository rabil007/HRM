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
import { Loader2, Copy, Check, MessageCircle, Lock, Key, Calendar } from 'lucide-react';
import { toast } from '@/lib/toast';
import { fetchDocumentShareLinks } from './fetch-document-share-links';
import { buildWhatsAppMessage } from './build-whatsapp-message';
import type { ShareLinkDocument } from './types';

type ShareLinksModalProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    employee: { id: number; name: string };
    documentIds: number[];
    shareLinksUrl: string;
    onComplete: () => void;
};

// Robust clipboard copy that works in both secure (HTTPS) and non-secure (HTTP) contexts.
async function copyToClipboard(text: string): Promise<boolean> {
    if (navigator.clipboard && window.isSecureContext) {
        try {
            await navigator.clipboard.writeText(text);
            return true;
        } catch {
            // Fall through to fallback
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
    const [generatedLinks, setGeneratedLinks] = useState<ShareLinkDocument[]>([]);
    const [copiedIndex, setCopiedIndex] = useState<number | null>(null);
    const [copiedAll, setCopiedAll] = useState(false);

    const handleGenerate = async () => {
        setIsGenerating(true);
        try {
            const { documents: shareDocuments } = await fetchDocumentShareLinks(
                shareLinksUrl,
                documentIds,
                usePassword ? password : '',
                expiresAt || undefined
            );
            setGeneratedLinks(shareDocuments);
            toast.success('Share links generated successfully.');
        } catch (error) {
            toast.error(error instanceof Error ? error.message : 'Failed to generate share links.');
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
        const message = buildWhatsAppMessage(employee.name, generatedLinks);
        const successful = await copyToClipboard(message);
        if (successful) {
            setCopiedAll(true);
            setTimeout(() => setCopiedAll(false), 2000);
            toast.success('All links and message copied to clipboard.');
        } else {
            toast.error('Failed to copy message.');
        }
    };

    const handleWhatsAppShare = () => {
        const message = buildWhatsAppMessage(employee.name, generatedLinks);
        window.open(
            `https://wa.me/?text=${encodeURIComponent(message)}`,
            '_blank',
            'noopener,noreferrer'
        );
        onOpenChange(false);
        onComplete();
    };

    const handleClose = () => {
        setUsePassword(false);
        setPassword('');
        setExpiresAt('');
        setGeneratedLinks([]);
        onOpenChange(false);
    };

    const generateRandomPassword = () => {
        const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%';
        let pass = '';
        for (let i = 0; i < 8; i++) {
            pass += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        setPassword(pass);
    };

    return (
        <Dialog open={open} onOpenChange={(openState) => {
            if (!openState) {
                handleClose();
            } else {
                onOpenChange(true);
            }
        }}>
            <DialogContent className="sm:max-w-md bg-zinc-900/95 border-zinc-800 text-zinc-100 backdrop-blur-xl">
                <DialogHeader>
                    <DialogTitle className="text-zinc-100 flex items-center gap-2">
                        <Lock className="h-5 w-5 text-zinc-400" />
                        Generate Share Links
                    </DialogTitle>
                </DialogHeader>

                {generatedLinks.length === 0 ? (
                    <div className="space-y-4 py-4">
                        <p className="text-sm text-zinc-400">
                            Configure security and expiration settings for the {documentIds.length} selected document(s).
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
                                    <Label htmlFor="use-password" className="text-sm font-medium text-zinc-200 cursor-pointer">
                                        Password Protection
                                    </Label>
                                    <span className="block text-xs text-zinc-500">
                                        Require a password to access and download the document(s).
                                    </span>
                                </div>
                            </div>

                            {usePassword && (
                                <div className="space-y-2 pl-7 animate-in fade-in slide-in-from-top-2 duration-200">
                                    <div className="flex gap-2">
                                        <Input
                                            type="text"
                                            value={password}
                                            onChange={(e) => setPassword(e.target.value)}
                                            placeholder="Enter password"
                                            className="bg-zinc-900/60 border-zinc-800 text-zinc-100 placeholder-zinc-600 focus-visible:ring-zinc-700 h-9 text-sm rounded-xl font-mono"
                                        />
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={generateRandomPassword}
                                            className="border-zinc-800 text-zinc-400 hover:text-zinc-200 hover:bg-zinc-850 h-9 px-3 rounded-xl gap-1 shrink-0"
                                        >
                                            <Key className="h-3.5 w-3.5" />
                                            Auto
                                        </Button>
                                    </div>
                                </div>
                            )}
                        </div>

                        <div className="space-y-2 rounded-2xl border border-zinc-800 bg-zinc-950/40 p-4">
                            <div className="flex items-center gap-2 text-zinc-200 text-sm font-medium">
                                <Calendar className="h-4 w-4 text-zinc-400" />
                                Custom Expiration Date
                            </div>
                            <p className="text-xs text-zinc-500 pl-6">
                                Specify when the generated links will automatically deactivate. Defaults to 24 hours.
                            </p>
                            <div className="pl-6 pt-1">
                                <Input
                                    type="datetime-local"
                                    value={expiresAt}
                                    onChange={(e) => setExpiresAt(e.target.value)}
                                    className="bg-zinc-900/60 border-zinc-800 text-zinc-100 placeholder-zinc-600 focus-visible:ring-zinc-700 h-9 text-sm rounded-xl"
                                />
                            </div>
                        </div>
                    </div>
                ) : (
                    <div className="space-y-4 py-4">
                        <p className="text-sm text-zinc-400">
                            Here are the generated links for {employee.name}.
                        </p>

                        {usePassword && (
                            <div className="rounded-2xl border border-amber-500/20 bg-amber-500/10 p-3 text-xs text-amber-200/90 flex items-center gap-2">
                                <Lock className="h-4 w-4 shrink-0 text-amber-400" />
                                <div>
                                    <span className="font-semibold">Password Protected:</span> Share the password <code className="bg-amber-950/50 border border-amber-500/20 px-1.5 py-0.5 rounded font-mono text-white text-xs select-all">{password}</code> securely with the recipient.
                                </div>
                            </div>
                        )}

                        <div className="max-h-48 overflow-y-auto space-y-3 rounded-2xl border border-zinc-800 bg-zinc-950/40 p-3">
                            {generatedLinks.map((doc, index) => (
                                <div key={doc.id} className="space-y-1.5 p-2 rounded-xl bg-zinc-900/50 border border-zinc-800/40">
                                    <span className="block text-xs font-medium text-zinc-200 truncate">{doc.name}</span>
                                    <div className="flex items-center gap-2">
                                        <Input
                                            readOnly
                                            value={doc.share_url}
                                            className="h-8 bg-zinc-950 border-zinc-800/80 text-[11px] font-mono text-zinc-400 select-all rounded-lg flex-1 min-w-0"
                                        />
                                        <Button
                                            type="button"
                                            size="icon"
                                            variant="outline"
                                            onClick={() => handleCopyLink(doc.share_url, index)}
                                            className="h-8 w-8 rounded-lg border-zinc-850 bg-zinc-900 text-zinc-400 hover:text-zinc-200 shrink-0"
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

                <DialogFooter className="border-t border-zinc-800/60 pt-4 flex sm:justify-between items-center gap-2">
                    {generatedLinks.length === 0 ? (
                        <>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={handleClose}
                                className="border-zinc-800 text-zinc-400 hover:text-zinc-200 hover:bg-zinc-850 rounded-xl"
                            >
                                Cancel
                            </Button>
                            <Button
                                type="button"
                                disabled={isGenerating || (usePassword && !password.trim())}
                                onClick={handleGenerate}
                                className="bg-zinc-100 hover:bg-zinc-200 text-zinc-950 rounded-xl font-medium"
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
                                className="border-zinc-800 text-zinc-400 hover:text-zinc-200 hover:bg-zinc-850 rounded-xl gap-1.5"
                            >
                                {copiedAll ? (
                                    <Check className="h-4 w-4 text-green-400" />
                                ) : (
                                    <Copy className="h-4 w-4" />
                                )}
                                Copy Message
                            </Button>
                            <Button
                                type="button"
                                onClick={handleWhatsAppShare}
                                className="bg-green-600 hover:bg-green-700 text-white rounded-xl font-medium gap-1.5"
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
