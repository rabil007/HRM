import { ErrorPage } from '@/components/errors/error-page';
import { getErrorPageConfig } from '@/lib/errors/error-page-config';

export default function ServiceUnavailable() {
    return <ErrorPage config={getErrorPageConfig(503)} />;
}
