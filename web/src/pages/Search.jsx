// @ts-check
import { AlbumGrid } from '../components/AlbumGrid.jsx';
import { TrackList } from '../components/TrackList.jsx';
import { useApi } from '../hooks.js';
import { Link } from '../router.jsx';
import { tr } from '../i18n/index.js';

/** @param {{ q: string }} props */
export function Search({ q }) {
  const { data, loading } = useApi(q ? '/search' : null, { q, limit: 50 });
  const r = /** @type {import('../api/types.js').SearchResults | null} */ (data);
  if (!q) return <p className="empty">{tr('Tapez un nom d’artiste, d’album ou de titre.')}</p>;
  if (!r) return <p className="empty">{loading ? tr('Recherche…') : ''}</p>;
  return (
    <section>
      <h1>« {q} »</h1>
      {r.artists.length + r.albums.length + r.tracks.length === 0 ? <p className="empty">{tr('Aucun résultat.')}</p> : null}
      {r.artists.length ? <><h2>{tr('Artistes')}</h2><ul className="artist-list">{r.artists.map((a) => <li key={a.id}><Link to={`/artistes/${a.id}`}>{a.name}</Link></li>)}</ul></> : null}
      {r.albums.length ? <><h2>{tr('Albums')}</h2><AlbumGrid albums={r.albums} /></> : null}
      {r.tracks.length ? <><h2>{tr('Pistes')}</h2><TrackList tracks={r.tracks} showAlbum showCover /></> : null}
    </section>
  );
}
