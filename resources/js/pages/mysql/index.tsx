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
                { preserveState: true, replace: true }
            );
        }, 300);

        return () => clearTimeout(timeout);
    }, [search]);

    return (
        <div className="p-6 max-w-7xl mx-auto w-full">
            <Head title="Database Tables" />
            <div className="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div className="flex items-center gap-4">
                    <h1 className="text-3xl font-bold tracking-tight">Database Tables</h1>
                    <Link
                        href="/mysql/query"
                        className="px-4 py-2 bg-blue-600 text-white rounded shadow-sm hover:bg-blue-700 transition-colors font-medium text-sm"
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
                        className="w-full px-4 py-2 border rounded shadow-sm focus:ring focus:ring-blue-200 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-700 dark:text-white"
                    />
                </div>
            </div>
            
            <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                {tables.map((table) => (
                    <Link
                        key={table}
                        href={`/mysql/${table}`}
                        className="block p-4 bg-white border rounded shadow-sm hover:shadow-md transition-shadow hover:bg-gray-50 dark:bg-gray-800 dark:border-gray-700 dark:hover:bg-gray-700"
                    >
                        <div className="font-medium text-lg text-blue-600 dark:text-blue-400 break-all">
                            {table}
                        </div>
                    </Link>
                ))}
                {tables.length === 0 && (
                    <div className="col-span-full py-12 text-center text-gray-500 bg-gray-50 rounded border border-dashed dark:bg-gray-800/50 dark:border-gray-700">
                        No tables found matching your search.
                    </div>
                )}
            </div>
        </div>
    );
}
