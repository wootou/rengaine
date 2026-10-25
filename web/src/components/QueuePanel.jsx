// @ts-check
// Vue de la file d'attente : un seul état (celui du contrôleur), redessiné à chaque changement.
import { useState, useSyncExternalStore } from 'react';
import { controller, remote } from '../engine/controller.js';
import { navigate } from '../router.jsx';
import { usePlayerSelect, useSession } from '../hooks.js';
import { openPicker } from '../engine/playlists.js';
import { formatTime } from '../engine/playback.js';
import { currentIndex } from '../engine/queue.js';
import { Cover } from './Cover.jsx';
import { Icon } from './Icon.jsx';
import { tr, trn } from '../i18n/index.js';

/** @param {{ onClose: () => void }} props */
export function QueuePanel({ onClose }) {
  // Pas la position : la file entière serait redessinée quatre fois par seconde
  const queue = usePlayerSelect((p) => p.queue);
  const infinite = usePlayerSelect((p) => p.infinite);
  const playing = usePlayerSelect((p) => p.playing);
  const s = { queue, infinite, playing };
  const cur = currentIndex(s.queue);
  const [dragFrom, setDragFrom] = useState(/** @type {number | null} */ (null));
  const link = useSyncExternalStore(remote.store.subscribe, remote.store.getState);
  const { server } = useSession();
  const totalMs = s.queue.items.slice(Math.max(cur, 0)).reduce((n, it) => n + it.track.durationMs, 0);

  return (
    <aside className="queue-panel" aria-label={tr("File d'attente")}>
      <header>
        <div className="queue-heading">
          <h2>{tr("File d'attente")}</h2>
          <span className="queue-info">{trn(s.queue.items.length, '{n} piste · {duree} restantes', '{n} pistes · {duree} restantes', { duree: formatTime(totalMs) })}</span>
        </div>
        <button type="button" className="icon-btn" title={tr('Fermer')} onClick={onClose}><Icon name="close" /></button>
      </header>
      <label className={`infinite-card ${s.infinite.enabled ? 'on' : ''}`}>
        <Icon name="infinite" size={26} />
        <span className="infinite-text">
          <strong>{tr('Mode infini')}</strong>
          <small>{s.infinite.enabled
            ? (s.infinite.genreIds.length ? tr('Genres choisis') : tr('Genre de la piste en cours'))
            : tr('La file se complète toute seule quand elle se termine')}</small>
        </span>
        <input type="checkbox" className="switch" checked={s.infinite.enabled} onChange={(e) => controller.setInfinite(e.target.checked, s.infinite.genreIds)} />
      </label>
      <div className="queue-tools">
        <span>{tr('À suivre')}</span>
        <span className="queue-tool-btns">
          <button type="button" className="icon-btn" aria-label={tr('Enregistrer en playlist')} title={tr('Enregistrer la file en playlist')}
            onClick={() => openPicker(s.queue.items.map((it) => it.track), tr('La file d’attente'))} disabled={!s.queue.items.length}><Icon name="playlistAdd" /></button>
          <button type="button" className="icon-btn" aria-label={tr('Vider la suite')} title={tr('Vider la suite (garde la piste en cours)')}
            onClick={() => controller.clearUpcoming()} disabled={s.queue.items.length <= 1}><Icon name="clearAll" /></button>
          {server?.features.remoteControl ? (
            <button type="button" className={`icon-btn ${link.sharesActive ? 'on' : ''}`} onClick={() => navigate('/reglages#telecommande')}
              aria-label={link.sharesActive ? tr('Télécommande partagée') : tr('Partager la file')}
              title={link.sharesActive ? tr('Télécommande partagée : un lien est actif') : tr('Partager la file : un lien pour la piloter depuis un autre téléphone')}><Icon name="share" /></button>
          ) : null}
        </span>
      </div>
      {link.lastAction ? <p className="remote-note" role="status">{link.lastAction}</p> : null}
      {s.queue.items.length === 0 ? (
        <p className="empty">{tr('La file est vide. Ouvrez un album et choisissez une piste, ou ajoutez-en avec')} <Icon name="add" size={16} />.</p>
      ) : (
        <ol className="queue-list">
          {s.queue.items.map((it, i) => (
            <li
              key={it.key}
              className={`${i === cur ? 'current' : ''} ${i < cur ? 'past' : ''}`}
              draggable
              onDragStart={() => setDragFrom(i)}
              onDragOver={(e) => e.preventDefault()}
              onDrop={() => {
                if (dragFrom !== null) controller.move(dragFrom, i);
                setDragFrom(null);
              }}
            >
              <button type="button" className="queue-item" onClick={() => controller.jumpTo(it.key)}>
                <Cover url={it.track.coverUrl} size="s" />
                <span className="track-main">
                  <span className="track-title">{it.track.title}</span>
                  <span className="track-sub">{it.track.artistCredit}</span>
                </span>
                {i === cur
                  ? <span className={`eq ${s.playing ? '' : 'paused'}`} aria-hidden="true"><i /><i /><i /></span>
                  : <span className="track-time">{formatTime(it.track.durationMs)}</span>}
              </button>
              <button type="button" className="icon-btn" title={tr('Retirer de la file')} onClick={() => controller.remove(it.key)}>
                <Icon name="close" size={18} />
              </button>
            </li>
          ))}
        </ol>
      )}
      {s.infinite.loading ? <p className="empty queue-loading" role="status">{tr('Le mode infini cherche des pistes…')}</p> : null}
    </aside>
  );
}
