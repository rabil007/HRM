import { http, HttpResponseError } from '@inertiajs/core';

export type QuickCreateResponse = {
    id: number | string;
    label?: string;
    name?: string;
    title?: string;
};

function validationMessageFromResponse(responseData: string): string {
    try {
        const parsed = JSON.parse(responseData) as {
            message?: string;
            errors?: Record<string, string[]>;
        };

        if (parsed.errors) {
            const first = Object.values(parsed.errors)[0];

            if (Array.isArray(first) && typeof first[0] === 'string') {
                return first[0];
            }
        }

        if (typeof parsed.message === 'string') {
            return parsed.message;
        }
    } catch {
        // Fall through to generic message.
    }

    return 'Could not create this option.';
}

export async function postQuickCreate(
    url: string,
    data: Record<string, unknown>,
): Promise<QuickCreateResponse> {
    try {
        const response = await http.getClient().request({
            method: 'post',
            url,
            data: JSON.stringify(data),
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (response.status >= 200 && response.status < 300) {
            return response.data ? (JSON.parse(response.data) as QuickCreateResponse) : { id: '' };
        }

        throw new HttpResponseError(
            `Request failed with status ${response.status}`,
            response,
        );
    } catch (error) {
        if (error instanceof HttpResponseError) {
            throw new Error(validationMessageFromResponse(error.response.data));
        }

        throw error;
    }
}
