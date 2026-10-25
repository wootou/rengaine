// @ts-check
// Fenêtre « Ajouter à une playlist » : mes playlists, ou une nouvelle. Une seule pour toute
// l'application (rendue dans App), ouverte par openPicker() depuis une piste, un album ou la file.
import { useEffect, useRef, useState, useSyncExternalStore } from 'react';
import { ApiError } from '../api/client.js';
import { addToPlaylist, closePicker, createPlaylist, loadMine, playlists } from '../engine/playlists.js';
import { Icon } from './Icon.jsx';
import { tr, trn } from '../i18n/index.js';

export function PlaylistPicker() {
  const { picker, mine } = useSyncExternalStore(playlists.subscribe, playlists.getState);
  const [name, setName] = useState('');
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState('');
  const [error, setError] = useState('');
  const box = useRef(/** @type {HTMLDivElement | null} */ (null));
  const opener = useRef(/** @type {Element | null} */ (null));

  useEffect(() => {
    if (!picker) return;
    opener.current = document.activeElement;
    setName('');
    setDone('');
    setError('');
    void loadMine().catch(() => {});
    box.current?.querySelector('input')?.focus();
    /** @param {KeyboardEvent} e */
    const onKey = (e) => e.key === 'Escape' && closePicker();
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('keydown', onKey);
      if (opener.current instanceof HTMLElement) opener.current.focus();
    };
  }, [picker]);

  if (!picker) return null;
  const n = picker.tracks.length;
  const ids = picker.tracks.map((t) => t.id);

  /** @param {() => Promise<{ name: string }>} fn */
  const run = async (fn) => {
    setBusy(true);
    setError('');
    try {
      const p = await fn();
      setDone(trn(n, 'Ajoutée à « {nom} »', '{n} pistes ajoutées à « {nom} »', { nom: p.name }));
      setTimeout(closePicker, 1200);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : tr('Échec de l’ajout'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="modal-backdrop" onClick={closePicker}>
      <div className="modal playlist-picker" role="dialog" aria-modal="true" aria-labelledby="picker-title" ref={box} onClick={(e) => e.stopPropagation()}>
        <div className="modal-head">
          <h2 id="picker-title">{tr('Ajouter à une playlist')}</h2>
          <button type="button" className="icon-btn" title={tr('Fermer')} onClick={closePicker}><Icon name="close" /></button>
        </div>
        <p className="hint">{picker.title}{n > 1 ? ` · ${trn(n, '{n} piste', '{n} pistes')}` : ''}</p>
        {done ? <p className="form-ok" role="status">{done}</p> : (
          <>
            <form className="picker-new" onSubmit={(e) => {
              e.preventDefault();
              const v = name.trim();
              if (v) void run(() => createPlaylist(v, ids));
            }}>
              <input type="text" aria-label={tr('Nom de la nouvelle playlist')} placeholder={tr('Nouvelle playlist…')} maxLength={200} value={name} onChange={(e) => setName(e.target.value)} />
              <button type="submit" className="primary" disabled={busy || !name.trim()}>{tr('Créer')}</button>
            </form>
            {mine === null ? <p className="hint">{tr('Chargement…')}</p> : mine.length ? (
              <ul className="picker-list">
                {mine.map((p) => (
                  <li key={p.id}>
                    <button type="button" disabled={busy} onClick={() => void run(() => addToPlaylist(p.id, ids))}>
                      <Icon name="playlist" size={20} />
                      <span>{p.name}<small className="block">{trn(p.trackCount, '{n} piste', '{n} pistes')}{p.shared ? ` · ${tr('partagée')}` : ''}</small></span>
                    </button>
                  </li>
                ))}
              </ul>
            ) : <p className="hint">{tr('Pas encore de playlist : donnez un nom à la première.')}</p>}
            {error ? <p className="form-error" role="alert">{error}</p> : null}
          </>
        )}
      </div>
    </div>
  );
}
