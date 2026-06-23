import { Head, Link, router } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

interface PaginatedData {
    data: any[];
    links: { url: string | null; label: string; active: boolean }[];
    current_page: number;
    last_page: number;
    total: number;
}

interface ShowProps {
    tableName: string;
    columns: string[];
    data: PaginatedData;
    filters: {
        search?: string;
        sort_by?: string;
        sort_dir?: 'asc' | 'desc';
        column_filters?: Record<string, string>;
    };
}

export default function Show({ tableName, columns, data, filters }: ShowProps) {
    const [search, setSearch] = useState(filters.search || '');
    const [columnFilters, setColumnFilters] = useState<Record<string, string>>(filters.column_filters || {});
    
    const [selectedRow, setSelectedRow] = useState<any | null>(null);
    const [copied, setCopied] = useState(false);

    useEffect(() => {
        const timeout = setTimeout(() => {
            router.get(
                `/mysql/${tableName}`,
                { 
                    search, 
                    sort_by: filters.sort_by, 
                    sort_dir: filters.sort_dir,
                    column_filters: columnFilters
                },
                { preserveState: true, replace: true }
            );
        }, 400);

        return () => clearTimeout(timeout);
    }, [search, columnFilters, filters.sort_by, filters.sort_dir, tableName]);

    const handleSort = (column: string) => {
        let sortDir: 'asc' | 'desc' = 'asc';

        if (filters.sort_by === column) {
            sortDir = filters.sort_dir === 'asc' ? 'desc' : 'asc';
        }

        router.get(
            `/mysql/${tableName}`,
            { search, sort_by: column, sort_dir: sortDir, column_filters: columnFilters },
            { preserveState: true, replace: true }
        );
    };

    const handleColumnFilterChange = (col: string, value: string) => {
        setColumnFilters(prev => ({
            ...prev,
            [col]: value
        }));
    };

    const copyToClipboard = () => {
        const text = JSON.stringify(data.data, null, 2);
        navigator.clipboard.writeText(text);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };

    const renderCell = (value: any, isModal: boolean = false) => {
        if (value === null) {
return <span className="text-gray-400 italic">NULL</span>;
}
        
        if (typeof value === 'string') {
            try {
                const parsed = JSON.parse(value);

                if (typeof parsed === 'object' && parsed !== null) {
                    return (
                        <pre className={`text-xs bg-gray-100 dark:bg-gray-900 p-2 rounded ${!isModal ? 'max-h-32 max-w-sm overflow-auto' : 'overflow-auto whitespace-pre-wrap'}`}>
                            {JSON.stringify(parsed, null, 2)}
                        </pre>
                    );
                }
            } catch {
                // Not JSON, continue to normal string render
            }
        }
        
        const strValue = String(value);

        if (!isModal && strValue.length > 50) {
            return <span title={strValue}>{strValue.substring(0, 50)}...</span>;
        }

        return <span className="whitespace-pre-wrap">{strValue}</span>;
    };

    return (
        <div className="p-6 max-w-[100vw] mx-auto w-full">
            <Head title={`Table: ${tableName}`} />
            
            <div className="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div className="flex items-center gap-4">
                    <Link
                        href="/mysql"
                        className="px-3 py-1.5 bg-gray-200 text-gray-800 rounded shadow-sm hover:bg-gray-300 transition-colors dark:bg-gray-700 dark:text-white dark:hover:bg-gray-600 font-medium"
                        title="Back to Tables"
                    >
                        &larr;
                    </Link>
                    <h1 className="text-3xl font-bold tracking-tight">Table: {tableName}</h1>
                </div>

                <div className="flex items-center gap-3">
                    <input
                        type="text"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder={`Global search...`}
                        className="w-full sm:w-64 px-4 py-2 border rounded shadow-sm focus:ring focus:ring-blue-200 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-700 dark:text-white"
                    />
                    
                    <button
                        onClick={copyToClipboard}
                        className="px-4 py-2 bg-gray-100 border rounded shadow-sm hover:bg-gray-200 dark:bg-gray-800 dark:border-gray-600 dark:hover:bg-gray-700 transition-colors font-medium whitespace-nowrap text-sm"
                    >
                        {copied ? 'Copied!' : 'Copy to Clipboard'}
                    </button>
                    
                    <a
                        href={`/mysql/${tableName}/export?search=${search || ''}&sort_by=${filters.sort_by || ''}&sort_dir=${filters.sort_dir || ''}&${new URLSearchParams(
                            Object.entries(columnFilters).reduce((acc, [k, v]) => ({ ...acc, [`column_filters[${k}]`]: v }), {})
                        ).toString()}`}
                        target="_blank"
                        className="px-4 py-2 bg-blue-600 text-white rounded shadow-sm hover:bg-blue-700 transition-colors font-medium whitespace-nowrap text-sm"
                    >
                        Export CSV
                    </a>
                </div>
            </div>

            <div className="overflow-x-auto bg-white border rounded shadow-sm dark:bg-gray-800 dark:border-gray-700">
                <table className="min-w-full text-left text-sm whitespace-nowrap">
                    <thead className="uppercase tracking-wider border-b-2 border-gray-200 bg-gray-50 dark:bg-gray-900/50 dark:border-gray-700">
                        <tr>
                            <th className="px-4 py-4 w-12 text-center text-gray-500">#</th>
                            {columns.map((col) => (
                                <th key={col} className="px-6 py-4">
                                    <div 
                                        className="font-semibold text-gray-700 dark:text-gray-300 cursor-pointer hover:text-blue-600 dark:hover:text-blue-400 flex items-center gap-1 mb-2 select-none"
                                        onClick={() => handleSort(col)}
                                    >
                                        {col}
                                        {filters.sort_by === col && (
                                            <span className="text-blue-600 dark:text-blue-400">
                                                {filters.sort_dir === 'asc' ? '▲' : '▼'}
                                            </span>
                                        )}
                                    </div>
                                    <input 
                                        type="text" 
                                        placeholder={`Filter ${col}...`}
                                        className="w-full text-xs px-2 py-1 border rounded bg-white dark:bg-gray-900 dark:border-gray-700 font-normal normal-case"
                                        value={columnFilters[col] || ''}
                                        onChange={(e) => handleColumnFilterChange(col, e.target.value)}
                                    />
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {data.data.map((row, index) => (
                            <tr key={index} className="border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
                                <td className="px-4 py-4 text-center">
                                    <button 
                                        onClick={() => setSelectedRow(row)}
                                        className="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 p-1"
                                        title="View Record Details"
                                    >
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                                    </button>
                                </td>
                                {columns.map((col) => (
                                    <td key={col} className="px-6 py-4 align-top">
                                        {renderCell(row[col])}
                                    </td>
                                ))}
                            </tr>
                        ))}
                        {data.data.length === 0 && (
                            <tr>
                                <td colSpan={columns.length + 1} className="px-6 py-8 text-center text-gray-500">
                                    No records found matching your search.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>

            <div className="mt-6 flex flex-col sm:flex-row items-center justify-between gap-4">
                <div className="text-sm text-gray-600 dark:text-gray-400">
                    Showing <span className="font-semibold text-gray-900 dark:text-white">{data.data.length}</span> of <span className="font-semibold text-gray-900 dark:text-white">{data.total}</span> results
                </div>
                <div className="flex flex-wrap gap-1">
                    {data.links.map((link, index) => (
                        <Link
                            key={index}
                            href={link.url || '#'}
                            className={`px-3 py-1.5 border rounded text-sm font-medium transition-colors ${
                                link.active 
                                    ? 'bg-blue-600 text-white border-blue-600 shadow-sm' 
                                    : 'bg-white text-gray-700 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:border-gray-700 dark:hover:bg-gray-700'
                            } ${!link.url ? 'opacity-50 cursor-not-allowed' : ''}`}
                            dangerouslySetInnerHTML={{ __html: link.label }}
                            preserveScroll
                            disabled={!link.url}
                        />
                    ))}
                </div>
            </div>

            <Dialog open={!!selectedRow} onOpenChange={(open) => !open && setSelectedRow(null)}>
                <DialogContent className="max-w-3xl max-h-[85vh] overflow-hidden flex flex-col">
                    <DialogHeader>
                        <DialogTitle>Record Details</DialogTitle>
                    </DialogHeader>
                    
                    {selectedRow && (
                        <div className="overflow-y-auto mt-4 pr-2">
                            <table className="w-full text-sm text-left">
                                <tbody>
                                    {columns.map((col) => (
                                        <tr key={col} className="border-b dark:border-gray-800 last:border-0">
                                            <th className="py-3 pr-4 font-semibold w-1/4 align-top text-gray-700 dark:text-gray-300">
                                                {col}
                                            </th>
                                            <td className="py-3 align-top font-mono text-sm break-all">
                                                {renderCell(selectedRow[col], true)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </DialogContent>
            </Dialog>
        </div>
    );
}
