import { Head } from '@inertiajs/react';
import { TrainingContent } from '@/features/organization/training/training-content';
import type { TrainingsIndexProps } from '@/features/organization/training/types';

export default function TrainingIndex(props: TrainingsIndexProps) {
    return (
        <>
            <Head title="Training" />
            <TrainingContent {...props} />
        </>
    );
}
