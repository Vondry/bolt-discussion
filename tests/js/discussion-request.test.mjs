import test from 'node:test';
import assert from 'node:assert/strict';

import { fetchWithCsrfRetry } from '../../assets/discussion-request.mjs';

function response(status, data) {
    return {
        status,
        ok: status >= 200 && status < 300,
        async json() {
            return data;
        },
    };
}

test('retries once with a refreshed token after an explicit CSRF failure', async () => {
    let token = 'stale';
    const tokensSent = [];
    const responses = [
        response(400, { code: 'invalid_csrf' }),
        response(201, { status: 'published' }),
    ];

    const result = await fetchWithCsrfRetry(
        async () => {
            tokensSent.push(token);
            return responses.shift();
        },
        async () => {
            token = 'fresh';
            return true;
        },
    );

    assert.deepEqual(tokensSent, ['stale', 'fresh']);
    assert.equal(result.response.status, 201);
    assert.deepEqual(result.data, { status: 'published' });
});

test('does not retry unrelated bad requests', async () => {
    let requests = 0;
    let refreshes = 0;

    const result = await fetchWithCsrfRetry(
        async () => {
            requests += 1;
            return response(400, { error: 'Validation failed.' });
        },
        async () => {
            refreshes += 1;
            return true;
        },
    );

    assert.equal(requests, 1);
    assert.equal(refreshes, 0);
    assert.equal(result.response.status, 400);
});
