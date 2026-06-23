export const INVALID_CSRF_CODE = 'invalid_csrf';

/**
 * Execute a mutation and retry it once when the server explicitly reports a
 * stale CSRF token and a fresh token can be obtained.
 */
export async function fetchWithCsrfRetry(request, refreshToken) {
    for (let attempt = 0; attempt < 2; attempt += 1) {
        const response = await request();
        const data = await readJson(response);

        if (
            attempt === 0
            && response.status === 400
            && data?.code === INVALID_CSRF_CODE
            && await refreshToken()
        ) {
            continue;
        }

        return { response, data };
    }

    throw new Error('Unreachable CSRF retry state.');
}

async function readJson(response) {
    try {
        return await response.json();
    } catch (e) {
        return null;
    }
}
