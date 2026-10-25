// @ts-check
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ApiError, onSessionLost, request, setCsrfToken } from '../src/api/client.js';

/** @param {number} status @param {unknown} body @param {Record<string, string>} [headers] */
function respond(status, body, headers = {}) {
  return vi.fn(async () => new Response(body === null ? null : JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json', ...headers } }));
}

afterEach(() => {
  vi.unstubAllGlobals();
  setCsrfToken(null);
});

describe('client de l’API', () => {
  it('jeton anti-CSRF et If-Match sur les requêtes qui modifient, pas sur les lectures', async () => {
    const f = respond(200, { ok: true }, { ETag: '"4"' });
    vi.stubGlobal('fetch', f);
    setCsrfToken('jeton');
    const r = await request('PUT', '/me/settings', { body: { a: 1 }, ifMatch: 3 });
    expect(r.etag).toBe('"4"');
    const mc = /** @type {any[][]} */ (f.mock.calls);
    const init = /** @type {RequestInit} */ (mc[0][1]);
    expect(init.headers).toMatchObject({ 'X-CSRF-Token': 'jeton', 'If-Match': '"3"', 'Content-Type': 'application/json' });
    expect(init.credentials).toBe('same-origin');
    await request('GET', '/albums', { query: { genre: [3, 12], q: '', limit: 5 } });
    expect(mc[1][0]).toBe('/api/v2/albums?genre=3%2C12&limit=5');
    expect(/** @type {RequestInit} */ (mc[1][1]).headers).not.toHaveProperty('X-CSRF-Token');
  });

  it('erreur du contrat → ApiError avec le code stable', async () => {
    vi.stubGlobal('fetch', respond(412, { error: { status: 412, code: 'version_conflict', message: 'Modifié', requestId: 'R', details: { current: { version: 7 } } } }));
    await expect(request('PUT', '/me/queue', { body: {} })).rejects.toMatchObject({ status: 412, code: 'version_conflict', details: { current: { version: 7 } }, requestId: 'R' });
  });

  it('session expirée : prévient l’application, sauf demande contraire', async () => {
    const lost = vi.fn();
    const off = onSessionLost(lost);
    vi.stubGlobal('fetch', respond(401, { error: { status: 401, code: 'unauthorized', message: 'x', requestId: 'R' } }));
    await expect(request('GET', '/me')).rejects.toBeInstanceOf(ApiError);
    expect(lost).toHaveBeenCalledTimes(1);
    await expect(request('GET', '/me', { quiet401: true })).rejects.toBeInstanceOf(ApiError);
    expect(lost).toHaveBeenCalledTimes(1);
    off();
  });

  it('serveur injoignable', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => {
      throw new TypeError('Failed to fetch');
    }));
    await expect(request('GET', '/server')).rejects.toMatchObject({ code: 'network' });
  });
});
