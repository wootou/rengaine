// @ts-check
// Suppression du compte (CPT-09) : rien n'est envoyé pendant les 15 s d'annulation ; « Annuler »
// garde le compte ; au bout du délai, DELETE /me puis retour à la connexion avec un message.
import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CANCEL_SECONDS, DeleteAccount } from '../src/components/DeleteAccount.jsx';
import { session } from '../src/engine/session.js';

/** @type {{ method: string, path: string, body: any }[]} */
let calls;
let answer = { status: 204, body: /** @type {any} */ (null) };

beforeEach(() => {
  vi.useFakeTimers({ shouldAdvanceTime: false });
  calls = [];
  answer = { status: 204, body: null };
  vi.stubGlobal('fetch', vi.fn(async (/** @type {string} */ url, /** @type {RequestInit} */ init = {}) => {
    calls.push({ method: init.method ?? 'GET', path: new URL(url, 'http://localhost').pathname.replace('/api/v2', ''), body: init.body ? JSON.parse(String(init.body)) : undefined });
    return answer.body === null ? new Response(null, { status: answer.status })
      : new Response(JSON.stringify(answer.body), { status: answer.status, headers: { 'Content-Type': 'application/json' } });
  }));
  session.set({ status: 'ready', user: /** @type {any} */ ({ id: 5, role: 'user', displayName: 'Paul' }), settings: /** @type {any} */ ({}), notice: null });
});
afterEach(() => {
  cleanup();
  vi.useRealTimers();
  vi.unstubAllGlobals();
});

/** Ouvre la confirmation, tape le mot de passe et lance le compte à rebours. */
function confirm() {
  render(<DeleteAccount />);
  fireEvent.click(screen.getByRole('button', { name: 'Supprimer mon compte…' }));
  fireEvent.change(screen.getByLabelText(/Votre mot de passe/), { target: { value: 'secret-secret' } });
  fireEvent.click(screen.getByRole('button', { name: 'Supprimer définitivement' }));
}

const deletes = () => calls.filter((c) => c.method === 'DELETE' && c.path === '/me');

describe('suppression du compte', () => {
  it('annulée pendant le délai : rien n’est envoyé', async () => {
    confirm();
    expect(CANCEL_SECONDS).toBe(15);
    expect(screen.getByText(/Suppression dans/).textContent).toContain('15 s');
    await act(async () => { vi.advanceTimersByTime(14_000); });
    expect(screen.getByText(/Suppression dans/).textContent).toContain('1 s');
    fireEvent.click(screen.getByRole('button', { name: 'Annuler' }));
    await act(async () => { vi.advanceTimersByTime(5_000); });
    expect(deletes()).toEqual([]);
    expect(screen.getByRole('button', { name: 'Supprimer mon compte…' })).toBeTruthy();
    expect(session.getState().status).toBe('ready');
  });

  it('page quittée pendant le délai : rien n’est envoyé', async () => {
    confirm();
    cleanup();
    await act(async () => { vi.advanceTimersByTime(20_000); });
    expect(deletes()).toEqual([]);
  });

  it('au bout de 15 s : DELETE /me avec le mot de passe, puis retour à la connexion', async () => {
    confirm();
    await act(async () => { vi.advanceTimersByTime(14_500); });
    expect(deletes()).toEqual([]);
    await act(async () => { vi.advanceTimersByTime(1_000); });
    await act(async () => { await Promise.resolve(); });
    expect(deletes()).toEqual([{ method: 'DELETE', path: '/me', body: { password: 'secret-secret' } }]);
    expect(session.getState().status).toBe('anonymous');
    expect(session.getState().notice).toMatch(/supprimé/);
  });

  it('mauvais mot de passe : retour à la confirmation, le compte reste', async () => {
    answer = { status: 401, body: { error: { status: 401, code: 'invalid_credentials', message: 'Mot de passe incorrect', requestId: 'x' } } };
    confirm();
    await act(async () => { vi.advanceTimersByTime(16_000); });
    await act(async () => { await Promise.resolve(); });
    expect(deletes().length).toBe(1);
    expect(screen.getByRole('alert').textContent).toMatch(/Mot de passe incorrect/);
    expect(screen.getByRole('button', { name: 'Supprimer définitivement' })).toBeTruthy();
    expect(session.getState().status).toBe('ready');
  });
});
