import { ErrorPage } from '@/components/errors/error-page';
import { getErrorPageConfig } from '@/lib/errors/error-page-config';

export default function TooManyRequests({
    retry_after_seconds = null,
}: {
    retry_after_seconds?: number | null;
}) {
    return (
        <ErrorPage
            config={getErrorPageConfig(429)}
            retryAfterSeconds={retry_after_seconds}
        />
    );
}
