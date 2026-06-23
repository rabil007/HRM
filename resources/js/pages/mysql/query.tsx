import { Head, Link, useForm, usePage } from '@inertiajs/react';
import type { FormEvent} from 'react';
import { useState } from 'react';

export default function Query() {
    const { props } = usePage<any>();
    const { data, setData, post, processing, errors } = useForm({
        query: 'SELECT * FROM users LIMIT 10;',
    });

    const [copied, setCopied] = useState(false);

    const queryResults = props.session?.query_results || props.query_results;

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        post('/mysql/query/execute', {
            preserveScroll: true,
        });
    };

    const copyToClipboard = () => {
        if (queryResults?.data) {
            const text = JSON.stringify(queryResults.data, null, 2);
            navigator.clipboard.writeText(text);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        }
    };

    const renderCell = (value: any) => {
        if (value === null) {
return <span className="text-gray-400 italic">NULL</span>;
}

        if (typeof value === 'object') {
return <pre className="text-xs bg-gray-100 dark:bg-gray-800 p-1 rounded">{JSON.stringify(value)}</pre>;
}

        return String(value);
    };

    return (
        <div className="p-6 max-w-7xl mx-auto w-full">
            <Head title="SQL Playground" />
            
            <div className="mb-6 flex items-center gap-4">
                <Link
                    href="/mysql"
                    className="px-3 py-1.5 bg-gray-200 text-gray-800 rounded shadow-sm hover:bg-gray-300 transition-colors dark:bg-gray-700 dark:text-white dark:hover:bg-gray-600 font-medium"
                    title="Back to Tables"
                >
                    &larr;
                </Link>
                <h1 className="text-3xl font-bold tracking-tight">SQL Playground</h1>
            </div>

            <div className="bg-white border rounded shadow-sm dark:bg-gray-800 dark:border-gray-700 p-4 mb-6">
                <form onSubmit={handleSubmit}>
                    <div className="mb-4">
                        <label htmlFor="query" className="block text-sm font-medium mb-2">Write your SELECT query (Read-Only)</label>
                        <textarea
                            id="query"
                            rows={5}
                            className="w-full p-4 border rounded font-mono text-sm bg-gray-50 dark:bg-gray-900 dark:border-gray-700 focus:ring focus:ring-blue-200 focus:border-blue-500"
                            value={data.query}
                            onChange={(e) => setData('query', e.target.value)}
                            placeholder="SELECT * FROM users LIMIT 10;"
                        />
                        {errors.query && <p className="text-red-600 text-sm mt-1">{errors.query}</p>}
                    </div>
                    <div className="flex items-center gap-4">
                        <button
                            type="submit"
                            disabled={processing}
                            className="px-6 py-2 bg-blue-600 text-white rounded shadow-sm hover:bg-blue-700 transition-colors font-medium disabled:opacity-50"
                        >
                            {processing ? 'Executing...' : 'Execute Query'}
                        </button>
                    </div>
                </form>
            </div>

            {queryResults && (
                <div className="bg-white border rounded shadow-sm dark:bg-gray-800 dark:border-gray-700 p-4">
                    <div className="flex items-center justify-between mb-4">
                        <h2 className="text-xl font-semibold">Results ({queryResults.data.length} rows)</h2>
                        <button
                            onClick={copyToClipboard}
                            className="text-sm px-3 py-1.5 bg-gray-100 border rounded hover:bg-gray-200 dark:bg-gray-700 dark:border-gray-600 dark:hover:bg-gray-600 transition-colors"
                        >
                            {copied ? 'Copied!' : 'Copy JSON'}
                        </button>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="min-w-full text-left text-sm whitespace-nowrap border-collapse">
                            <thead className="uppercase tracking-wider border-b-2 border-gray-200 bg-gray-50 dark:bg-gray-900/50 dark:border-gray-700">
                                <tr>
                                    {queryResults.columns.map((col: string) => (
                                        <th key={col} className="px-6 py-3 font-semibold text-gray-700 dark:text-gray-300">
                                            {col}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {queryResults.data.map((row: any, index: number) => (
                                    <tr key={index} className="border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
                                        {queryResults.columns.map((col: string) => (
                                            <td key={col} className="px-6 py-3">
                                                {renderCell(row[col])}
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                                {queryResults.data.length === 0 && (
                                    <tr>
                                        <td colSpan={Math.max(queryResults.columns.length, 1)} className="px-6 py-8 text-center text-gray-500">
                                            No records found.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}
        </div>
    );
}
