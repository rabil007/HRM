import { Head, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { complete, cancel } from '@/routes/organization/impersonation';

interface Props {
    target: {
        id: number;
        name: string;
        email: string;
    };
    company: {
        id: number;
        name: string;
    };
}

export default function ImpersonationContinuation({ target, company }: Props) {
    const [isSubmitting, setIsSubmitting] = useState(false);

    const handleContinue = () => {
        if (isSubmitting) {
            return;
        }

        setIsSubmitting(true);
        router.post(complete.url());
    };

    const handleCancel = () => {
        router.post(cancel.url());
    };

    useEffect(() => {
        handleContinue();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    return (
        <>
            <Head title={`Login as ${target.name}`} />
            <div className="space-y-6 text-center">
                <div className="flex flex-col items-center justify-center space-y-4 py-4">
                    <Spinner className="h-8 w-8 text-primary" />
                    <div className="space-y-1">
                        <p className="text-sm font-medium text-foreground">
                            Connecting to {target.name}&apos;s account...
                        </p>
                        <p className="text-xs text-muted-foreground">
                            {company.name}
                        </p>
                    </div>
                </div>

                <div className="flex items-center justify-between gap-3 pt-2">
                    <Button
                        type="button"
                        variant="outline"
                        className="w-full"
                        onClick={handleCancel}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        className="w-full"
                        disabled={isSubmitting}
                        onClick={handleContinue}
                    >
                        {isSubmitting ? (
                            <>
                                <Spinner className="mr-2 h-4 w-4" />
                                Logging in...
                            </>
                        ) : (
                            'Continue'
                        )}
                    </Button>
                </div>
            </div>
        </>
    );
}

ImpersonationContinuation.layout = {
    title: 'Connecting account',
    description: 'Password confirmed. Finishing authentication transition.',
};
