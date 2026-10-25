// @ts-check
// Page ouverte depuis le lien « mot de passe oublié » reçu par mail. Le jeton est retiré de la
// barre d'adresse dès l'ouverture (historique, capture d'écran) et ne vit plus qu'en mémoire.
import { useEffect, useState } from 'react';
import { ApiError, request } from '../api/client.js';
import { useSession } from '../hooks.js';
import { Logo } from '../components/Icon.jsx';
import { tr } from '../i18n/index.js';

const HOME = import.meta.env.BASE_URL;

export function ResetPassword() {
  const { server } = useSession();
  // Jeton dans le fragment (#jeton=…) : jamais envoyé au serveur ni écrit dans ses journaux
  const [token, setToken] = useState(() => new URLSearchParams(window.location.hash.slice(1)).get('jeton') ?? '');
  const [password, setPassword] = useState('');
  const [again, setAgain] = useState('');
  const [state, setState] = useState(/** @type {'form' | 'done' | 'invalid'} */ (token ? 'form' : 'invalid'));
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (window.location.search || window.location.hash) window.history.replaceState(null, '', window.location.pathname);
    // Un autre lien ouvert dans le même onglet : seul le fragment change, la page n'est pas rechargée
    const onHash = () => {
      const t = new URLSearchParams(window.location.hash.slice(1)).get('jeton');
      if (!t) return;
      window.history.replaceState(null, '', window.location.pathname);
      setToken(t);
      setPassword('');
      setAgain('');
      setError('');
      setState('form');
    };
    window.addEventListener('hashchange', onHash);
    return () => window.removeEventListener('hashchange', onHash);
  }, []);

  /** @param {import('react').FormEvent} e */
  const submit = async (e) => {
    e.preventDefault();
    setError('');
    if (password !== again) {
      setError(tr('Les deux mots de passe ne sont pas identiques.'));
      return;
    }
    setBusy(true);
    try {
      await request('POST', '/auth/password/reset', { body: { token, newPassword: password } });
      setState('done');
    } catch (err) {
      if (err instanceof ApiError && err.code === 'invalid_token') setState('invalid');
      else setError(err instanceof ApiError ? err.message : tr('Erreur inattendue.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <main className="login">
      <form className="login-card" onSubmit={submit}>
        <div className="login-brand">
          <Logo size={64} />
          <h1>{server?.name ?? 'Rengaine'}</h1>
          <p className="tagline">{tr('Votre musique, en boucle.')}</p>
        </div>
        {state === 'done' ? (
          <>
            <p role="status">{tr('Mot de passe changé. Par sécurité, vos appareils ont été déconnectés : reconnectez-vous avec le nouveau mot de passe.')}</p>
            <a className="button primary" href={HOME}>{tr('Se connecter')}</a>
          </>
        ) : state === 'invalid' ? (
          <>
            <p role="alert">{tr('Ce lien a expiré ou a déjà servi. Demandez-en un nouveau avec « Mot de passe oublié ? » sur la page de connexion.')}</p>
            <a className="button" href={HOME}>{tr('Page de connexion')}</a>
          </>
        ) : (
          <>
            <p>{tr('Choisissez un nouveau mot de passe (10 caractères au moins).')}</p>
            <label>{tr('Nouveau mot de passe')}
              <input type="password" autoComplete="new-password" required minLength={10} maxLength={200} value={password} onChange={(e) => setPassword(e.target.value)} />
            </label>
            <label>{tr('Encore une fois')}
              <input type="password" autoComplete="new-password" required minLength={10} maxLength={200} value={again} onChange={(e) => setAgain(e.target.value)} />
            </label>
            {error ? <p className="form-error" role="alert">{error}</p> : null}
            <button type="submit" className="primary" disabled={busy}>{tr('Changer le mot de passe')}</button>
          </>
        )}
      </form>
    </main>
  );
}
