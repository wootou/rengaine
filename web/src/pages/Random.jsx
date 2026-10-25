// @ts-check
// Page « Aléatoire » (reprise de l'ancien site) : une liste de pistes au hasard, filtrée par genres,
// avec des albums à laisser de côté dans chaque genre. Le filtre est enregistré dans les réglages
// du compte (`random`) : l'appli tire la même liste avec `GET /random/tracks`.
import { useEffect, useState } from 'react';
import { ApiError, get } from '../api/client.js';
import { Cover } from '../components/Cover.jsx';
import { Icon } from '../components/Icon.jsx';
import { TrackList } from '../components/TrackList.jsx';
import { controller } from '../engine/controller.js';
import { formatTime } from '../engine/playback.js';
import { updateSettings } from '../engine/session.js';
import { useApi, useSession } from '../hooks.js';
import { tr, trn } from '../i18n/index.js';

/** @typedef {import('../api/types.js').Track} Track */
/** @typedef {import('../api/types.js').Genre} Genre */
/** @typedef {{ genreIds: number[], excludedAlbumIds: number[], count: number }} RandomFilter */

const COUNTS = [25, 50, 100, 200];
const DEFAULT = /** @type {RandomFilter} */ ({ genreIds: [], excludedAlbumIds: [], count: 100 });

