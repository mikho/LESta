/**
 * A thin fetch wrapper for the handful of plain-JSON endpoints this app has
 * that are not Inertia visits (currently just the file manager's own
 * dispatch/poll endpoints, which return a 202 + operation id or a terminal
 * status, never an Inertia-shaped response). Inertia's own <Form>/router
 * already handle CSRF for every page navigation; this is the one place
 * that needs to do it by hand, reading the same XSRF-TOKEN cookie Laravel's
 * own VerifyCsrfToken middleware already sets on every response.
 */
function readCsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

async function apiRequest<T>(
    method: 'POST' | 'PUT' | 'DELETE',
    url: string,
    body?: unknown,
): Promise<T> {
    const response = await fetch(url, {
        method,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': readCsrfToken(),
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });

    const payload = await response.json().catch(() => null);

    if (!response.ok) {
        const message =
            payload && typeof payload === 'object' && 'message' in payload
                ? String((payload as { message: unknown }).message)
                : `Request to ${url} failed with status ${response.status}`;

        throw new Error(message);
    }

    return payload as T;
}

export function apiPost<T>(url: string, body?: unknown): Promise<T> {
    return apiRequest<T>('POST', url, body);
}

export function apiPut<T>(url: string, body?: unknown): Promise<T> {
    return apiRequest<T>('PUT', url, body);
}

export function apiDelete<T>(url: string, body?: unknown): Promise<T> {
    return apiRequest<T>('DELETE', url, body);
}

export async function apiGet<T>(url: string): Promise<T> {
    const response = await fetch(url, {
        headers: { Accept: 'application/json' },
    });

    const payload = await response.json().catch(() => null);

    if (!response.ok) {
        throw new Error(
            `Request to ${url} failed with status ${response.status}`,
        );
    }

    return payload as T;
}
