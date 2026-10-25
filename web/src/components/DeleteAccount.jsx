// @ts-check
// Suppression du compte (CPT-09) : explication, mot de passe, puis 15 secondes pour annuler
// (compte à rebours et bouton « Annuler », comme le contrat le demande aux clients). Rien n'est
// envoyé avant la fin du délai ; quitter la page pendant le délai annule.
import { useEffect, useRef, useState } from 'react';
import { ApiError, request } from '../api/client.js';
import { controller } from '../engine/controller.js';
import { accountDeleted } from '../engine/session.js';
import { tr } from '../i18n/index.js';

export const CANCEL_SECONDS = 15;

/** @param {unknown} e */
function message(e) {
  if (e instanceof ApiError) {
    if (e.code === 'invalid_credentials') return tr('Mot de passe incorrect : le compte n’a pas été supprimé.');
    if (e.code === 'last_admin') return tr('Vous êtes le dernier administrateur : nommez d’abord un autre admin.');
    return e.message;
  }
  return tr('Suppression impossible pour l’instant, réessayez.');
}

export function DeleteAccount() {
  const [step, setStep] = useState(/** @type {'idle' | 'confirm' | 'countdown' | 'sending'} */ ('idle'));
  const [password, setPassword] = useState('');
  const [left, setLeft] = useState(CANCEL_SECONDS);
  const [error, setError] = useState('');
  const timer = useRef(/** @type {ReturnType<typeof setInterval> | null} */ (null));
  const cancelBtn = useRef(/** @type {HTMLButtonElement | null} */ (null));

  const stop = () => {
    if (timer.current) clearInterval(timer.current);
    timer.current = null;
  };
  // Quitter la page pendant le délai : rien n'est supprimé
  useEffect(() => stop, []);

  const send = async () => {
    setStep('sending');
    try {
      await request('DELETE', '/me', { body: { password }, quiet401: true });
      controller.reset();
      accountDeleted();
    } catch (e) {
      setError(message(e));
      setStep('confirm');
    }
  };

  /** @param {import('react').FormEvent} e */
  const start = (e) => {
    e.preventDefault();
    setError('');
    setLeft(CANCEL_SECONDS);
    setStep('countdown');
    const end = Date.now() + CANCEL_SECONDS * 1000;
    timer.current = setInterval(() => {
      const s = Math.max(0, Math.ceil((end - Date.now()) / 1000));
      setLeft(s);
      if (s === 0) {
        stop();
        void send();
      }
    }, 250);
    setTimeout(() => cancelBtn.current?.focus(), 0);
  };

  const cancel = () => {
    stop();
    setStep('idle');
    setPassword('');
    setError('');
  };

  return (
    <div className="delete-account">
      <h3>{tr('Supprimer mon compte')}</h3>
      {step === 'idle' ? (
        <>
          <p className="hint">{tr('Le compte et toutes ses données sont effacés aussitôt, sans retour possible.')}</p>
          <button type="button" className="danger" onClick={() => setStep('confirm')}>{tr('Supprimer mon compte…')}</button>
        </>
      ) : step === 'confirm' ? (
        <form onSubmit={start}>
          <p>{tr('Seront effacés tout de suite et définitivement :')}</p>
          <ul>
            <li>{tr('vos playlists, vos favoris et votre historique d’écoute ;')}</li>
            <li>{tr('vos réglages et votre file d’attente ;')}</li>
            <li>{tr('vos appareils connectés et vos liens de télécommande.')}</li>
          </ul>
          <label>{tr('Votre mot de passe, pour confirmer')}
            <input type="password" autoComplete="current-password" required maxLength={200} value={password}
              onChange={(e) => setPassword(e.target.value)} />
          </label>
          {error ? <p className="form-error" role="alert">{error}</p> : null}
          <div className="actions">
            <button type="submit" className="danger solid" disabled={!password}>{tr('Supprimer définitivement')}</button>
            <button type="button" onClick={cancel}>{tr('Garder mon compte')}</button>
          </div>
        </form>
      ) : step === 'countdown' ? (
        <div>
          <p role="alert" className="sr-only">{tr('Votre compte sera supprimé dans {n} secondes. Bouton Annuler pour le garder.', { n: CANCEL_SECONDS })}</p>
          <p className="delete-countdown" aria-hidden="true">{tr('Suppression dans')} <strong>{left} s</strong>.</p>
          <div className="actions">
            <button type="button" ref={cancelBtn} className="primary" onClick={cancel}>{tr('Annuler')}</button>
          </div>
        </div>
      ) : (
        <p role="status">{tr('Suppression…')}</p>
      )}
    </div>
  );
}
