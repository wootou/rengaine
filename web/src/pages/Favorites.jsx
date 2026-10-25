// @ts-check
// Mes favoris (WEB-08) : les pistes, albums et artistes marqués d'un cœur, du plus récent au plus
// ancien. Retirer un cœur ici laisse l'élément affiché jusqu'au prochain passage sur la page (pas de
// liste qui saute sous le doigt) ; il disparaît au rechargement.
import { useState } from 'react';
import { get } from '../api/client.js';
import { AlbumGrid } from '../components/AlbumGrid.jsx';
import { Favorite } from '../components/Favorite.jsx';
import { Icon } from '../components/Icon.jsx';
import { TrackList } from '../components/TrackList.jsx';
import { controller } from '../engine/controller.js';
import { session } from '../engine/session.js';
import { useApi, usePaged, useSentinel } from '../hooks.js';
import { Link } from '../router.jsx';
import { tr, trn } from '../i18n/index.js';

/** @typedef {import('../api/types.js').Track} Track */
/** @typedef {'pistes' | 'albums' | 'artistes'} Tab */

const TABS = /** @type {[Tab, 'track' | 'album' | 'artist', string][]} */ ([
  ['pistes', 'track', 'Pistes'], ['albums', 'album', 'Albums'], ['artistes', 'artist', 'Artistes'],
]);

/** Nombre de favoris d'un type (pour l'onglet). @param {'track' | 'album' | 'artist'} type */
function useCount(type) {
  return /** @type {{ total: number } | null} */ (useApi('/me/favorites', { type, limit: 1 }).data)?.total ?? null;
}

/** @param {{ query: URLSearchParams }} props */
export function Favorites({ query }) {
  const tab = /** @type {Tab} */ (TABS.some(([t]) => t === query.get('type')) ? query.get('type') : 'pistes');
  const counts = { track: useCount('track'), album: useCount('album'), artist: useCount('artist') };
  return (
    <section className="favorites">
      <div className="page-head"><h1>{tr('Mes favoris')}</h1><Link to="/historique" className="link-btn">{tr('Historique d’écoute')}</Link></div>
      <nav className="tabs" aria-label={tr('Type de favoris')}>
        {TABS.map(([t, type]) => (
          <Link key={t} to={t === 'pistes' ? '/favoris' : `/favoris?type=${t}`} className={tab === t ? 'current' : ''} aria-current={tab === t ? 'page' : undefined}>
            {{ track: tr('Pistes'), album: tr('Albums'), artist: tr('Artistes') }[type]}{counts[type] ? <span className="count">{counts[type]}</span> : null}
          </Link>
        ))}
      </nav>
      {tab === 'pistes' ? <FavoriteTracks /> : tab === 'albums' ? <FavoriteAlbums /> : <FavoriteArtists />}
    </section>
  );
}

/** Toutes les pistes favorites jouables (jusqu'à la taille maximale de la file). */
async function allTracks() {
  const max = session.getState().server?.limits?.maxQueueLength ?? 1000;
  /** @type {Track[]} */
  const out = [];
  for (let offset = 0; out.length < max; offset += 500) {
    const page = /** @type {{ items: { track: Track }[], total: number }} */ (await get('/me/favorites', { query: { type: 'track', limit: 500, offset } }));
    out.push(...page.items.map((i) => i.track));
    if (offset + 500 >= page.total) break;
  }
  return out.filter((t) => t.available).slice(0, max);
}

function FavoriteTracks() {
  const { items, total, loading, loadMore, done } = usePaged('/me/favorites', { type: 'track' }, 100);
  const sentinel = useSentinel(loadMore, !done && !loading);
  const tracks = /** @type {{ track: Track }[]} */ (items).map((i) => i.track);
  const [busy, setBusy] = useState(false);
  /** @param {(t: Track[]) => void} fn */
  const withAll = async (fn) => {
    setBusy(true);
    try {
      const all = await allTracks();
      if (all.length) fn(all);
    } finally {
      setBusy(false);
    }
  };
  if (total === 0) {
    return <Empty>{tr('Aucune piste en favori. Touchez le')} <Icon name="heartOutline" size={16} /> {tr('d’une piste pour la retrouver ici.')}</Empty>;
  }
  return (
    <>
      <div className="album-actions favorite-actions">
        <button type="button" className="primary" disabled={!total || busy} onClick={() => void withAll((t) => controller.playTracks(t, 0))}>
          <Icon name="play" size={22} /> <span className="btn-label">{tr('Tout écouter')}</span>
        </button>
        <button type="button" disabled={!total || busy} onClick={() => void withAll((t) => controller.playTracks([...t].sort(() => Math.random() - 0.5), 0))}>
          <Icon name="shuffle" size={20} /> <span className="btn-label">{tr('Au hasard')}</span>
        </button>
        <button type="button" className="icon-btn boxed" disabled={!total || busy} onClick={() => void withAll((t) => controller.enqueue(t))} title={tr('Ajouter à la file')}>
          <Icon name="add" size={20} />
        </button>
      </div>
      {tracks.length ? <TrackList tracks={tracks} showAlbum showCover /> : null}
      {!done ? <div ref={sentinel} className="sentinel">{loading ? tr('Chargement…') : ''}</div> : null}
    </>
  );
}

function FavoriteAlbums() {
  const { items, total, loading, loadMore, done } = usePaged('/me/favorites', { type: 'album' }, 60);
  const sentinel = useSentinel(loadMore, !done && !loading);
  const albums = /** @type {{ album: import('../api/types.js').AlbumSummary }[]} */ (items).map((i) => i.album);
  if (total === 0) return <Empty>{tr('Aucun album en favori. Le')} <Icon name="heartOutline" size={16} /> {tr('est en haut de chaque album.')}</Empty>;
  return (
    <>
      {albums.length ? <AlbumGrid albums={albums} /> : null}
      {!done ? <div ref={sentinel} className="sentinel">{loading ? tr('Chargement…') : ''}</div> : null}
    </>
  );
}

function FavoriteArtists() {
  const { items, total, loading, loadMore, done } = usePaged('/me/favorites', { type: 'artist' }, 100);
  const sentinel = useSentinel(loadMore, !done && !loading);
  const artists = /** @type {{ artist: import('../api/types.js').ArtistSummary }[]} */ (items).map((i) => i.artist);
  if (total === 0) return <Empty>{tr('Aucun artiste en favori. Le')} <Icon name="heartOutline" size={16} /> {tr('est en haut de la page de chaque artiste.')}</Empty>;
  return (
    <>
      <ul className="artist-list">
        {artists.map((a) => (
          <li key={a.id}>
            <Link to={`/artistes/${a.id}`}>{a.name}</Link>
            {' '}<small>{a.albumCount > 0 ? trn(a.albumCount, '{n} album', '{n} albums') : tr('invité')}</small>
            <Favorite type="artist" id={a.id} initial />
          </li>
        ))}
      </ul>
      {!done ? <div ref={sentinel} className="sentinel">{loading ? tr('Chargement…') : ''}</div> : null}
    </>
  );
}

/** @param {{ children: import('react').ReactNode }} props */
function Empty({ children }) {
  return <p className="empty">{children}</p>;
}
