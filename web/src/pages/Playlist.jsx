// @ts-check
// Une playlist : écouter, et pour son créateur, renommer, partager, déplacer et retirer des pistes.
// Une playlist partagée par un autre compte se lit et se copie, mais ne se modifie pas.
import { useEffect, useState } from 'react';
import { ApiError, get } from '../api/client.js';
import { Icon } from '../components/Icon.jsx';
import { TrackList } from '../components/TrackList.jsx';
import { controller } from '../engine/controller.js';
import { createPlaylist, editPlaylist } from '../engine/playlists.js';
import { navigate } from '../router.jsx';
import { Mosaic, formatLength } from './Playlists.jsx';
import { tr, trn } from '../i18n/index.js';

/** @typedef {import('../engine/playlists.js').PlaylistDetail} PlaylistDetail */

/** @param {{ id: string }} props */
export function Playlist({ id }) {
  const [p, setP] = useState(/** @type {PlaylistDetail | null} */ (null));
  const [error, setError] = useState(/** @type {ApiError | null} */ (null));
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');
  const [editing, setEditing] = useState(false);

  useEffect(() => {
    let live = true;
    get(`/playlists/${id}`).then((d) => live && setP(/** @type {PlaylistDetail} */ (d)))
      .catch((e) => live && setError(e instanceof ApiError ? e : new ApiError(0, 'internal', String(e))));
    return () => {
      live = false;
    };
  }, [id]);

  if (error) return <p className="empty">{error.status === 404 ? tr('Playlist introuvable.') : error.message}</p>;
  if (!p) return <p className="empty">{tr('Chargement…')}</p>;

  const tracks = p.items.map((i) => i.track);
  const playable = tracks.filter((t) => t.available);
  const base = `/playlists/${p.id}`;

  /** @param {() => Promise<PlaylistDetail | null>} fn @param {string} [ok] */
  const change = async (fn, ok = '') => {
    setBusy(true);
    setMessage('');
    try {
      const next = await fn();
      if (next) setP(next);
      setMessage(ok);
    } catch (e) {
      setMessage(e instanceof ApiError ? e.message : tr('Échec de la modification'));
    } finally {
      setBusy(false);
    }
  };

  /** @param {number} index @param {-1 | 1} delta */
  const move = (index, delta) => {
    const ids = p.items.map((i) => i.id);
    const [it] = ids.splice(index, 1);
    ids.splice(index + delta, 0, it);
    void change(() => editPlaylist('PUT', `${base}/items`, { itemIds: ids }));
  };

  return (
    <section className="album-page playlist-page">
      <div className="album-head">
        <div className="album-cover"><Mosaic urls={p.coverUrls} /></div>
        <div className="album-info">
          <span className="kind">{p.mine ? (p.shared ? tr('Playlist partagée') : tr('Playlist privée')) : tr('Playlist de {nom}', { nom: p.owner.displayName })}</span>
          <h1>{p.name}</h1>
          {p.description ? <p className="playlist-description">{p.description}</p> : null}
          <p className="album-meta">{trn(p.trackCount, '{n} piste', '{n} pistes')}{p.trackCount ? ` · ${formatLength(p.durationMs)}` : ''}</p>
          <div className="album-actions">
            <button type="button" className="primary play-album" disabled={!playable.length} onClick={() => controller.playTracks(tracks, 0)}>
              <Icon name="play" size={22} /> <span className="btn-label">{tr('Écouter')}</span>
            </button>
            <button type="button" className="shuffle-album" disabled={!playable.length} onClick={() => controller.playTracks([...playable].sort(() => Math.random() - 0.5), 0)}>
              <Icon name="shuffle" size={20} /> <span className="btn-label">{tr('Au hasard')}</span>
            </button>
            <span className="album-tools">
              <button type="button" className="icon-btn boxed" disabled={!playable.length} onClick={() => controller.playNext(playable)} title={tr('Lire ensuite')}><Icon name="playNext" size={20} /></button>
              <button type="button" className="icon-btn boxed" disabled={!playable.length} onClick={() => controller.enqueue(playable)} title={tr('Ajouter à la file')}><Icon name="add" size={20} /></button>
              {p.mine ? (
                <button type="button" className="icon-btn boxed" aria-expanded={editing} onClick={() => setEditing(!editing)} title={tr('Modifier la playlist')}><Icon name="edit" size={20} /></button>
              ) : (
                <button type="button" className="icon-btn boxed" disabled={busy} title={tr('Copier dans mes playlists')} onClick={async () => {
                  setBusy(true);
                  try {
                    const copy = await createPlaylist(tr('{nom} (copie)', { nom: p.name }), tracks.map((t) => t.id), { description: p.description });
                    navigate(`/playlists/${copy.id}`);
                  } catch (e) {
                    setMessage(e instanceof ApiError ? e.message : tr('Copie impossible'));
                    setBusy(false);
                  }
                }}><Icon name="playlistAdd" size={20} /></button>
              )}
            </span>
          </div>
          {p.mine ? (
            <label className="check share-toggle">
              <input type="checkbox" className="switch" checked={p.shared}
                onChange={(e) => {
                  const shared = e.target.checked;
                  setP({ ...p, shared }); // tout de suite ; la réponse du serveur fait foi
                  void change(() => editPlaylist('PATCH', base, { shared }).catch((err) => {
                    setP((cur) => (cur ? { ...cur, shared: !shared } : cur));
                    throw err;
                  }),
                    shared ? tr('Partagée : tous les comptes la voient et l’écoutent (vous seul la modifiez).') : tr('Redevenue privée.'));
                }} /> {tr('Partager avec tous les comptes')}
            </label>
          ) : null}
          {message ? <p className="form-ok" role="status">{message}</p> : null}
        </div>
      </div>

      {editing && p.mine ? (
        <form className="playlist-edit card" onSubmit={(e) => {
          e.preventDefault();
          const f = new FormData(e.currentTarget);
          void change(async () => {
            const next = await editPlaylist('PATCH', base, { name: String(f.get('name') ?? '').trim(), description: String(f.get('description') ?? '').trim() || null });
            setEditing(false);
            return next;
          }, tr('Enregistré'));
        }}>
          <label>{tr('Nom')}<input type="text" name="name" required maxLength={200} defaultValue={p.name} /></label>
          <label>{tr('Description')}<textarea name="description" maxLength={1000} rows={2} defaultValue={p.description ?? ''} /></label>
          <div className="form-actions">
            <button type="submit" className="primary" disabled={busy}>{tr('Enregistrer')}</button>
            <button type="button" className="danger" disabled={busy} onClick={async () => {
              if (!window.confirm(tr('Supprimer la playlist « {nom} » ? Les pistes restent dans la bibliothèque.', { nom: p.name }))) return;
              setBusy(true);
              try {
                await editPlaylist('DELETE', base);
                navigate('/playlists');
              } catch (e) {
                setMessage(e instanceof ApiError ? e.message : tr('Suppression impossible'));
                setBusy(false);
              }
            }}><Icon name="trash" size={18} /> {tr('Supprimer la playlist')}</button>
          </div>
        </form>
      ) : null}

      {tracks.length ? (
        <TrackList tracks={tracks} showAlbum showCover itemKeys={p.items.map((i) => i.id)}
          edit={p.mine ? { busy, onMove: move, onRemove: (i) => void change(() => editPlaylist('DELETE', `${base}/items/${p.items[i].id}`)) } : undefined} />
      ) : (
        <p className="empty">{tr('Playlist vide. Ajoutez des pistes avec le bouton')} <Icon name="playlistAdd" size={16} />{' '}
          {tr('d’une piste, d’un album ou de la file d’attente.')}</p>
      )}
    </section>
  );
}
