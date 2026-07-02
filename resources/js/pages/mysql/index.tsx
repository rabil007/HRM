import { Head, Link, router } from '@inertiajs/react';
import { useState, useEffect } from 'react';

interface IndexProps {
    tables: string[];
    filters: {
        search?: string;
    };
}

export default function Index({ tables, filters }: IndexProps) {
    const [search, setSearch] = useState(filters.search || '');

    useEffect(() => {
        const timeout = setTimeout(() => {
            router.get(
                '/mysql',
                { search },
                { preserveState: true, replace: true },
            );
        }, 300);

        return () => clearTimeout(timeout);
    }, [search]);

    return (
        <div className="mx-auto w-full max-w-7xl p-6">
            <Head title="Database Tables" />
            <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex items-center gap-4">
                    <h1 className="text-3xl font-bold tracking-tight">
                        Database Tables
                    </h1>
                    <Link
                        href="/mysql/query"
                        className="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition-colors hover:bg-blue-700"
                    >
                        SQL Playground
                    </Link>
                </div>
                <div className="w-full sm:w-72">
                    <input
                        type="text"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder="Search tables..."
                        className="w-full rounded border px-4 py-2 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200 dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                    />
                </div>
            </div>

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
                {tables.map((table) => (
                    <Link
                        key={table}
                        href={`/mysql/${table}`}
                        className="block rounded border bg-white p-4 shadow-sm transition-shadow hover:bg-gray-50 hover:shadow-md dark:border-gray-700 dark:bg-gray-800 dark:hover:bg-gray-700"
                    >
                        <div className="text-lg font-medium break-all text-blue-600 dark:text-blue-400">
                            {table}
                        </div>
                    </Link>
                ))}
                {tables.length === 0 && (
                    <div className="col-span-full rounded border border-dashed bg-gray-50 py-12 text-center text-gray-500 dark:border-gray-700 dark:bg-gray-800/50">
                        No tables found matching your search.
                    </div>
                )}
            </div>
        </div>
    );
}
