// @ts-check
// Historique d'écoute (2.26.0) : les écoutes du compte, de la plus récente à la plus ancienne,
// groupées par jour. Chacune s'efface, et tout l'historique d'un coup (après confirmation).
import { useState } from 'react';
import { ApiError, request } from '../api/client.js';
import { Cover } from '../components/Cover.jsx';
import { Icon } from '../components/Icon.jsx';
import { controller } from '../engine/controller.js';
import { formatTime } from '../engine/playback.js';
import { usePaged, useSentinel } from '../hooks.js';
import { locale, tr, trn } from '../i18n/index.js';

/** @typedef {import('../api/types.js').Schemas['PlayHistoryPage']['items'][number]} Play */

/** « Aujourd'hui », « Hier », sinon la date en toutes lettres. @param {Date} d */
function dayLabel(d) {
  const start = (/** @type {Date} */ x) => new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime();
  const days = Math.round((start(new Date()) - start(d)) / 86_400_000);
  if (days === 0) return tr('Aujourd’hui');
  if (days === 1) return tr('Hier');
  return d.toLocaleDateString(locale(), { weekday: 'long', day: 'numeric', month: 'long', year: d.getFullYear() === new Date().getFullYear() ? undefined : 'numeric' });
}

export function History() {
  const { items, total, loading, loadMore, done } = usePaged('/me/history', {}, 100);
  const sentinel = useSentinel(loadMore, !done && !loading);
  const [removed, setRemoved] = useState(() => new Set());
  const [cleared, setCleared] = useState(false);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const plays = cleared ? [] : /** @type {Play[]} */ (items).filter((p) => !removed.has(p.id));
  const left = cleared ? 0 : total === null ? null : total - removed.size;

  /** @param {Play} p */
  const remove = async (p) => {
    setError('');
    try {
      await request('DELETE', `/me/history/${p.id}`);
      setRemoved((r) => new Set(r).add(p.id));
    } catch (e) {
      // Déjà effacée ailleurs (autre appareil) : on la retire aussi de la liste
      if (e instanceof ApiError && e.status === 404) setRemoved((r) => new Set(r).add(p.id));
      else setError(tr('Écoute non effacée : vérifiez la connexion.'));
    }
  };

  const clearAll = async () => {
    if (!window.confirm(tr('Effacer tout votre historique d’écoute ? C’est définitif.'))) return;
    setBusy(true);
    setError('');
    try {
      await request('DELETE', '/me/history');
      setCleared(true);
    } catch {
      setError(tr('Historique non effacé : vérifiez la connexion.'));
    } finally {
      setBusy(false);
    }
  };

  /** @type {{ label: string, plays: Play[] }[]} */
  const groups = [];
  for (const p of plays) {
    const label = dayLabel(new Date(p.startedAt));
    if (groups.at(-1)?.label !== label) groups.push({ label, plays: [] });
    groups.at(-1)?.plays.push(p);
  }

  return (
    <section className="history">
      <div className="page-head">
        <div>
          <h1>{tr('Historique d’écoute')}</h1>
          {left ? <p className="meta">{trn(left, '{n} écoute', '{n} écoutes')}</p> : null}
        </div>
        {left ? (
          <div className="album-actions">
            <button type="button" disabled={busy} onClick={() => void clearAll()}><Icon name="trash" size={20} /> {tr('Tout effacer')}</button>
          </div>
        ) : null}
      </div>
      <p className="hint">{tr('Une piste compte dès 5 secondes écoutées. Vous seul voyez votre historique ; la lecture continue s’en sert pour ne pas rejouer trop vite les mêmes pistes.')}</p>
      {error ? <p className="form-error" role="alert">{error}</p> : null}
      {left === 0 ? <p className="empty">{cleared ? tr('Historique effacé.') : tr('Aucune écoute pour l’instant.')}</p> : null}
      {groups.map((g) => (
        <div key={g.label} className="history-day">
          <h2>{g.label}</h2>
          <ol className="history-list">
            {g.plays.map((p) => (
              <li key={p.id} className={p.track.available ? '' : 'unavailable'}>
                <button type="button" className="history-play" disabled={!p.track.available} onClick={() => controller.playTracks([p.track], 0)}
                  title={tr('Écouter')}>
                  <Cover url={p.track.coverUrl} size="s" />
                  <span className="history-text">
                    <span className="history-title">{p.track.title}</span>
                    <small>{p.track.artistCredit} · {p.track.album.title}</small>
                  </span>
                </button>
                <small className="history-meta">
                  <time dateTime={p.startedAt}>{new Date(p.startedAt).toLocaleTimeString(locale(), { hour: '2-digit', minute: '2-digit' })}</time>
                  {p.completed ? null : <> · {tr('passée à {t}', { t: formatTime(p.playedMs) })}</>}
                  {p.device ? <span className="history-device"> · {p.device}</span> : null}
                </small>
                <button type="button" className="icon-btn" title={tr('Effacer cette écoute')} aria-label={tr('Effacer l’écoute de « {titre} »', { titre: p.track.title })}
                  onClick={() => void remove(p)}><Icon name="close" size={18} /></button>
              </li>
            ))}
          </ol>
        </div>
      ))}
      {!done && !cleared ? <div ref={sentinel} className="sentinel">{loading ? <p className="empty">{tr('Chargement…')}</p> : null}</div> : null}
    </section>
  );
}
