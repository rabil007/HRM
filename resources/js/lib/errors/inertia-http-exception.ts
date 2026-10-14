type InertiaPagePayload = {
    component?: string;
};

type HttpExceptionResponse = {
    status?: number;
    data?: InertiaPagePayload;
};

export function readHttpExceptionResponse(
    event: unknown,
): HttpExceptionResponse | null {
    if (!event || typeof event !== 'object') {
        return null;
    }

    const detail = (event as { detail?: { response?: HttpExceptionResponse } })
        .detail;
    const direct = event as HttpExceptionResponse;

    if (detail?.response) {
        return detail.response;
    }

    if (typeof direct.status === 'number') {
        return direct;
    }

    return null;
}

export function isInertiaErrorPageResponse(
    response: HttpExceptionResponse | null,
): boolean {
    const component = response?.data?.component;

    return typeof component === 'string' && component.startsWith('errors/');
}
