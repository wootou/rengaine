// @ts-check
import { Link } from '../router.jsx';
import { Cover } from './Cover.jsx';
import { formatLength } from '../pages/Playlists.jsx';
import { trn } from '../i18n/index.js';

/** @typedef {import('../api/types.js').AlbumSummary} AlbumSummary */

/**
 * Albums en grille de pochettes, ou en liste (une ligne par album : pochette, titre, artiste et année,
 * nombre de pistes et durée).
 * @param {{ albums: AlbumSummary[], list?: boolean }} props
 */
export function AlbumGrid({ albums, list = false }) {
  if (list) {
    return (
      <ul className="album-list">
        {albums.map((a) => (
          <li key={a.id}>
            <Link to={`/albums/${a.id}`} className="album-row" title={`${a.title} — ${a.artistName}`}>
              <Cover url={a.coverUrl} size="s" alt="" />
              <span className="album-row-text">
                <span className="album-title">{a.title}</span>
                <span className="album-artist">{a.artistName}{a.year ? ` · ${a.year}` : ''}</span>
              </span>
              <span className="album-row-meta">
                <span>{trn(a.trackCount, '{n} piste', '{n} pistes')}</span>
                <span>{formatLength(a.durationMs)}</span>
              </span>
            </Link>
          </li>
        ))}
      </ul>
    );
  }
  return (
    <ul className="album-grid">
      {albums.map((a) => (
        <li key={a.id}>
          <Link to={`/albums/${a.id}`} className="album-card" title={`${a.title} — ${a.artistName}`}>
            <Cover url={a.coverUrl} size="m" alt="" />
            <span className="album-title">{a.title}</span>
            <span className="album-artist">{a.artistName}{a.year ? ` · ${a.year}` : ''}</span>
          </Link>
        </li>
      ))}
    </ul>
  );
}
