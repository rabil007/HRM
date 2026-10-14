import { ErrorPage } from '@/components/errors/error-page';
import { getErrorPageConfig } from '@/lib/errors/error-page-config';

export default function Forbidden() {
    return <ErrorPage config={getErrorPageConfig(403)} />;
}
