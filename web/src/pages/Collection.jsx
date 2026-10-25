// @ts-check
// Une collection d'albums (CAT-08), créée par l'admin et visible de tous.
import { get } from '../api/client.js';
import { AlbumGrid } from '../components/AlbumGrid.jsx';
import { Icon } from '../components/Icon.jsx';
import { controller } from '../engine/controller.js';
import { useApi, useDevice, useSession } from '../hooks.js';
import { Link } from '../router.jsx';
import { Mosaic } from './Playlists.jsx';
import { tr, trn } from '../i18n/index.js';

/** @param {{ id: string }} props */
export function Collection({ id }) {
  const { data, error } = useApi(`/collections/${id}`);
  const c = /** @type {import('../api/types.js').Schemas['CollectionDetail'] | null} */ (data);
  const admin = useSession().user?.role === 'admin';
  const dev = useDevice();
  if (error) return <p className="empty">{error.status === 404 ? tr('Collection introuvable.') : error.message}</p>;
  if (!c) return <p className="empty">{tr('Chargement…')}</p>;
  const filter = { collection: c.id };
  return (
    <section className="album-page">
      <div className="album-head">
        <div className="album-cover"><Mosaic urls={c.coverUrls} /></div>
        <div className="album-info">
          <span className="kind">{tr('Collection')}</span>
          <h1>{c.name}</h1>
          {c.description ? <p className="playlist-description">{c.description}</p> : null}
          <p className="album-meta">{trn(c.albumCount, '{n} album', '{n} albums')}</p>
          <div className="album-actions">
            <button type="button" className="primary" disabled={!c.albumCount} onClick={async () => {
              const r = /** @type {{ items: import('../api/types.js').Track[] }} */ (await get('/random/tracks', { query: { collection: c.id, count: 100 } }));
              controller.playTracks(r.items, 0);
            }}>
              <Icon name="shuffle" size={20} /> <span className="btn-label">{tr('Au hasard')}</span>
            </button>
            <span className="album-tools">
              <button type="button" className="icon-btn boxed" disabled={!c.albumCount} title={tr('Écoute infinie dans la collection')}
                onClick={() => void controller.startInfinite([], filter)}><Icon name="infinite" size={20} /></button>
              {admin ? <Link to={`/admin/collections?id=${c.id}`} className="icon-btn boxed" title={tr('Modifier la collection')}><Icon name="edit" size={20} /></Link> : null}
            </span>
          </div>
        </div>
      </div>
      {c.albums.length ? <AlbumGrid albums={c.albums} list={dev.albumsList} /> : <p className="empty">{tr('Collection vide.')}</p>}
    </section>
  );
}
