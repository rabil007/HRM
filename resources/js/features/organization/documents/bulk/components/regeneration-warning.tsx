import { AlertTriangle } from 'lucide-react';
import { Alert, AlertDescription } from '@/components/ui/alert';

export function RegenerationWarning({ count }: { count: number }) {
    if (count === 0) {
        return null;
    }

    return (
        <Alert className="border-amber-500/25 bg-amber-500/5 text-amber-900 dark:text-amber-200">
            <AlertTriangle className="h-4 w-4 text-amber-600 dark:text-amber-500" />
            <AlertDescription className="text-sm">
                <strong>{count}</strong> selected{' '}
                {count === 1 ? 'employee has' : 'employees have'} an existing
                copy of this document. Generating again will replace{' '}
                {count === 1 ? 'their' : 'each'} current Library copy.
            </AlertDescription>
        </Alert>
    );
}
