// @ts-check
// Réglages > Télécommande partagée (FUT-02) : créer un lien pour un invité, le copier ou l'envoyer,
// voir les liens valables et les révoquer.
import { useEffect, useRef, useState } from 'react';
import { ApiError, request } from '../api/client.js';
import { remote } from '../engine/controller.js';
import { useApi } from '../hooks.js';
import { locale, tr } from '../i18n/index.js';

/** @typedef {import('../api/types.js').QueueShare} QueueShare */
/** @typedef {import('../api/types.js').QueueShareCreated} QueueShareCreated */

const durations = () => /** @type {const} */ ([[1, tr('1 heure')], [3, tr('3 heures')], [6, tr('6 heures')], [24, tr('24 heures')], [72, tr('3 jours')], [168, tr('7 jours')]]);

/** @param {QueueShare['rights']} r */
function rightsText(r) {
  const parts = [r.add ? tr('ajouter') : null, r.edit ? tr('déplacer, retirer') : null, r.control ? tr('lecture, pause, suivante') : null].filter(Boolean);
  return parts.length ? parts.join(' · ') : tr('voir seulement');
}

export function RemoteShares() {
  const list = useApi('/me/shares');
  const [label, setLabel] = useState('');
  const [hours, setHours] = useState(6);
  const [rights, setRights] = useState({ add: true, edit: true, control: true });
  const [created, setCreated] = useState(/** @type {QueueShareCreated | null} */ (null));
  const [status, setStatus] = useState('');
  const [busy, setBusy] = useState(false);
  const ref = useRef(/** @type {HTMLHeadingElement | null} */ (null));
  const items = /** @type {{ items: QueueShare[] } | null} */ (list.data)?.items ?? [];

  // Arrivée depuis la file (« Partager la file ») : on descend jusqu'ici
  useEffect(() => {
    if (window.location.hash === '#telecommande') ref.current?.scrollIntoView({ block: 'start' });
  }, []);

  const create = async () => {
    setBusy(true);
    setStatus('');
    try {
      const r = await request('POST', '/me/shares', { body: { label: label.trim() || undefined, hours, rights } });
      setCreated(/** @type {QueueShareCreated} */ (r.data));
      setLabel('');
      remote.setActive(true);
      list.reload();
    } catch (e) {
      setStatus(e instanceof ApiError ? e.message : tr('Création impossible'));
    } finally {
      setBusy(false);
    }
  };

  const copy = async () => {
    if (!created) return;
    try {
      await navigator.clipboard.writeText(created.url);
      setStatus(tr('Lien copié'));
    } catch {
      setStatus(tr('Copie impossible : sélectionnez le lien et copiez-le à la main'));
    }
  };

  /** @param {QueueShare} s */
  const revoke = async (s) => {
    await request('DELETE', `/me/shares/${s.id}`);
    if (created?.id === s.id) setCreated(null);
    setStatus(tr('Lien « {nom} » révoqué', { nom: s.label }));
    remote.setActive(items.length > 1);
    list.reload();
  };

  return (
    <>
      <h2 id="telecommande" ref={ref}>{tr('Télécommande partagée')}</h2>
      <p className="hint">{tr('Un lien à donner à un invité (pendant une soirée, en voiture…) : depuis son téléphone, sans compte, il voit ce qui joue et pilote votre file d’attente, jusqu’à l’heure choisie. La musique continue de jouer sur votre appareil.')}</p>
      <div className="share-form">
        <label>{tr('Pour qui')}
          <input type="text" maxLength={100} value={label} placeholder={tr('Invité')} onChange={(e) => setLabel(e.target.value)} />
        </label>
        <label>{tr('Valable')}
          <select value={hours} onChange={(e) => setHours(Number(e.target.value))}>
            {durations().map(([h, text]) => <option key={h} value={h}>{text}</option>)}
          </select>
        </label>
        <fieldset>
          <legend>{tr('L’invité peut')}</legend>
          <label className="check"><input type="checkbox" checked={rights.add} onChange={(e) => setRights({ ...rights, add: e.target.checked })} /> {tr('chercher et ajouter des morceaux')}</label>
          <label className="check"><input type="checkbox" checked={rights.edit} onChange={(e) => setRights({ ...rights, edit: e.target.checked })} /> {tr('déplacer et retirer des morceaux de la file')}</label>
          <label className="check"><input type="checkbox" checked={rights.control} onChange={(e) => setRights({ ...rights, control: e.target.checked })} /> {tr('mettre en pause, reprendre, passer à la suivante')}</label>
        </fieldset>
        <button type="button" onClick={() => void create()} disabled={busy}>{tr('Créer le lien')}</button>
      </div>
      <span className="status" aria-live="polite">{status}</span>
      {created ? (
        <div className="share-created">
          <label>{tr('Lien pour « {nom} » (affiché une seule fois)', { nom: created.label })}
            <input type="text" readOnly value={created.url} onFocus={(e) => e.target.select()} />
          </label>
          <div className="share-actions">
            <button type="button" onClick={() => void copy()}>{tr('Copier le lien')}</button>
            {typeof navigator.share === 'function' ? (
              <button type="button" onClick={() => void navigator.share({ title: tr('Télécommande'), text: tr('Pilote la musique depuis ton téléphone :'), url: created.url }).catch(() => {})}>{tr('Envoyer…')}</button>
            ) : null}
          </div>
        </div>
      ) : null}
      {items.length > 0 ? (
        <ul className="devices shares">
          {items.map((s) => (
            <li key={s.id}>
              <span>{s.label} <small>{rightsText(s.rights)} · {tr('jusqu’au {date}', { date: new Date(s.expiresAt).toLocaleString(locale(), { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) })}{s.lastUsedAt ? ` · ${tr('ouvert')}` : ` · ${tr('pas encore ouvert')}`}</small></span>
              <button type="button" className="link-btn" aria-label={tr('Révoquer le lien « {nom} »', { nom: s.label })} onClick={() => void revoke(s)}>{tr('Révoquer')}</button>
            </li>
          ))}
        </ul>
      ) : null}
    </>
  );
}