export function Random() {
  const { settings } = useSession();
  const saved = settings?.random ?? DEFAULT;
  const genresApi = useApi('/genres');
  const genres = /** @type {{ items: Genre[] } | null} */ (genresApi.data)?.items.filter((g) => g.trackCount > 0) ?? [];
  // Genres cochés : « tous » tant que le filtre enregistré n'en nomme aucun
  const [checked, setChecked] = useState(/** @type {Set<number> | null} */ (saved.genreIds.length ? new Set(saved.genreIds) : null));
  const [excluded, setExcluded] = useState(() => new Set(saved.excludedAlbumIds));
  const [count, setCount] = useState(saved.count);
  const [tracks, setTracks] = useState(/** @type {Track[] | null} */ (null));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [open, setOpen] = useState(false);

  const allIds = genres.map((g) => g.id);
  const selected = checked ?? new Set(allIds);
  const none = genres.length > 0 && selected.size === 0;

  /** Filtre au format des réglages et de l'API (aucun genre nommé = tous). @returns {RandomFilter} */
  const filter = () => ({
    genreIds: checked === null || allIds.every((id) => checked.has(id)) ? [] : [...checked].sort((a, b) => a - b),
    excludedAlbumIds: [...excluded].sort((a, b) => a - b).slice(0, 500),
    count,
  });

  /** @param {RandomFilter} f */
  const draw = async (f) => {
    setBusy(true);
    setError('');
    try {
      const r = /** @type {{ items: Track[] }} */ (await get('/random/tracks', { query: { genre: f.genreIds, excludeAlbum: f.excludedAlbumIds, count: f.count } }));
      setTracks(r.items);
      if (settings && JSON.stringify(f) !== JSON.stringify(settings.random)) {
        updateSettings((s) => ({ ...s, random: f })).catch((e) => console.warn('Filtre de l’aléatoire :', e));
      }
    } catch (e) {
      setError(e instanceof ApiError ? e.message : tr('Tirage impossible.'));
    } finally {
      setBusy(false);
    }
  };

  // Premier tirage à l'ouverture, avec le filtre enregistré
  useEffect(() => {
    void draw(saved);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  /** @param {number} id @param {boolean} on */
  const toggleGenre = (id, on) => {
    const next = new Set(selected);
    if (on) next.add(id);
    else next.delete(id);
    setChecked(next);
  };
  /** @param {number} id @param {boolean} on */
  const toggleAlbum = (id, on) => {
    const next = new Set(excluded);
    if (on) next.delete(id);
    else next.add(id);
    setExcluded(next);
  };

  const f = filter();
  const summary = (f.genreIds.length === 0 ? tr('Tous les genres') : genres.filter((g) => selected.has(g.id)).map((g) => g.name).join(', '))
    + (f.excludedAlbumIds.length ? ` · ${trn(f.excludedAlbumIds.length, '{n} album de côté', '{n} albums de côté')}` : '');

  return (
    <section className="random-page">
      <div className="page-head">
        <div className="random-title">
          <span className="cover-stack" aria-hidden="true">
            {(tracks ?? []).slice(0, 3).map((t, i) => <Cover key={`${t.id}-${i}`} url={t.coverUrl} size="s" />)}
          </span>
          <div>
            <h1>{tr('Aléatoire')}</h1>
            {tracks?.length ? <p className="meta">{trn(tracks.length, '{n} piste', '{n} pistes')} · {formatTime(tracks.reduce((n, t) => n + t.durationMs, 0))}</p> : null}
          </div>
        </div>
        <div className="album-actions">
          <button type="button" className="primary" disabled={busy || none} onClick={() => void draw(f)}><Icon name="shuffle" size={20} /> {tr('Générer une nouvelle sélection')}</button>
          <button type="button" disabled={!tracks?.length} onClick={() => tracks && controller.playTracks(tracks, 0)}><Icon name="play" size={20} /> {tr('Tout écouter')}</button>
          <button type="button" disabled={!tracks?.length} onClick={() => tracks && controller.enqueue(tracks)}><Icon name="add" size={20} /> {tr('Ajouter à la file')}</button>
        </div>
      </div>

      <div className="random-filter">
        <label>{tr('Pistes')}
          <select aria-label={tr('Nombre de pistes')} value={count} onChange={(e) => setCount(Number(e.target.value))}>
            {COUNTS.map((n) => <option key={n} value={n}>{n}</option>)}
          </select>
        </label>
        <button type="button" className="link-btn" aria-expanded={open} onClick={() => setOpen(!open)}>
          {open ? tr('Masquer la sélection') : tr('Sélection')} <small>({summary})</small>
        </button>
      </div>

      {open ? (
        <div className="random-select card">
          <div className="random-select-head">
            <button type="button" className="link-btn" onClick={() => { setChecked(null); setExcluded(new Set()); }}>{tr('Tout cocher')}</button>
            <button type="button" className="link-btn" onClick={() => setChecked(new Set())}>{tr('Tout décocher')}</button>
            <small>{tr('Le choix s’applique à la prochaine sélection (« Générer une nouvelle sélection »).')}</small>
          </div>
          <ul className="genre-groups">
            {genres.map((g) => (
              <GenreGroup key={g.id} genre={g} checked={selected.has(g.id)} excluded={excluded}
                onGenre={(on) => toggleGenre(g.id, on)} onAlbum={toggleAlbum} />
            ))}
          </ul>
          {none ? <p className="form-error" role="alert">{tr('Cochez au moins un genre.')}</p> : null}
        </div>
      ) : null}

      {error ? <p className="form-error" role="alert">{error}</p> : null}
      {tracks === null ? <p className="empty">{tr('Tirage…')}</p>
        : tracks.length === 0 ? <p className="empty">{tr('Aucune piste ne correspond à la sélection.')}</p>
        : <TrackList tracks={tracks} showAlbum showCover />}
    </section>
  );
}

/**
 * Un genre et, dépliés, ses albums à cocher ou décocher.
 * @param {{ genre: Genre, checked: boolean, excluded: Set<number>, onGenre: (on: boolean) => void, onAlbum: (id: number, on: boolean) => void }} props
 */
function GenreGroup({ genre, checked, excluded, onGenre, onAlbum }) {
  const [open, setOpen] = useState(false);
  const albumsApi = useApi(open ? '/albums' : null, { genre: genre.id, limit: 500, sort: 'name' });
  const albums = /** @type {{ items: import('../api/types.js').AlbumSummary[] } | null} */ (albumsApi.data)?.items ?? [];
  const out = albums.filter((a) => excluded.has(a.id)).length;
  return (
    <li className="genre-group">
      <div className="genre-group-head">
        <label className="check"><input type="checkbox" checked={checked} onChange={(e) => onGenre(e.target.checked)} /> {genre.name}</label>
        <small>{trn(genre.albumCount, '{n} album', '{n} albums')}{out ? ` · ${tr('{n} de côté', { n: out })}` : ''}</small>
        <button type="button" className="icon-btn" aria-expanded={open} title={open ? tr('Replier') : tr('Albums de {nom}', { nom: genre.name })} onClick={() => setOpen(!open)}>
          {open ? '▲' : '▼'}
        </button>
      </div>
      {open ? (
        <ul className="genre-group-albums">
          {albumsApi.loading ? <li><small>{tr('Chargement…')}</small></li> : null}
          {albums.map((a) => (
            <li key={a.id}>
              <label className="check">
                <input type="checkbox" disabled={!checked} checked={checked && !excluded.has(a.id)} onChange={(e) => onAlbum(a.id, e.target.checked)} />
                {a.title} <small>{a.artistName}</small>
              </label>
            </li>
          ))}
        </ul>
      ) : null}
    </li>
  );
}
