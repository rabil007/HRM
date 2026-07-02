import { Head, Link, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
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
            return (
                <pre className="rounded bg-gray-100 p-1 text-xs dark:bg-gray-800">
                    {JSON.stringify(value)}
                </pre>
            );
        }

        return String(value);
    };

    return (
        <div className="mx-auto w-full max-w-7xl p-6">
            <Head title="SQL Playground" />

            <div className="mb-6 flex items-center gap-4">
                <Link
                    href="/mysql"
                    className="rounded bg-gray-200 px-3 py-1.5 font-medium text-gray-800 shadow-sm transition-colors hover:bg-gray-300 dark:bg-gray-700 dark:text-white dark:hover:bg-gray-600"
                    title="Back to Tables"
                >
                    &larr;
                </Link>
                <h1 className="text-3xl font-bold tracking-tight">
                    SQL Playground
                </h1>
            </div>

            <div className="mb-6 rounded border bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <form onSubmit={handleSubmit}>
                    <div className="mb-4">
                        <label
                            htmlFor="query"
                            className="mb-2 block text-sm font-medium"
                        >
                            Write your SELECT query (Read-Only)
                        </label>
                        <textarea
                            id="query"
                            rows={5}
                            className="w-full rounded border bg-gray-50 p-4 font-mono text-sm focus:border-blue-500 focus:ring focus:ring-blue-200 dark:border-gray-700 dark:bg-gray-900"
                            value={data.query}
                            onChange={(e) => setData('query', e.target.value)}
                            placeholder="SELECT * FROM users LIMIT 10;"
                        />
                        {errors.query && (
                            <p className="mt-1 text-sm text-red-600">
                                {errors.query}
                            </p>
                        )}
                    </div>
                    <div className="flex items-center gap-4">
                        <button
                            type="submit"
                            disabled={processing}
                            className="rounded bg-blue-600 px-6 py-2 font-medium text-white shadow-sm transition-colors hover:bg-blue-700 disabled:opacity-50"
                        >
                            {processing ? 'Executing...' : 'Execute Query'}
                        </button>
                    </div>
                </form>
            </div>

            {queryResults && (
                <div className="rounded border bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                    <div className="mb-4 flex items-center justify-between">
                        <h2 className="text-xl font-semibold">
                            Results ({queryResults.data.length} rows)
                        </h2>
                        <button
                            onClick={copyToClipboard}
                            className="rounded border bg-gray-100 px-3 py-1.5 text-sm transition-colors hover:bg-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:hover:bg-gray-600"
                        >
                            {copied ? 'Copied!' : 'Copy JSON'}
                        </button>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="min-w-full border-collapse text-left text-sm whitespace-nowrap">
                            <thead className="border-b-2 border-gray-200 bg-gray-50 tracking-wider uppercase dark:border-gray-700 dark:bg-gray-900/50">
                                <tr>
                                    {queryResults.columns.map((col: string) => (
                                        <th
                                            key={col}
                                            className="px-6 py-3 font-semibold text-gray-700 dark:text-gray-300"
                                        >
                                            {col}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {queryResults.data.map(
                                    (row: any, index: number) => (
                                        <tr
                                            key={index}
                                            className="border-b border-gray-100 transition-colors hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-700/50"
                                        >
                                            {queryResults.columns.map(
                                                (col: string) => (
                                                    <td
                                                        key={col}
                                                        className="px-6 py-3"
                                                    >
                                                        {renderCell(row[col])}
                                                    </td>
                                                ),
                                            )}
                                        </tr>
                                    ),
                                )}
                                {queryResults.data.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={Math.max(
                                                queryResults.columns.length,
                                                1,
                                            )}
                                            className="px-6 py-8 text-center text-gray-500"
                                        >
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
