import { ErrorPage } from '@/components/errors/error-page';
import { getErrorPageConfig } from '@/lib/errors/error-page-config';

export default function NotFound() {
    return <ErrorPage config={getErrorPageConfig(404)} />;
}
