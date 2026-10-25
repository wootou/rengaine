// @ts-check
import { AlbumGrid } from '../components/AlbumGrid.jsx';
import { Cover } from '../components/Cover.jsx';
import { Favorite } from '../components/Favorite.jsx';
import { Icon } from '../components/Icon.jsx';
import { TrackList } from '../components/TrackList.jsx';
import { controller } from '../engine/controller.js';
import { useApi, useSession } from '../hooks.js';
import { Link } from '../router.jsx';
import { tr } from '../i18n/index.js';

/** @param {{ id: string }} props */
export function Artist({ id }) {
  const { data, error } = useApi(`/artists/${id}`);
  const tracks = /** @type {{ items: import('../api/types.js').Track[] } | null} */ (useApi('/tracks', { artist: id, limit: 500, sort: 'name' }).data);
  const artist = /** @type {import('../api/types.js').ArtistDetail | null} */ (data);
  const admin = useSession().user?.role === 'admin';
  if (error) return <p className="empty">{error.status === 404 ? tr('Artiste introuvable.') : error.message}</p>;
  if (!artist) return <p className="empty">{tr('Chargement…')}</p>;
  return (
    <section>
      <div className="page-head artist-head">
        {artist.imageUrl ? <Cover url={artist.imageUrl} size="m" alt={tr('Photo de {nom}', { nom: artist.name })} className="artist-photo" /> : null}
        <h1>{artist.name}</h1>
        <div className="album-actions">
          <button type="button" className="primary" disabled={!tracks?.items.length}
            onClick={() => tracks && controller.playTracks([...tracks.items].sort(() => Math.random() - 0.5), 0)}>
            <Icon name="shuffle" size={20} /> {tr('Écouter au hasard')}
          </button>
          <Favorite type="artist" id={artist.id} initial={artist.favorite} />
          {admin ? <Link to={`/artistes/${artist.id}/corriger`} className="icon-btn" title={tr('Corriger l’artiste')}><Icon name="edit" size={20} /></Link> : null}
        </div>
      </div>
      {artist.albums.length ? <><h2>{tr('Albums')}</h2><AlbumGrid albums={artist.albums} /></> : null}
      {artist.appearsOn.length ? <><h2>{tr('Apparaît sur')}</h2><AlbumGrid albums={artist.appearsOn} /></> : null}
      {tracks?.items.length ? <><h2>{tr('Pistes')}</h2><TrackList tracks={tracks.items} showAlbum showCover /></> : null}
    </section>
  );
}
