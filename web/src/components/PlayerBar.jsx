// @ts-check
import { useEffect, useRef, useState } from 'react';
import { controller } from '../engine/controller.js';
import { djLabel } from '../engine/dj.js';
import { formatTime } from '../engine/playback.js';
import { currentItem } from '../engine/queue.js';
import { useDevice, usePlayer, useSession } from '../hooks.js';
import { Link, useRoute } from '../router.jsx';
import { Cover } from './Cover.jsx';
import { Favorite } from './Favorite.jsx';
import { Icon } from './Icon.jsx';
import { Waveform } from './Waveform.jsx';
import { tr } from '../i18n/index.js';

const NEXT_MODE = /** @type {const} */ ({ normal: 'repeat_all', repeat_all: 'repeat_one', repeat_one: 'normal', continuous: 'normal' });
const modeLabel = () => ({ normal: tr('Répétition désactivée'), repeat_all: tr('Répéter la file'), repeat_one: tr('Répéter la piste'), continuous: tr('Lecture continue') });

/** @param {{ onToggleQueue: () => void, queueOpen: boolean }} props */
export function PlayerBar({ onToggleQueue, queueOpen }) {
  const s = usePlayer();
  const cur = currentItem(s.queue)?.track ?? null;
  const bar = useRef(/** @type {HTMLDivElement | null} */ (null));
  const [dragMs, setDragMs] = useState(/** @type {number | null} */ (null));
  const duration = s.durationMs || cur?.durationMs || 0;
  const pos = dragMs ?? s.positionMs;
  const server = useSession().server;
  const dev = useDevice();
  const waveform = Boolean(server?.features.analysis.waveform) && dev.waveform;
  // Téléphone : la barre se déplie en vue « en cours de lecture » plein écran (même composant,
  // mêmes contrôles : rien n'est dupliqué, les libellés restent uniques pour les tests).
  const [expanded, setExpanded] = useState(false);
  const open = expanded && cur !== null;
  const path = useRoute().path;
  useEffect(() => setExpanded(false), [path]); // un lien suivi depuis la vue dépliée la referme

  useEffect(() => {
    document.body.classList.toggle('np-open', open);
    return () => document.body.classList.remove('np-open');
  }, [open]);

  /** @param {{ clientX: number }} e */
  const msAt = (e) => {
    const r = bar.current?.getBoundingClientRect();
    if (!r || r.width === 0) return 0;
    return Math.max(0, Math.min(1, (e.clientX - r.left) / r.width)) * duration;
  };

  return (
    <footer className={`player-bar ${open ? 'expanded' : ''} ${s.playing ? 'is-playing' : ''}`} aria-label={tr('Lecteur')}
      onKeyDown={(e) => open && e.key === 'Escape' && setExpanded(false)}>
      {open ? <div className="np-backdrop" aria-hidden="true"><Cover url={cur.coverUrl} size="s" /></div> : null}
      {open ? (
        <div className="np-head">
          <button type="button" className="icon-btn" title={tr('Réduire le lecteur')} onClick={() => setExpanded(false)}><Icon name="chevronDown" size={28} /></button>
          <Link to={`/albums/${cur.album.id}`} className="np-from"><small>{tr('En lecture')}</small><span className="np-album">{cur.album.title}</span></Link>
        </div>
      ) : null}
      <div className="now-playing">
        {cur ? (
          <>
            <Link to={`/albums/${cur.album.id}`} className="np-cover"><Cover url={cur.coverUrl} size={open ? 'l' : 's'} alt={cur.album.title} /></Link>
            <div className="np-text">
              <span className="np-title">{cur.title}</span>
              <span className="np-artist">{cur.artistCredit}</span>
              {djLabel(cur.playback) ? <span className="np-dj">{djLabel(cur.playback)}</span> : null}
            </div>
            {open ? <Favorite key={cur.id} type="track" id={cur.id} initial={cur.favorite} /> : null}
            {!open ? <button type="button" className="np-expand" title={tr('Ouvrir le lecteur')} onClick={() => setExpanded(true)} /> : null}
          </>
        ) : (
          <span className="np-empty">{tr('Rien en lecture')}</span>
        )}
      </div>

      <div className="controls">
        <div className="buttons">
          <button type="button" className="icon-btn" title={modeLabel()[s.queue.mode]} aria-pressed={s.queue.mode !== 'normal'}
            onClick={() => controller.setMode(NEXT_MODE[s.queue.mode])}>
            <Icon name={s.queue.mode === 'repeat_one' ? 'repeatOne' : 'repeat'} />
          </button>
          <button type="button" className="icon-btn" title={tr('Précédente')} onClick={() => controller.previous()} disabled={!cur}><Icon name="prev" /></button>
          <button type="button" className="play-btn" title={s.playing ? tr('Pause') : tr('Lecture')} onClick={() => controller.togglePlay()} disabled={!cur}>
            <Icon name={s.playing ? 'pause' : 'play'} size={open ? 36 : 28} />
          </button>
          <button type="button" className="icon-btn" title={tr('Suivante')} onClick={() => controller.next(true)} disabled={!cur}><Icon name="next" /></button>
          <button type="button" className={`icon-btn ${s.infinite.enabled ? 'active' : ''}`} aria-pressed={s.infinite.enabled}
            title={s.infinite.enabled ? tr('Mode infini : la file se complète toute seule') : tr('Activer le mode infini')}
            onClick={() => controller.setInfinite(!s.infinite.enabled, s.infinite.genreIds, s.infinite.filter)}>
            <Icon name="infinite" />
          </button>
        </div>
        <div className="progress">
          <span className="time">{formatTime(pos)}</span>
          <div
            ref={bar}
            className="progress-bar"
            role="slider"
            tabIndex={0}
            aria-label={tr('Position')}
            aria-valuemin={0}
            aria-valuemax={Math.round(duration / 1000)}
            aria-valuenow={Math.round(pos / 1000)}
            onPointerDown={(e) => {
              if (!cur) return;
              e.currentTarget.setPointerCapture(e.pointerId);
              setDragMs(msAt(e));
            }}
            onPointerMove={(e) => dragMs !== null && setDragMs(msAt(e))}
            onPointerUp={(e) => {
              if (dragMs === null) return;
              controller.seek(msAt(e));
              setDragMs(null);
            }}
            onKeyDown={(e) => {
              if (e.key === 'ArrowRight') controller.seek(s.positionMs + 10000);
              if (e.key === 'ArrowLeft') controller.seek(s.positionMs - 10000);
            }}
          >
            {waveform && cur ? <Waveform trackId={cur.id} progress={duration > 0 ? pos / duration : 0} /> : null}
            <div className="progress-fill" style={{ width: duration > 0 ? `${(pos / duration) * 100}%` : '0%' }} />
          </div>
          <span className="time">{formatTime(duration)}</span>
        </div>
      </div>

      <div className="extra">
        {s.error ? <span className="player-error" role="status">{s.error}</span> : null}
        <div className="volume">
          <button type="button" className="icon-btn" title={s.muted ? tr('Remettre le son') : tr('Couper le son')} aria-pressed={s.muted}
            onClick={() => controller.toggleMute()}>
            <Icon name={s.muted || s.volume === 0 ? 'volumeOff' : 'volume'} />
          </button>
          <input type="range" min={0} max={100} step={1} aria-label={tr('Volume')} title={tr('Volume : {v} % du volume visé', { v: Math.round(s.volume * 100) })}
            value={s.muted ? 0 : Math.round(s.volume * 100)}
            onChange={(e) => controller.setVolume(Number(e.target.value) / 100)} />
        </div>
        <button type="button" className={`icon-btn queue-btn ${queueOpen ? 'active' : ''}`} title={tr("File d'attente")} aria-expanded={queueOpen}
          onClick={() => {
            setExpanded(false);
            onToggleQueue();
          }}>
          <Icon name="queue" />
          {s.queue.items.length > 0 ? <span className="badge">{s.queue.items.length}</span> : null}
        </button>
      </div>
    </footer>
  );
}
