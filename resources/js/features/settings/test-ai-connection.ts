function extractErrorMessage(payload: unknown, fallback: string): string {
    if (!payload || typeof payload !== 'object') {
        return fallback;
    }

    const data = payload as {
        message?: string;
        errors?: Record<string, string[]>;
    };

    if (data.message) {
        return data.message;
    }

    const firstError = Object.values(data.errors ?? {})[0]?.[0];

    return firstError ?? fallback;
}

function csrfToken(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

export type AiConnectionTestResult = {
    message: string;
};

export async function testAiConnection(
    url: string,
): Promise<AiConnectionTestResult> {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        body: JSON.stringify({}),
    });

    const contentType = response.headers.get('Content-Type') ?? '';
    const data = contentType.includes('application/json')
        ? await response.json().catch(() => null)
        : null;

    if (!response.ok) {
        throw new Error(
            extractErrorMessage(
                data,
                'Unable to connect to the selected AI provider.',
            ),
        );
    }

    return {
        message:
            (data as AiConnectionTestResult | null)?.message ??
            'AI provider connection successful.',
    };
}
