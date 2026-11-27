import { readFile } from 'node:fs/promises';
import { strict as assert } from 'node:assert';
import { pathToFileURL } from 'node:url';
import path from 'node:path';

const root = path.resolve(new URL('../..', import.meta.url).pathname);
const source = await readFile(path.join(root, 'js/api-client.js'), 'utf8');
globalThis.window = { location: { origin: 'https://mrneon.synthetic.test' } };
globalThis.document = { baseURI: 'https://mrneon.synthetic.test/index.php' };
const moduleUrl = `data:text/javascript;base64,${Buffer.from(source, 'utf8').toString('base64')}`;
const client = await import(moduleUrl);
let calls = [];
const csrfA = 'a'.repeat(64);
const csrfB = 'b'.repeat(64);
const jsonResponse = (payload, status = 200, contentType = 'application/json; charset=utf-8') =>
  new Response(JSON.stringify(payload), { status, headers: { 'Content-Type': contentType, 'X-Request-ID': payload?.meta?.requestId || payload?.requestId || 'synthetic-http-id' } });

globalThis.fetch = async (input, init) => {
  const url = new URL(input);
  const action = url.searchParams.get('action');
  calls.push({ action, init });
  if (action === 'getSession') return jsonResponse({ data: { authenticated: false, csrfToken: csrfA }, meta: { requestId: 'synthetic-get-session-id' } });
  if (action === 'login') {
    assert.equal(init.credentials, 'same-origin', 'credentials must be same-origin');
    assert.equal(new Headers(init.headers).get('X-CSRF-Token'), csrfA, 'mutation must carry session-bound CSRF');
    assert.deepEqual(JSON.parse(init.body), { username: 'synthetic_user', password: 'synthetic-only' });
    return jsonResponse({ data: { authenticated: true, principalType: 'user', csrfToken: csrfB }, meta: { requestId: 'synthetic-login-id' } });
  }
  return jsonResponse({ type: 'urn:mr-neon:problem:forbidden', title: 'Forbidden', status: 403, detail: 'Synthetic rejection', code: 'FORBIDDEN', requestId: 'synthetic-forbidden-id' }, 403, 'application/problem+json; charset=utf-8');
};

client.configureApiEndpoint('api.php');
assert.throws(() => client.configureApiEndpoint('https://cross-origin.synthetic.test/api.php'), /same-origin/);

client.clearApiClientState();
calls = [];
globalThis.fetch = async (input, init) => {
  calls.push({ url: new URL(input).href, init });
  return new Response(JSON.stringify({ catalogVersion: 'synthetic-catalog-v1' }), {
    status: 200, headers: { 'Content-Type': 'application/json; charset=utf-8' },
  });
};
const publicResource = await client.fetchSameOriginResource('../assets/data/neo-catalog.json');
assert.equal(publicResource.ok, true);
assert.deepEqual(await publicResource.json(), { catalogVersion: 'synthetic-catalog-v1' });
assert.equal(calls.length, 1, 'same-origin public resource is fetched exactly once');
assert.equal(calls[0].init.method, 'GET');
assert.equal(calls[0].init.credentials, 'same-origin');
assert.equal(calls[0].init.cache, 'no-store');
assert.equal(calls[0].init.redirect, 'error');
assert.equal(new Headers(calls[0].init.headers).get('Accept'), 'application/json');
await assert.rejects(
  client.fetchSameOriginResource('https://cross-origin.synthetic.test/assets/data/neo-catalog.json'),
  (error) => error instanceof client.ApiError && error.code === 'CROSS_ORIGIN_RESOURCE_REJECTED',
);
assert.equal(calls.length, 1, 'cross-origin resource is rejected before network access');

globalThis.fetch = async () => new Response('<html>synthetic resource</html>', {
  status: 200, headers: { 'Content-Type': 'text/html; charset=utf-8' },
});
await assert.rejects(
  client.fetchSameOriginResource('../assets/data/neo-catalog.json'),
  (error) => error instanceof client.ApiError && error.code === 'INVALID_RESOURCE_CONTENT_TYPE',
);
client.clearApiClientState();
const data = await client.apiRequest('login', { method: 'POST', body: { username: 'synthetic_user', password: 'synthetic-only' } });
assert.equal(data.authenticated, true);
assert.equal(calls.length, 2, 'CSRF bootstrap and mutation should issue exactly two requests, with no retry');
assert.deepEqual(calls.map((item) => item.action), ['getSession', 'login']);

