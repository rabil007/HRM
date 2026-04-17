import { Input } from '@/components/ui/input';
import { Upload, X, FileText, CheckCircle2, AlertCircle } from 'lucide-react';
import React, { useState } from 'react';

interface DocumentRegistryProps {
    documents: Array<{ type: string; min: number; ask_issue_date?: boolean; ask_expiry_date?: boolean; ask_document_number?: boolean }>;
    docUploads: Record<string, { files: File[]; issue_date?: string; expiry_date?: string; document_number?: string }>;
    onUploadChange: (type: string, data: any) => void;
    documentTypes: Array<{ id: number; title: string; slug: string }>;
}

export function DocumentRegistry({
    documents,
    docUploads,
    onUploadChange,
    documentTypes
}: DocumentRegistryProps) {
    const [search, setSearch] = useState('');
    const [dragActive, setDragActive] = useState<string | null>(null);

    const getDocTitle = (type: string) => {
        return documentTypes.find(dt => String(dt.slug) === String(type) || String(dt.id) === String(type))?.title 
            || type.split('_').join(' ').toUpperCase();
    };

    const handleDrag = (e: React.DragEvent, type: string) => {
        e.preventDefault();
        e.stopPropagation();
        if (e.type === "dragenter" || e.type === "dragover") {
            setDragActive(type);
        } else if (e.type === "dragleave") {
            setDragActive(null);
        }
    };

    const handleDrop = (e: React.DragEvent, type: string) => {
        e.preventDefault();
        e.stopPropagation();
        setDragActive(null);
        if (e.dataTransfer.files && e.dataTransfer.files[0]) {
            const files = Array.from(e.dataTransfer.files);
            onUploadChange(type, { ...(docUploads[type] ?? { files: [] }), files });
        }
    };

    const filteredDocs = documents.filter(d => getDocTitle(d.type).toLowerCase().includes(search.toLowerCase()));

    return (
        <div className="space-y-4">
            <div className="flex items-center justify-between border-b pb-2">
                <div className="text-xs font-bold uppercase tracking-wider text-muted-foreground">Required Documents</div>
                <div className="relative w-64">
                    <Input 
                        placeholder="Search documents..."
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        className="h-8 text-xs pl-3 pr-8 rounded-md bg-muted/30 border-none focus-visible:ring-1 focus-visible:ring-primary"
                    />
                    {search && (
                        <button 
                            type="button"
                            onClick={() => setSearch('')}
                            className="absolute right-2 top-1/2 -translate-y-1/2 text-[10px] text-muted-foreground hover:text-foreground"
                        >
                            <X className="h-3 w-3" />
                        </button>
                    )}
                </div>
            </div>

            <div className="overflow-x-auto">
                <table className="w-full text-left border-collapse">
                    <thead>
                        <tr className="border-b border-border bg-muted/50">
                            <th className="px-4 py-3 text-[10px] font-bold uppercase text-muted-foreground w-1/3">Document Type</th>
                            <th className="px-4 py-3 text-[10px] font-bold uppercase text-muted-foreground">Requirements</th>
                            <th className="px-4 py-3 text-[10px] font-bold uppercase text-muted-foreground">Status & Upload</th>
                            <th className="px-4 py-3 text-[10px] font-bold uppercase text-muted-foreground">Doc Details</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-border">
                        {filteredDocs.map((d) => {
                            const value = docUploads[d.type] ?? { files: [] };
                            const selectedCount = value.files?.length ?? 0;
                            const isComplete = selectedCount >= d.min;
                            const hasMetadata = value.issue_date || value.expiry_date || value.document_number;
                            const isPending = selectedCount > 0 && selectedCount < d.min;

                            return (
                                <tr key={d.type} className="group hover:bg-muted/10 transition-colors">
                                    <td className="px-4 py-4">
                                        <div className="flex items-center gap-3">
                                            <div className={`p-2 rounded-lg ${isComplete ? 'bg-primary/10 text-primary' : isPending ? 'bg-orange-500/10 text-orange-500' : 'bg-muted text-muted-foreground'}`}>
                                                <FileText className="h-4 w-4" />
                                            </div>
                                            <span className="text-sm font-bold text-foreground">{getDocTitle(d.type)}</span>
                                        </div>
                                    </td>
                                    <td className="px-4 py-4">
                                        <span className={`text-[10px] font-bold border px-2 py-0.5 rounded-full inline-flex items-center ${isComplete ? 'bg-primary/5 border-primary/20 text-primary' : 'bg-muted border-border text-muted-foreground'}`}>
                                            Min {d.min}
                                        </span>
                                    </td>
                                    <td className="px-4 py-4">
                                        <div 
                                            onDragEnter={(e) => handleDrag(e, d.type)}
                                            onDragLeave={(e) => handleDrag(e, d.type)}
                                            onDragOver={(e) => handleDrag(e, d.type)}
                                            onDrop={(e) => handleDrop(e, d.type)}
                                            className={`relative group/upload flex flex-col items-center justify-center border-2 border-dashed rounded-xl p-4 transition-all ${
                                                dragActive === d.type ? 'border-primary bg-primary/5 scale-[1.02]' : 
                                                isComplete ? 'border-primary/20 bg-primary/5' : 'border-border hover:border-muted-foreground/50'
                                            }`}
                                        >
                                            <input
                                                type="file"
                                                multiple
                                                className="absolute inset-0 w-full h-full opacity-0 cursor-pointer"
                                                onChange={(e) => {
                                                    const files = Array.from(e.target.files ?? []);
                                                    onUploadChange(d.type, { ...value, files });
                                                }}
                                            />
                                            <Upload className={`h-4 w-4 mb-1 ${isComplete ? 'text-primary' : 'text-muted-foreground'}`} />
                                            <span className="text-[10px] font-bold uppercase text-muted-foreground">
                                                {selectedCount > 0 ? `${selectedCount} Files Selected` : 'Drop or Click to Upload'}
                                            </span>
                                            {selectedCount > 0 && (
                                                <button 
                                                    type="button"
                                                    onClick={(e) => {
                                                        e.stopPropagation();
                                                        onUploadChange(d.type, { ...value, files: [] });
                                                    }}
                                                    className="absolute -top-2 -right-2 h-5 w-5 rounded-full bg-destructive text-destructive-foreground flex items-center justify-center opacity-0 group-hover/upload:opacity-100 transition-opacity shadow-lg"
                                                >
                                                    <X className="h-3 w-3" />
                                                </button>
                                            )}
                                        </div>
                                    </td>
                                    <td className="px-4 py-4">
                                        <div className="flex flex-col gap-2">
                                            {(d.ask_issue_date || d.ask_expiry_date || d.ask_document_number) ? (
                                                <div className="grid grid-cols-1 gap-2">
                                                    {d.ask_document_number && (
                                                        <div className="flex flex-col gap-0.5">
                                                            <label className="text-[9px] font-bold uppercase text-muted-foreground/60">Number</label>
                                                            <Input
                                                                value={value.document_number ?? ''}
                                                                onChange={(e) => onUploadChange(d.type, { ...value, document_number: e.target.value })}
                                                                className="h-7 text-[10px] px-2 bg-muted/20 border-none focus-visible:ring-1 focus-visible:ring-primary"
                                                                placeholder="Doc #"
                                                            />
                                                        </div>
                                                    )}
                                                    <div className="flex items-center gap-2">
                                                        {d.ask_issue_date && (
                                                            <div className="flex-1 flex flex-col gap-0.5">
                                                                <label className="text-[9px] font-bold uppercase text-muted-foreground/60">Issue</label>
                                                                <Input
                                                                    type="date"
                                                                    value={value.issue_date ?? ''}
                                                                    onChange={(e) => onUploadChange(d.type, { ...value, issue_date: e.target.value })}
                                                                    className="h-7 text-[10px] px-2 bg-muted/20 border-none focus-visible:ring-1 focus-visible:ring-primary"
                                                                />
                                                            </div>
                                                        )}
                                                        {d.ask_expiry_date && (
                                                            <div className="flex-1 flex flex-col gap-0.5">
                                                                <label className="text-[9px] font-bold uppercase text-muted-foreground/60">Expiry</label>
                                                                <Input
                                                                    type="date"
                                                                    value={value.expiry_date ?? ''}
                                                                    onChange={(e) => onUploadChange(d.type, { ...value, expiry_date: e.target.value })}
                                                                    className="h-7 text-[10px] px-2 bg-muted/20 border-none focus-visible:ring-1 focus-visible:ring-primary"
                                                                />
                                                            </div>
                                                        )}
                                                    </div>
                                                </div>
                                            ) : (
                                                <div className="flex items-center gap-2 text-muted-foreground/40 italic">
                                                    <AlertCircle className="h-3 w-3" />
                                                    <span className="text-[10px]">No metadata required</span>
                                                </div>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
