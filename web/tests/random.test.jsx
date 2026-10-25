// @ts-check
// Page « Aléatoire » : le filtre enregistré sert au premier tirage ; décocher un genre ou un album
// change le tirage suivant, et le filtre est enregistré dans les réglages du compte.
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { session } from '../src/engine/session.js';
import { Random } from '../src/pages/Random.jsx';
import { track } from './fixtures.js';

/** @type {{ method: string, url: URL, body: any }[]} */
let calls;
const genres = { items: [
  { id: 3, name: 'Rock', parentId: null, albumCount: 2, trackCount: 20, aliases: [] },
  { id: 4, name: 'Jazz', parentId: null, albumCount: 1, trackCount: 9, aliases: [] },
] };
const albums = { items: [{ id: 9, title: 'Rock un', artistName: 'Groupe' }, { id: 10, title: 'Rock deux', artistName: 'Groupe' }], total: 2, limit: 500, offset: 0 };

/** @param {Partial<{ genreIds: number[], excludedAlbumIds: number[], count: number }>} random */
function withSettings(random) {
  session.set({ status: 'ready', settingsVersion: 1, settings: /** @type {any} */ ({ random: { genreIds: [], excludedAlbumIds: [], count: 100, ...random } }) });
}

beforeEach(() => {
  calls = [];
  vi.stubGlobal('fetch', vi.fn(async (/** @type {string} */ url, /** @type {RequestInit} */ init = {}) => {
    const u = new URL(url, 'http://localhost');
    const body = init.body ? JSON.parse(String(init.body)) : undefined;
    calls.push({ method: init.method ?? 'GET', url: u, body });
    const path = u.pathname.replace('/api/v2', '');
    const json = path === '/genres' ? genres : path === '/albums' ? albums
      : path === '/me/settings' ? { settings: body, version: 2, updatedAt: '2026-10-19T08:00:00Z' }
      : { items: [track(1, { title: 'Une' }), track(2, { title: 'Deux' })] };
    return new Response(JSON.stringify(json), { status: 200, headers: { 'Content-Type': 'application/json', ETag: '"2"' } });
  }));
});
afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const draws = () => calls.filter((c) => c.url.pathname.endsWith('/random/tracks'));

describe('page Aléatoire', () => {
  it('premier tirage avec le filtre enregistré', async () => {
    withSettings({ genreIds: [3], excludedAlbumIds: [9], count: 50 });
    render(<Random />);
    await screen.findByText('Une');
    const q = draws()[0].url.searchParams;
    expect([q.get('genre'), q.get('excludeAlbum'), q.get('count')]).toEqual(['3', '9', '50']);
  });

  it('décocher un genre et un album : tirage suivant filtré, filtre enregistré', async () => {
    withSettings({});
    render(<Random />);
    await screen.findByText('Une');
    expect(draws()[0].url.searchParams.get('genre')).toBeNull(); // tous les genres
    fireEvent.click(screen.getByRole('button', { name: /Sélection/ }));
    fireEvent.click(await screen.findByLabelText('Jazz'));
    fireEvent.click(screen.getByTitle('Albums de Rock'));
    fireEvent.click(await screen.findByLabelText(/Rock un/));
    fireEvent.change(screen.getByLabelText('Nombre de pistes'), { target: { value: '25' } });
    fireEvent.click(screen.getByRole('button', { name: /Générer une nouvelle sélection/ }));
    await waitFor(() => expect(draws()).toHaveLength(2));
    const q = draws()[1].url.searchParams;
    expect([q.get('genre'), q.get('excludeAlbum'), q.get('count')]).toEqual(['3', '9', '25']);
    await waitFor(() => expect(calls.find((c) => c.method === 'PUT')?.body.random).toEqual({ genreIds: [3], excludedAlbumIds: [9], count: 25 }));
  });

  it('aucun genre coché : pas de tirage possible', async () => {
    withSettings({});
    render(<Random />);
    await screen.findByText('Une');
    fireEvent.click(screen.getByRole('button', { name: /Sélection/ }));
    await screen.findByLabelText('Jazz');
    fireEvent.click(screen.getByRole('button', { name: 'Tout décocher' }));
    expect(screen.getByRole('button', { name: /Générer une nouvelle sélection/ }).hasAttribute('disabled')).toBe(true);
    expect(screen.getByText('Cochez au moins un genre.')).toBeTruthy();
  });
});
