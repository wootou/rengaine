// @ts-check
import { Cover } from '../components/Cover.jsx';
import { Favorite } from '../components/Favorite.jsx';
import { Icon } from '../components/Icon.jsx';
import { TrackList } from '../components/TrackList.jsx';
import { controller } from '../engine/controller.js';
import { openPicker } from '../engine/playlists.js';
import { formatTime } from '../engine/playback.js';
import { useApi, useSession } from '../hooks.js';
import { Link } from '../router.jsx';
import { tr, trn } from '../i18n/index.js';

/** @param {{ id: string }} props */
export function Album({ id }) {
  const { data, error, loading } = useApi(`/albums/${id}`);
  const admin = useSession().user?.role === 'admin';
  const album = /** @type {import('../api/types.js').AlbumDetail | null} */ (data);
  if (error) return <p className="empty">{error.status === 404 ? tr('Album introuvable.') : error.message}</p>;
  if (!album) return <p className="empty">{loading ? tr('Chargement…') : ''}</p>;
  const playable = album.tracks.filter((t) => t.available);
  return (
    <section className="album-page">
      <div className="album-head">
        <div className="album-backdrop" aria-hidden="true"><Cover url={album.coverUrl} size="s" /></div>
        <Cover url={album.coverUrl} size="l" alt={album.title} className="album-cover" />
        <div className="album-info">
          <span className="kind">{album.kind === 'loose' ? tr('Pistes en vrac') : album.isCompilation ? tr('Compilation') : tr('Album')}</span>
          <h1>{album.title}</h1>
          <p className="album-meta">
            {album.artist ? <Link to={`/artistes/${album.artist.id}`}>{album.artistName}</Link> : album.artistName}
            {album.year ? ` · ${album.year}` : ''} · {trn(album.trackCount, '{n} piste', '{n} pistes')} · {formatTime(album.durationMs)}
            {album.hasLossless ? <span className="tag format">FLAC</span> : null}
          </p>
          {album.genres.length ? <p className="genres">{album.genres.map((g) => <Link key={g.id} to={`/?genre=${g.id}`} className="tag">{g.name}</Link>)}</p> : null}
          <div className="album-actions">
            <button type="button" className="primary play-album" disabled={!playable.length} onClick={() => controller.playTracks(album.tracks, 0)}>
              <Icon name="play" size={22} /> <span className="btn-label">{tr('Écouter')}</span>
            </button>
            <button type="button" className="shuffle-album" disabled={!playable.length} onClick={() => controller.playTracks([...playable].sort(() => Math.random() - 0.5), 0)}>
              <Icon name="shuffle" size={20} /> <span className="btn-label">{tr('Au hasard')}</span>
            </button>
            <span className="album-tools">
              <button type="button" className="icon-btn boxed" disabled={!playable.length} onClick={() => controller.playNext(playable)} title={tr('Lire ensuite')}><Icon name="playNext" size={20} /></button>
              <button type="button" className="icon-btn boxed" disabled={!playable.length} onClick={() => controller.enqueue(playable)} title={tr('Ajouter à la file')}><Icon name="add" size={20} /></button>
              <button type="button" className="icon-btn boxed" disabled={!playable.length} onClick={() => openPicker(playable, tr('L’album {titre}', { titre: album.title }))} title={tr('Ajouter à une playlist')}><Icon name="playlistAdd" size={20} /></button>
              <Favorite type="album" id={album.id} initial={album.favorite} />
              {admin ? <Link to={`/albums/${album.id}/corriger`} className="icon-btn" title={tr('Corriger l’album')}><Icon name="edit" size={20} /></Link> : null}
            </span>
          </div>
        </div>
      </div>
      <TrackList tracks={album.tracks} />
    </section>
  );
}
