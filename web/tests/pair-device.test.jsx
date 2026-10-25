// @ts-check
// Appairage de l'appli (WEB-14) : code créé par POST /me/pairing-codes, affiché en QR + en clair.
import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { PairDevice, formatLeft, qrPath, serverFromPayload } from '../src/components/PairDevice.jsx';

const payload = 'rengaine://pair?server=https%3A%2F%2Fmusique.example&code=K7QF-2M9X';
/** @type {{ method: string, path: string }[]} */
let calls;
let pairStatus = 201;
/** @type {{ id: number, name: string, kind: string }[]} */
let devices;
const json = (/** @type {number} */ status, /** @type {any} */ body) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });

beforeEach(() => {
  calls = [];
  pairStatus = 201;
  devices = [{ id: 1, name: 'Firefox', kind: 'web' }];
  vi.stubGlobal('fetch', vi.fn(async (/** @type {string} */ url, /** @type {RequestInit} */ init) => {
    const path = new URL(url, 'http://localhost').pathname;
    calls.push({ method: init?.method ?? 'GET', path });
    if (path.endsWith('/me/devices')) return json(200, { items: devices });
    if (pairStatus !== 201) return json(pairStatus, { error: { status: pairStatus, code: 'rate_limited', message: 'Trop', requestId: 'x' } });
    return json(201, { code: 'K7QF-2M9X', expiresAt: new Date(Date.now() + 300_000).toISOString(), qrPayload: payload });
  }));
});
afterEach(() => {
  cleanup();
  vi.useRealTimers();
  vi.unstubAllGlobals();
});

describe('appairage', () => {
  it('QR avec marge de 4 modules', () => {
    const { d, size } = qrPath(payload);
    expect(size).toBeGreaterThanOrEqual(29);
    expect(d).toMatch(/^M\d+ \d+h1v1h-1z/);
  });

  it('adresse du serveur lue dans le QR', () => {
    expect(serverFromPayload(payload)).toBe('https://musique.example');
    expect(serverFromPayload('n’importe quoi')).toBe(window.location.origin);
  });

  it('temps restant', () => {
    expect(formatLeft(300_000)).toBe('5:00');
    expect(formatLeft(61_500)).toBe('1:02');
    expect(formatLeft(-5)).toBe('0:00');
  });

  it('affiche QR, code et serveur, puis annonce l’appareil appairé', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    const onPaired = vi.fn();
    render(<PairDevice onPaired={onPaired} />);
    fireEvent.click(screen.getByRole('button', { name: 'Appairer un appareil' }));
    await screen.findByRole('img', { name: 'QR code d’appairage' });
    expect(screen.getByText('K7QF-2M9X')).toBeTruthy();
    expect(screen.getByText('https://musique.example')).toBeTruthy();
    expect(calls.filter((c) => c.method === 'POST').map((c) => c.path)).toEqual(['/api/v2/me/pairing-codes']);
    devices = [...devices, { id: 7, name: 'Pixel 8', kind: 'app' }];
    await act(async () => { await vi.advanceTimersByTimeAsync(4100); });
    await waitFor(() => expect(screen.getByText('« Pixel 8 » est appairé.')).toBeTruthy());
    expect(onPaired).toHaveBeenCalledTimes(1);
    expect(screen.queryByRole('img')).toBeNull();
  });

  it('code expiré : propose un nouveau code', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    render(<PairDevice />);
    fireEvent.click(screen.getByRole('button', { name: 'Appairer un appareil' }));
    await screen.findByText('K7QF-2M9X');
    await act(async () => { await vi.advanceTimersByTimeAsync(301_000); });
    expect(screen.getByText('Ce code a expiré.')).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Nouveau code' })).toBeTruthy();
  });

  it('trop de codes : message clair', async () => {
    pairStatus = 429;
    render(<PairDevice />);
    fireEvent.click(screen.getByRole('button', { name: 'Appairer un appareil' }));
    await screen.findByText('Trop de codes demandés : réessayez dans une heure.');
  });
});
