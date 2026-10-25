// @ts-check
// Écrans d'admin : ce qui part vers l'API (seulement les champs modifiés, les bons statuts).
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Jobs } from '../src/admin/Activity.jsx';
import { Backgrounds } from '../src/admin/Backgrounds.jsx';
import { Duplicates } from '../src/admin/Duplicates.jsx';
import { SiteBackdrop } from '../src/components/SiteBackdrop.jsx';
import { Admin } from '../src/admin/Admin.jsx';
import { AlbumEdit } from '../src/admin/AlbumEdit.jsx';
import { ArtistEdit } from '../src/admin/ArtistEdit.jsx';
import { ChangeSetDetail } from '../src/admin/ChangeSets.jsx';
import { formatDuration, formatValue, useTableLabels } from '../src/admin/common.jsx';
import { Tags } from '../src/admin/Library.jsx';
import { Users } from '../src/admin/Users.jsx';
import { session } from '../src/engine/session.js';
import { track } from './fixtures.js';

/** @typedef {{ method: string, path: string, query: URLSearchParams, body: any }} Call */

/**
 * Faux serveur : `routes` associe « MÉTHODE /chemin » à une réponse (ou à une fonction).
 * Une réponse `{ status, error }` avec un statut d'erreur est renvoyée telle quelle.
 * @param {Record<string, any>} routes
 */
function fakeApi(routes) {
  /** @type {Call[]} */
  const calls = [];
  const f = vi.fn(async (/** @type {string} */ url, /** @type {RequestInit} */ init = {}) => {
    const u = new URL(url, 'http://localhost');
    const path = u.pathname.replace('/api/v2', '');
    const method = init.method ?? 'GET';
    const body = init.body ? JSON.parse(String(init.body)) : undefined;
    calls.push({ method, path, query: u.searchParams, body });
    let r = routes[`${method} ${path}`];
    if (typeof r === 'function') r = r(body, u.searchParams);
    if (r === undefined) return new Response(JSON.stringify({ error: { status: 404, code: 'not_found', message: 'introuvable', requestId: 'R' } }), { status: 404 });
    if (r === null) return new Response(null, { status: 204 });
    if (r.error && r.status >= 400) return new Response(JSON.stringify({ error: { status: r.status, requestId: 'R', ...r.error } }), { status: r.status });
    return new Response(JSON.stringify(r), { status: method === 'POST' && path === '/admin/jobs' ? 202 : 200, headers: { 'Content-Type': 'application/json' } });
  });
  vi.stubGlobal('fetch', f);
  return calls;
}

const admin = { id: 1, email: 'admin@test', displayName: 'Admin', role: /** @type {const} */ ('admin') };
const pending = { id: 7, email: 'alice@test', displayName: 'Alice', role: 'user', status: 'pending', requestMessage: 'Coucou', createdAt: '2026-10-19T08:00:00Z', decidedAt: null, lastLoginAt: null };
const genres = { items: [{ id: 3, name: 'Rock', parentId: null, albumCount: 1, trackCount: 2, aliases: [] }, { id: 4, name: 'Jazz', parentId: null, albumCount: 0, trackCount: 0, aliases: [] }] };

beforeEach(() => session.set({ status: 'ready', user: admin }));
afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

describe('admin : accès', () => {
  it('un utilisateur ordinaire ne voit pas les écrans', () => {
    session.set({ user: { ...admin, role: 'user' } });
    render(<Admin path="/admin" />);
    expect(screen.getByText(/réservée à l’admin/)).toBeTruthy();
  });
});

