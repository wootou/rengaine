// @ts-check
// Playlists (FUT-01) : les miennes (avec « Mes favoris » en tête), puis celles que les autres comptes
// partagent. « Nouvelle playlist » en crée une vide et l'ouvre.
import { useEffect, useState, useSyncExternalStore } from 'react';
import { ApiError } from '../api/client.js';
import { Cover } from '../components/Cover.jsx';
import { Icon } from '../components/Icon.jsx';
import { createPlaylist, playlists } from '../engine/playlists.js';
import { useApi } from '../hooks.js';
import { Link, navigate } from '../router.jsx';
import { tr, trn } from '../i18n/index.js';

/** @typedef {import('../engine/playlists.js').PlaylistSummary} PlaylistSummary */

/** Durée d'une playlist : « 48 min », « 2 h 05 ». @param {number} ms */
export function formatLength(ms) {
  const min = Math.round(ms / 60000);
  return min < 60 ? `${min} min` : `${Math.floor(min / 60)} h ${String(min % 60).padStart(2, '0')}`;
}

/** Mosaïque des pochettes (1 ou 4), ou un disque vide. @param {{ urls: string[], size?: 's' | 'm' }} props */
export function Mosaic({ urls, size = 'm' }) {
  if (urls.length < 4) return <Cover url={urls[0] ?? null} size={size} alt="" className="mosaic-single" />;
  return (
    <div className="mosaic" aria-hidden="true">
      {urls.slice(0, 4).map((u) => <Cover key={u} url={u} size="s" alt="" />)}
    </div>
  );
}

/** @param {{ p: PlaylistSummary }} props */
function PlaylistCard({ p }) {
  return (
    <li>
      <Link to={`/playlists/${p.id}`} className="album-card playlist-card" title={p.name}>
        <Mosaic urls={p.coverUrls} />
        <span className="album-title">{p.name}</span>
        <span className="album-artist">
          {trn(p.trackCount, '{n} piste', '{n} pistes')}{p.trackCount ? ` · ${formatLength(p.durationMs)}` : ''}
          {p.mine ? (p.shared ? ` · ${tr('partagée')}` : '') : ` · ${p.owner.displayName}`}
        </span>
      </Link>
    </li>
  );
}

export function Playlists() {
  const { changes } = useSyncExternalStore(playlists.subscribe, playlists.getState);
  const api = useApi('/playlists');
  const reload = api.reload;
  useEffect(() => {
    if (changes > 0) reload();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [changes]);
  const items = /** @type {{ items: PlaylistSummary[] } | null} */ (api.data)?.items ?? [];
  const mine = items.filter((p) => p.mine);
  const others = items.filter((p) => !p.mine);
  const [creating, setCreating] = useState(false);
  const [name, setName] = useState('');
  const [error, setError] = useState('');

  return (
    <section className="playlists-page">
      <div className="page-head">
        <h1>{tr('Playlists')}</h1>
        <button type="button" className="primary" onClick={() => setCreating(!creating)} aria-expanded={creating}>
          <Icon name="add" size={20} /> <span className="btn-label">{tr('Nouvelle playlist')}</span>
        </button>
      </div>
      {creating ? (
        <form className="playlist-new card" onSubmit={async (e) => {
          e.preventDefault();
          setError('');
          try {
            const p = await createPlaylist(name.trim());
            navigate(`/playlists/${p.id}`);
          } catch (err) {
            setError(err instanceof ApiError ? err.message : tr('Création impossible'));
          }
        }}>
          <label>{tr('Nom de la playlist')}
            <input type="text" maxLength={200} required value={name} onChange={(e) => setName(e.target.value)} autoFocus />
          </label>
          <button type="submit" className="primary" disabled={!name.trim()}>{tr('Créer')}</button>
          {error ? <p className="form-error" role="alert">{error}</p> : null}
        </form>
      ) : null}

      <h2>{tr('Mes playlists')}</h2>
      <ul className="album-grid">
        <li>
          <Link to="/favoris" className="album-card playlist-card favorites-card" title={tr('Mes favoris')}>
            <div className="cover favorites-cover" aria-hidden="true"><Icon name="heart" size={56} /></div>
            <span className="album-title">{tr('Mes favoris')}</span>
            <span className="album-artist">{tr('Pistes, albums et artistes')}</span>
          </Link>
        </li>
        {mine.map((p) => <PlaylistCard key={p.id} p={p} />)}
      </ul>
      {!api.loading && !mine.length ? (
        <p className="hint">{tr('Pas encore de playlist. Créez-en une ici, ou utilisez le bouton')} <Icon name="playlistAdd" size={16} />{' '}
          {tr('d’une piste, d’un album ou de la file d’attente.')}</p>
      ) : null}

      {others.length ? (
        <>
          <h2>{tr('Partagées par les autres')}</h2>
          <ul className="album-grid">{others.map((p) => <PlaylistCard key={p.id} p={p} />)}</ul>
        </>
      ) : null}
    </section>
  );
}
