// @ts-check
// Liste consultée (album, recherche, artiste) : distincte de la file d'attente. Un clic sur une
// piste écoute la liste à partir de celle-ci ; les boutons l'ajoutent à la file sans couper.
// Ordinateur : les actions apparaissent au survol. Téléphone : un seul bouton « ⋮ » par piste
// déplie les actions sous la ligne (une ligne ouverte à la fois), dont « Album » quand la liste
// mélange plusieurs albums.
// Page d'une playlist : `itemKeys` (une piste peut y être deux fois) et `edit` (retirer, déplacer).
import { useState } from 'react';
import { controller } from '../engine/controller.js';
import { openPicker } from '../engine/playlists.js';
import { formatTime } from '../engine/playback.js';
import { usePlayerSelect } from '../hooks.js';
import { currentItem } from '../engine/queue.js';
import { Link } from '../router.jsx';
import { Cover } from './Cover.jsx';
import { Favorite } from './Favorite.jsx';
import { Icon } from './Icon.jsx';
import { tr } from '../i18n/index.js';

/** @typedef {import('../api/types.js').Track} Track */

/**
 * @param {{ tracks: Track[], showAlbum?: boolean, showCover?: boolean, numbered?: boolean, itemKeys?: number[],
 *   edit?: { onRemove: (index: number) => void, onMove: (index: number, delta: -1 | 1) => void, busy?: boolean } }} props
 */
export function TrackList({ tracks, showAlbum = false, showCover = false, numbered = true, itemKeys, edit }) {
  // Seulement la piste en cours et lecture/pause : pas la position (sinon toute la liste 4 fois par seconde)
  const playing = usePlayerSelect((s) => currentItem(s.queue)?.track.id ?? null);
  const isOn = usePlayerSelect((s) => s.playing);
  const [openId, setOpenId] = useState(/** @type {number | null} */ (null));
  // En-têtes « Disque n » : seulement pour un album en plusieurs disques, pas pour une liste mêlée
  // (aléatoire, recherche, playlist), où le numéro de disque d'une piste ne veut rien dire.
  const oneAlbum = new Set(tracks.map((t) => t.album.id)).size === 1;
  const multiDisc = oneAlbum && new Set(tracks.map((t) => t.discNumber)).size > 1;
  return (
    <ol className={`tracklist ${showCover ? 'with-cover' : ''}`}>
      {tracks.map((t, i) => {
        const discHeader = multiDisc && (i === 0 || tracks[i - 1].discNumber !== t.discNumber);
        const isPlaying = t.id === playing;
        const key = itemKeys?.[i] ?? t.id;
        const open = openId === key;
        return (
          <li key={key} className={`${isPlaying ? 'playing' : ''} ${t.available ? '' : 'unavailable'}`}>
            {discHeader ? <div className="disc-header">{tr('Disque {n}', { n: t.discNumber })}</div> : null}
            <div className={`track-row ${open ? 'actions-open' : ''}`} role="button" tabIndex={0}
              onClick={() => t.available && controller.playTracks(tracks, i)}
              onKeyDown={(e) => e.key === 'Enter' && t.available && controller.playTracks(tracks, i)}>
              {showCover ? <Cover url={t.coverUrl} size="s" className="track-cover" /> : null}
              {numbered && !showCover ? (
                <span className="track-num">
                  {isPlaying ? <span className={`eq ${isOn ? '' : 'paused'}`} aria-hidden="true"><i /><i /><i /></span> : null}
                  <span className="num">{t.trackNumber ?? '·'}</span>
                </span>
              ) : null}
              <span className="track-main">
                <span className="track-title">{t.title}</span>
                <span className="track-sub">
                  {t.artistCredit}
                  {showAlbum ? <> · <Link to={`/albums/${t.album.id}`}>{t.album.title}</Link></> : null}
                </span>
              </span>
              <span className="track-actions" onClick={(e) => e.stopPropagation()} onKeyDown={(e) => e.stopPropagation()}>
                <Favorite type="track" id={t.id} initial={t.favorite} label={tr('Favori')} />
                <button type="button" className="icon-btn" title={tr('Lire ensuite')} disabled={!t.available} onClick={() => controller.playNext([t])}>
                  <Icon name="playNext" size={20} /><span className="action-label" aria-hidden="true">{tr('Lire ensuite')}</span>
                </button>
                <button type="button" className="icon-btn" title={tr('Ajouter à la file')} disabled={!t.available} onClick={() => controller.enqueue([t])}>
                  <Icon name="add" size={20} /><span className="action-label" aria-hidden="true">{tr('À la file')}</span>
                </button>
                <button type="button" className="icon-btn" title={tr('Ajouter à une playlist')} disabled={!t.available} onClick={() => openPicker([t], t.title)}>
                  <Icon name="playlistAdd" size={20} /><span className="action-label" aria-hidden="true">{tr('Playlist')}</span>
                </button>
                {edit ? (
                  <>
                    <button type="button" className="icon-btn" title={tr('Monter')} disabled={edit.busy || i === 0} onClick={() => edit.onMove(i, -1)}>
                      <Icon name="chevronUp" size={20} /><span className="action-label" aria-hidden="true">{tr('Monter')}</span>
                    </button>
                    <button type="button" className="icon-btn" title={tr('Descendre')} disabled={edit.busy || i === tracks.length - 1} onClick={() => edit.onMove(i, 1)}>
                      <Icon name="chevronDown" size={20} /><span className="action-label" aria-hidden="true">{tr('Descendre')}</span>
                    </button>
                    <button type="button" className="icon-btn" title={tr('Retirer de la playlist')} disabled={edit.busy} onClick={() => edit.onRemove(i)}>
                      <Icon name="trash" size={20} /><span className="action-label" aria-hidden="true">{tr('Retirer')}</span>
                    </button>
                  </>
                ) : null}
                {showAlbum ? (
                  // Téléphone seulement : le lien de l'album sous le titre est trop petit pour le doigt
                  <Link to={`/albums/${t.album.id}`} className="icon-btn album-action" title={tr('Voir l’album {titre}', { titre: t.album.title })}>
                    <Icon name="disc" size={20} /><span className="action-label" aria-hidden="true">{tr('Album')}</span>
                  </Link>
                ) : null}
              </span>
              <span className="track-time">{formatTime(t.durationMs)}</span>
              <button type="button" className="icon-btn track-more" title={tr('Actions pour {titre}', { titre: t.title })} aria-expanded={open}
                onClick={(e) => {
                  e.stopPropagation();
                  setOpenId(open ? null : key);
                }}
                onKeyDown={(e) => e.stopPropagation()}>
                <Icon name="more" size={20} />
              </button>
            </div>
          </li>
        );
      })}
    </ol>
  );
}