describe('admin : comptes', () => {
  it('approuver une demande envoie status=active', async () => {
    const calls = fakeApi({
      'GET /admin/users': { items: [pending], total: 1, limit: 50, offset: 0 },
      'PATCH /admin/users/7': (/** @type {any} */ b) => ({ ...pending, ...b }),
    });
    render(<Users />);
    await screen.findByText('Alice');
    expect(screen.getByText('Coucou')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Approuver' }));
    await screen.findByText(/compte activé/);
    expect(calls.find((c) => c.method === 'PATCH')?.body).toEqual({ status: 'active' });
  });

  it('refuser demande confirmation ; annuler ne change rien', async () => {
    const calls = fakeApi({ 'GET /admin/users': { items: [pending], total: 1, limit: 50, offset: 0 } });
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
    render(<Users />);
    fireEvent.click(await screen.findByRole('button', { name: 'Refuser' }));
    expect(confirm).toHaveBeenCalled();
    expect(calls.some((c) => c.method === 'PATCH')).toBe(false);
  });

  it('pas de bouton d’action sur son propre compte', async () => {
    fakeApi({ 'GET /admin/users': { items: [{ ...pending, ...admin, status: 'active' }], total: 1, limit: 50, offset: 0 } });
    render(<Users />);
    await screen.findByText('vous');
    expect(screen.queryByRole('button', { name: 'Suspendre' })).toBeNull();
  });
});

describe('admin : correction d’un album', () => {
  const album = {
    id: 5, title: 'Vieux titre', kind: 'album', artist: { id: 2, name: 'Cabrel' }, artistName: 'Cabrel', year: 1977, trackCount: 2, discCount: 1,
    durationMs: 1, hasLossless: false, coverUrl: null, genres: [{ id: 3, name: 'Rock' }], addedAt: '2026-10-19T08:00:00Z', favorite: false,
    isCompilation: false, loudness: { lufs: -12, truePeakDbtp: 0 }, collections: [],
    tracks: [track(11, { title: 'Un' }), track(12, { title: 'Deux', playback: { bpm: 120, key: 'Am' } })],
    sourceDir: 'Cabrel/Album', files: [], activeCorrections: [],
  };

  it('n’envoie que les champs modifiés de l’album', async () => {
    const calls = fakeApi({ 'GET /admin/albums/5': album, 'GET /genres': genres, 'PATCH /admin/albums/5': album });
    render(<AlbumEdit id="5" />);
    fireEvent.change(await screen.findByLabelText('Titre'), { target: { value: 'Nouveau titre' } });
    fireEvent.change(screen.getByLabelText('Ajouter un genre'), { target: { value: '4' } });
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer l’album' }));
    await screen.findByText('Album corrigé.');
    expect(calls.find((c) => c.method === 'PATCH')?.body).toEqual({ title: 'Nouveau titre', genreIds: [3, 4] });
  });

  it('artiste vidé = artistes divers (null)', async () => {
    const calls = fakeApi({ 'GET /admin/albums/5': album, 'GET /genres': genres, 'PATCH /admin/albums/5': album });
    render(<AlbumEdit id="5" />);
    fireEvent.change(await screen.findByLabelText('Artiste de l’album'), { target: { value: '' } });
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer l’album' }));
    await waitFor(() => expect(calls.find((c) => c.method === 'PATCH')?.body).toEqual({ albumArtist: null }));
  });

  it('piste : seulement les champs changés ; clé invalide refusée avant l’envoi', async () => {
    const calls = fakeApi({ 'GET /admin/albums/5': album, 'GET /genres': genres, 'PATCH /admin/tracks/12': track(12) });
    render(<AlbumEdit id="5" />);
    const row = await screen.findByLabelText('Clé (Deux)');
    fireEvent.change(row, { target: { value: 'H' } });
    expect(screen.getByRole('button', { name: 'Enregistrer' }).hasAttribute('disabled')).toBe(true);
    fireEvent.change(row, { target: { value: 'F#m' } });
    fireEvent.change(screen.getByLabelText('BPM (Deux)'), { target: { value: '98,5' } });
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    await screen.findByText(/corrigée/);
    expect(calls.find((c) => c.method === 'PATCH')?.body).toEqual({ key: 'F#m', bpm: 98.5 });
  });
});

describe('admin : séparer un album', () => {
  it('les pistes cochées partent dans un nouvel album, avec l’artiste et l’année de l’album', async () => {
    const album = {
      id: 5, title: 'Double', kind: 'album', artist: { id: 2, name: 'Cabrel' }, artistName: 'Cabrel', year: 1977, trackCount: 2, discCount: 1,
      durationMs: 1, hasLossless: false, coverUrl: null, genres: [], addedAt: '2026-10-19T08:00:00Z', favorite: false,
      isCompilation: false, loudness: { lufs: -12, truePeakDbtp: 0 }, collections: [],
      tracks: [track(11, { title: 'Un' }), track(12, { title: 'Deux' })], sourceDir: 'Cabrel/Double', files: [], activeCorrections: [],
    };
    const calls = fakeApi({ 'GET /admin/albums/5': album, 'GET /genres': genres, 'POST /admin/albums': { ...album, id: 9 } });
    render(<AlbumEdit id="5" />);
    fireEvent.click(await screen.findByLabelText('Choisir « Deux »'));
    expect(screen.getByText('Séparer 1 piste en un nouvel album')).toBeTruthy();
    fireEvent.change(screen.getByLabelText('Titre du nouvel album'), { target: { value: 'Double (disque 2)' } });
    fireEvent.click(screen.getByRole('button', { name: 'Créer l’album' }));
    await waitFor(() => expect(calls.find((c) => c.method === 'POST')?.body).toEqual({ title: 'Double (disque 2)', albumArtist: 'Cabrel', year: 1977, trackIds: [12] }));
    await waitFor(() => expect(window.location.pathname).toBe('/albums/9/corriger'));
  });
});

describe('admin : artiste', () => {
  const artist = { id: 2, name: 'Cabrell', sortName: null, albumCount: 1, imageUrl: null, favorite: false, albums: [], appearsOn: [] };
  const empty = { items: [], total: 0, limit: 50, offset: 0 };

  it('renommer n’envoie que le nom ; un nom déjà pris affiche le conflit', async () => {
    const calls = fakeApi({
      'GET /artists/2': artist, 'GET /admin/corrections': empty, 'GET /genres': genres,
      'PATCH /admin/artists/2': { status: 409, error: { code: 'conflict', message: 'Un autre artiste s’appelle déjà « Cabrel »' } },
    });
    render(<ArtistEdit id="2" />);
    fireEvent.change(await screen.findByLabelText('Nom'), { target: { value: 'Cabrel' } });
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    await screen.findByText(/s’appelle déjà « Cabrel »/);
    expect(calls.find((c) => c.method === 'PATCH')?.body).toEqual({ name: 'Cabrel' });
  });
});

describe('admin : lots de l’agent', () => {
  const pendingSet = {
    id: 5, title: 'Genres des compilations', description: 'Trois corrections', status: 'pending',
    createdBy: { id: 3, displayName: 'Claude', role: 'agent' }, createdAt: '2026-10-19T08:00:00Z', decidedAt: null, decidedBy: null, changeCount: 2,
    changes: [
      { entityType: 'album', entityId: 7, field: 'year', value: 1999, previousValue: 1998, currentValue: 1998, correctionId: 40, status: 'proposed', reason: 'Date de sortie' },
      { entityType: 'genreAlias', alias: 'Hip-Hop/Rap', field: 'genreId', value: 3, previousValue: null, currentValue: 4, correctionId: 41, status: 'proposed' },
    ],
  };

  it('montre chaque changement, signale ce qui a bougé, puis applique', async () => {
    const calls = fakeApi({
      'GET /admin/change-sets/5': pendingSet, 'GET /genres': genres,
      'POST /admin/change-sets/5/apply': { ...pendingSet, status: 'applied' },
    });
    render(<ChangeSetDetail id="5" />);
    expect(await screen.findByText('Date de sortie')).toBeTruthy();
    expect(screen.getByText('Tag « Hip-Hop/Rap »')).toBeTruthy();
    await waitFor(() => expect(screen.getAllByText('Rock').length).toBeGreaterThan(0)); // genreId 3 → nom du genre
    expect(screen.getByText(/ont changé depuis la proposition/)).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Appliquer le lot' }));
    await screen.findByText(/Lot appliqué/);
    expect(calls.some((c) => c.method === 'POST' && c.path === '/admin/change-sets/5/apply')).toBe(true);
  });

  it('refuser demande confirmation', async () => {
    const calls = fakeApi({ 'GET /admin/change-sets/5': pendingSet, 'GET /genres': genres });
    vi.spyOn(window, 'confirm').mockReturnValue(false);
    render(<ChangeSetDetail id="5" />);
    fireEvent.click(await screen.findByRole('button', { name: 'Refuser' }));
    expect(calls.some((c) => c.method === 'POST')).toBe(false);
  });
});

describe('admin : tags et tâches', () => {
  it('rattacher une valeur de tag à un genre', async () => {
    const calls = fakeApi({
      'GET /admin/genre-aliases': { items: [{ alias: 'Rock & Roll', genre: null, trackCount: 4, firstSeenAt: '2026-10-19T08:00:00Z' }], total: 1, limit: 50, offset: 0 },
      'GET /genres': genres,
      'POST /admin/genre-aliases': { alias: 'Rock & Roll', genre: { id: 3, name: 'Rock' }, trackCount: 4, firstSeenAt: '2026-10-19T08:00:00Z' },
    });
    render(<Tags />);
    await screen.findByText('Rock & Roll');
    await waitFor(() => expect(screen.getAllByRole('option', { name: 'Rock' }).length).toBeGreaterThan(0));
    fireEvent.change(screen.getByLabelText('Genre de « Rock & Roll »'), { target: { value: '3' } });
    await screen.findByText(/rattaché à Rock/);
    expect(calls.find((c) => c.method === 'POST')?.body).toEqual({ alias: 'Rock & Roll', genreId: 3 });
    expect(calls.find((c) => c.path === '/admin/genre-aliases')?.query.get('mapped')).toBe('false');
  });

  it('lancer un scan d’un dossier', async () => {
    const calls = fakeApi({
      'GET /admin/jobs': { items: [], total: 0, limit: 20, offset: 0 },
      'GET /admin/job-errors': { items: [], total: 0, limit: 30, offset: 0 },
      'POST /admin/jobs': { id: 9, type: 'index', status: 'queued', createdAt: '2026-10-19T08:00:00Z' },
    });
    render(<Jobs />);
    fireEvent.change(screen.getByLabelText(/Dossier/), { target: { value: '/Cabrel/Album' } });
    fireEvent.click(screen.getByRole('button', { name: 'Lancer un scan' }));
    await screen.findByText(/Scan demandé/);
    expect(calls.find((c) => c.method === 'POST')?.body).toEqual({ type: 'index', params: { prefix: 'Cabrel/Album' } });
  });

  it('dépôt : état de chaque dossier, raison d’un blocage, rangement à la demande', async () => {
    const item = { fileCount: 3, sizeBytes: 3 * 1048576, albumTitle: null, albumArtist: null, firstSeenAt: '2026-10-20T08:00:00Z', updatedAt: '2026-10-20T08:00:00Z', filedAt: null };
    const calls = fakeApi({
      'GET /admin/jobs': { items: [], total: 0, limit: 20, offset: 0 },
      'GET /admin/job-errors': { items: [], total: 0, limit: 30, offset: 0 },
      'GET /admin/inbox': { items: [
        { ...item, id: 1, sourceDir: 'inbox/vrac', status: 'blocked', message: 'Les pistes portent plusieurs albums', targetDir: null },
        { ...item, id: 2, sourceDir: 'inbox/Tonight', status: 'filed', message: null, targetDir: 'Franz Ferdinand/Tonight (2009)', filedAt: '2026-10-20T09:00:00Z' },
      ] },
      'POST /admin/jobs': { id: 9, type: 'inbox', status: 'queued', createdAt: '2026-10-20T08:00:00Z' },
    });
    render(<Jobs />);
    expect(await screen.findByText('À ranger à la main')).toBeTruthy();
    expect(screen.getByText('Les pistes portent plusieurs albums')).toBeTruthy();
    expect(screen.getByText('Franz Ferdinand/Tonight (2009)')).toBeTruthy();
    expect(screen.getByText('1 en attente')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Ranger maintenant' }));
    await screen.findByText(/Rangement demandé/);
    expect(calls.find((c) => c.method === 'POST')?.body).toEqual({ type: 'inbox' });
  });
});

describe('admin : affichage', () => {
  it('tableaux en cartes sur téléphone : chaque cellule porte le nom de sa colonne, lignes ajoutées comprises', async () => {
    /** @param {{ rows: string[] }} props */
    function Table({ rows }) {
      const ref = useTableLabels();
      return (
        <div ref={ref}>
          <table className="admin-table">
            <thead><tr><th>Nom</th><th>Statut</th><th /></tr></thead>
            <tbody>
              {rows.map((r) => <tr key={r}><td>{r}</td><td>Actif</td><td><button type="button">Suspendre</button></td></tr>)}
              <tr className="details"><td colSpan={2}>détail</td><td>fin</td></tr>
            </tbody>
          </table>
        </div>
      );
    }
    const { rerender, container } = render(<Table rows={['Alice']} />);
    const labels = () => [...container.querySelectorAll('tbody tr')].map((tr) => [...tr.children].map((td) => td.getAttribute('data-label')));
    expect(labels()).toEqual([['Nom', 'Statut', null], [null, null]]);
    rerender(<Table rows={['Alice', 'Bob']} />);
    await waitFor(() => expect(labels()[1]).toEqual(['Nom', 'Statut', null]));
  });

  it('valeurs de correction et durées lisibles', () => {
    expect(formatValue('genreIds', [3, 9], new Map([[3, 'Rock']]))).toBe('Rock, #9');
    expect(formatValue('isCompilation', true)).toBe('oui');
    expect(formatValue('year', null)).toBe('—');
    expect(formatValue('genreId', 3, new Map([[3, 'Rock']]))).toBe('Rock');
    expect(formatDuration('2026-10-19T08:00:00Z', '2026-10-19T08:02:20Z')).toBe('2 min 20 s');
  });
});

describe('images de fond', () => {
  const bg = { id: 4, name: 'Plage', active: true, width: 2560, height: 1440, sizeBytes: 300000, mimeType: 'image/webp', imageUrl: '/api/v2/backgrounds/4/image?v=abc', createdAt: '2026-10-20T08:00:00Z' };

  it('admin : activer, renommer, supprimer', async () => {
    const calls = fakeApi({
      'GET /admin/backgrounds': { items: [bg] },
      'PATCH /admin/backgrounds/4': (/** @type {any} */ body) => ({ ...bg, ...body }),
      'DELETE /admin/backgrounds/4': null,
    });
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    render(<Backgrounds />);
    expect((await screen.findByRole('img', { name: 'Plage' })).getAttribute('src')).toBe(bg.imageUrl);
    expect(screen.getByText(/1 \/ 30/)).toBeTruthy();

    fireEvent.click(screen.getByLabelText('Active'));
    await screen.findByText('Image désactivée.');
    const name = screen.getByLabelText('Nom de l’image Plage');
    fireEvent.change(name, { target: { value: 'Plage au soir' } });
    fireEvent.blur(name);
    await screen.findByText('Nom enregistré.');
    fireEvent.click(screen.getByRole('button', { name: 'Supprimer' }));
    await screen.findByText('Image supprimée.');
    expect(calls.filter((c) => c.method !== 'GET').map((c) => [c.method, c.body])).toEqual([
      ['PATCH', { active: false }], ['PATCH', { name: 'Plage au soir' }], ['DELETE', undefined],
    ]);
  });

  it('le site affiche une image active, sauf si le compte les a masquées', async () => {
    fakeApi({ 'GET /backgrounds': { items: [{ id: 4, imageUrl: bg.imageUrl }] } });
    const settings = { interface: { theme: 'auto', background: true } };
    session.set({ settings: /** @type {any} */ (settings) });
    const { container, rerender } = render(<SiteBackdrop />);
    await waitFor(() => expect(container.querySelector('.site-backdrop img')?.getAttribute('src')).toBe(bg.imageUrl));
    session.set({ settings: /** @type {any} */ ({ interface: { theme: 'auto', background: false } }) });
    rerender(<SiteBackdrop />);
    await waitFor(() => expect(container.querySelector('.site-backdrop')).toBeNull());
  });
});

describe('MP3 en double', () => {
  const album = { albumId: 3, title: 'Tonight', artist: 'Franz Ferdinand', removable: 7, kept: 5, removableBytes: 58_000_000 };
  /** @param {number} n @param {boolean} removable @param {string | null} reason */
  const file = (n, removable, reason) => ({
    trackId: n, discNumber: 1, trackNumber: n, title: `Titre ${n}`, removable, reason,
    mp3: { storageKey: `Tonight/0${n}.mp3`, sizeBytes: 8_000_000, durationMs: 200_000, bitrateKbps: 320 },
    lossless: { storageKey: `Tonight/flac/0${n}.flac`, format: 'flac', durationMs: removable ? 200_500 : 178_000, available: true },
  });

  it('détail par piste, puis retrait demandé après confirmation', async () => {
    const calls = fakeApi({
      'GET /admin/duplicates': { items: [album], removableBytes: album.removableBytes },
      'GET /admin/duplicates/3': { ...album, files: [file(1, false, 'durées différentes (22 s) : peut-être une autre version'), file(2, true, null)] },
      'POST /admin/jobs': { id: 9, type: 'dedup', status: 'queued', params: { albumId: 3 }, createdAt: '2026-10-20T20:00:00Z' },
    });
    render(<Duplicates />);
    expect(await screen.findByText('Tonight')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Détail' }));
    expect(await screen.findByText(/durées différentes \(22 s\)/)).toBeTruthy();
    expect(screen.getByText('Gardé')).toBeTruthy();
    expect(screen.getByText('Retirable')).toBeTruthy();

    const confirm = vi.spyOn(window, 'confirm').mockReturnValueOnce(false).mockReturnValueOnce(true);
    fireEvent.click(screen.getByRole('button', { name: 'Retirer 7 MP3' }));
    expect(calls.some((c) => c.method === 'POST')).toBe(false);
    fireEvent.click(screen.getByRole('button', { name: 'Retirer 7 MP3' }));
    await screen.findByText(/Retrait demandé pour « Franz Ferdinand – Tonight »/);
    expect(confirm.mock.calls[1][0]).toMatch(/corbeille/);
    expect(calls.find((c) => c.method === 'POST')?.body).toEqual({ type: 'dedup', params: { albumId: 3 } });
    expect(screen.getByRole('button', { name: 'Retrait en cours…' })).toHaveProperty('disabled', true);
  });
});
