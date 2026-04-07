import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import LoadingBar from 'react-top-loading-bar';
import type { LoadingBarRef } from 'react-top-loading-bar';

export function NavigationProgress() {
    const ref = useRef<LoadingBarRef>(null);

    useEffect(() => {
        const removeStart = router.on('start', () => {
            ref.current?.continuousStart();
        });
        const removeFinish = router.on('finish', () => {
            ref.current?.complete();
        });

        return () => {
            removeStart();
            removeFinish();
        };
    }, []);

    return (
        <LoadingBar
            color="var(--muted-foreground)"
            ref={ref}
            shadow={true}
            height={2}
        />
    );
}