globalThis.fetch = async (input, init) => {
  const url = new URL(input);
  const action = url.searchParams.get('action');
  calls.push({ action, init });
  return jsonResponse({ type: 'urn:mr-neon:problem:forbidden', title: 'Forbidden', status: 403, detail: 'Synthetic rejection', code: 'FORBIDDEN', requestId: 'synthetic-forbidden-id' }, 403, 'application/problem+json; charset=utf-8');
};
await assert.rejects(
  client.apiRequest('getAll', { method: 'GET' }),
  (error) => error instanceof client.ApiError && error.status === 403 && error.code === 'FORBIDDEN' && error.requestId === 'synthetic-forbidden-id',
);

client.clearApiClientState();
calls = [];
const legacy = await client.apiFetch('https://mrneon.synthetic.test/api.php?action=getAll', { method: 'GET', headers: { Authorization: 'Bearer SYNTHETIC-LEGACY-TOKEN' } });
assert.equal(legacy.ok, false, 'legacy adapter preserves HTTP failure');
assert.equal(legacy.status, 403);
assert.equal((await legacy.json()).code, 'FORBIDDEN');
assert.equal(calls.length, 1);
assert.equal(new Headers(calls[0].init.headers).has('Authorization'), false, 'legacy Authorization is discarded');

// The compatibility adapter must preserve successful HTTP semantics and request correlation.
client.clearApiClientState();
calls = [];
globalThis.fetch = async (input) => {
  const url = new URL(input);
  if (url.searchParams.get('action') === 'getSession') {
    return jsonResponse({ data: { authenticated: false, csrfToken: csrfA }, meta: { requestId: 'synthetic-csrf-bootstrap' } });
  }
  return jsonResponse({ data: { attemptId: 'a'.repeat(32), deliveryStatus: 'accepted' }, meta: { requestId: 'synthetic-register-202' } }, 202);
};
const accepted = await client.apiFetch('https://mrneon.synthetic.test/api.php?action=register', {
  method: 'POST', body: JSON.stringify({ username: 'synthetic_user' }),
});
assert.equal(accepted.ok, true);
assert.equal(accepted.status, 202, 'compatibility adapter must preserve the actual success status');
assert.equal(accepted.headers.get('X-Request-ID'), 'synthetic-register-202');
const acceptedEnvelope = await accepted.json();
assert.equal(acceptedEnvelope.meta.requestId, 'synthetic-register-202', 'compatibility adapter must preserve request correlation');
assert.equal(acceptedEnvelope.data.deliveryStatus, 'accepted');

// Non-JSON payloads must not be mistaken for a successful API response.
globalThis.fetch = async () => new Response('<html>synthetic server warning</html>', { status: 200, headers: { 'Content-Type': 'text/html' } });
await assert.rejects(client.apiRequest('getSession'), (error) => error instanceof client.ApiError && error.code === 'INVALID_API_CONTENT_TYPE');

globalThis.fetch = async (_url, init) => new Promise((_resolve, reject) => {
  if (init.signal.aborted) {
    const error = new Error('synthetic abort');
    error.name = 'AbortError';
    reject(error);
    return;
  }
  init.signal.addEventListener('abort', () => {
    const error = new Error('synthetic abort');
    error.name = 'AbortError';
    reject(error);
  }, { once: true });
});
await assert.rejects(client.apiRequest('getSession', { timeoutMs: 1000 }), (error) => error instanceof client.ApiError && error.code === 'REQUEST_TIMEOUT');
const alreadyAborted = new AbortController();
alreadyAborted.abort();
await assert.rejects(client.apiRequest('getSession', { signal: alreadyAborted.signal }), (error) => error instanceof client.ApiError && error.code === 'REQUEST_ABORTED');

console.log('PASS: central API client behavior assertions; all request/response values are synthetic.');
