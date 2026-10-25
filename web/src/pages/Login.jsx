// @ts-check
import { useEffect, useState } from 'react';
import { ApiError, request } from '../api/client.js';
import { login } from '../engine/session.js';
import { useSession } from '../hooks.js';
import { Logo } from '../components/Icon.jsx';
import { tr } from '../i18n/index.js';

const messages = () => /** @type {Record<string, string>} */ ({
  invalid_credentials: tr('Adresse e-mail ou mot de passe incorrect.'),
  account_pending: tr('Votre demande de compte attend l’approbation de l’administrateur.'),
  account_suspended: tr('Ce compte est suspendu.'),
  account_locked: tr('Trop d’essais : compte verrouillé quelques minutes.'),
  rate_limited: tr('Trop d’essais : réessayez dans quelques minutes.'),
  registration_closed: tr('Les inscriptions sont fermées.'),
  pending_limit: tr('Trop de demandes en attente depuis votre connexion : attendez qu’elles soient traitées.'),
});

/** @param {unknown} e */
function message(e) {
  if (e instanceof ApiError) return messages()[e.code] ?? e.message;
  return tr('Erreur inattendue.');
}

export function Login() {
  const { server, notice } = useSession();
  // Lien direct vers la demande de compte (« /?demande », à partager) : ouvert si les demandes le sont
  const [mode, setMode] = useState(/** @type {'login' | 'register' | 'sent' | 'forgot' | 'forgotSent'} */ (
    new URLSearchParams(window.location.search).has('demande') && server?.registrationOpen !== false ? 'register' : 'login'));
  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    if (!params.has('demande')) return;
    params.delete('demande');
    const rest = params.toString();
    window.history.replaceState(null, '', window.location.pathname + (rest ? `?${rest}` : '') + window.location.hash);
  }, []);
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [name, setName] = useState('');
  const [note, setNote] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  /** @param {import('react').FormEvent} e */
  const submit = async (e) => {
    e.preventDefault();
    setBusy(true);
    setError('');
    try {
      if (mode === 'login') {
        await login(email.trim(), password);
      } else if (mode === 'forgot') {
        await request('POST', '/auth/password/forgot', { body: { email: email.trim() } });
        setMode('forgotSent');
      } else {
        await request('POST', '/auth/register', { body: { email: email.trim(), displayName: name.trim(), password, message: note.trim() || undefined } });
        setMode('sent');
      }
    } catch (err) {
      setError(message(err));
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
        {notice && mode === 'login' ? <p className="login-notice" role="status">{notice}</p> : null}
        {mode === 'sent' || mode === 'forgotSent' ? (
          <>
            <p role="status">
              {mode === 'sent'
                ? tr('Demande envoyée. Vous pourrez vous connecter dès que l’administrateur l’aura approuvée.')
                : tr('Si un compte existe avec cette adresse, un lien pour choisir un nouveau mot de passe vient de lui être envoyé. Il est valable une heure.')}
            </p>
            <button type="button" className="link-btn" onClick={() => setMode('login')}>{tr('Retour à la connexion')}</button>
          </>
        ) : mode === 'forgot' ? (
          <>
            <p>{tr('Indiquez l’adresse de votre compte : vous recevrez un lien pour choisir un nouveau mot de passe.')}</p>
            <label>{tr('Adresse e-mail')}<input type="email" autoComplete="username" required value={email} onChange={(e) => setEmail(e.target.value)} /></label>
            {error ? <p className="form-error" role="alert">{error}</p> : null}
            <button type="submit" className="primary" disabled={busy}>{tr('Recevoir le lien')}</button>
            <button type="button" className="link-btn" onClick={() => { setError(''); setMode('login'); }}>{tr('Retour à la connexion')}</button>
          </>
        ) : (
          <>
            <label>{tr('Adresse e-mail')}<input type="email" autoComplete="username" required value={email} onChange={(e) => setEmail(e.target.value)} /></label>
            {mode === 'register' ? (
              <label>{tr('Votre nom')}<input required maxLength={100} value={name} onChange={(e) => setName(e.target.value)} /></label>
            ) : null}
            <label>{tr('Mot de passe')}
              <input type="password" autoComplete={mode === 'login' ? 'current-password' : 'new-password'} required minLength={mode === 'register' ? 10 : undefined}
                value={password} onChange={(e) => setPassword(e.target.value)} />
            </label>
            {mode === 'register' ? (
              <label>{tr('Un mot pour l’administrateur (facultatif)')}<textarea maxLength={1000} value={note} onChange={(e) => setNote(e.target.value)} /></label>
            ) : null}
            {error ? <p className="form-error" role="alert">{error}</p> : null}
            <button type="submit" className="primary" disabled={busy}>{mode === 'login' ? tr('Se connecter') : tr('Demander un compte')}</button>
            {mode === 'login' && server?.features.passwordReset ? (
              <button type="button" className="link-btn" onClick={() => { setError(''); setMode('forgot'); }}>{tr('Mot de passe oublié ?')}</button>
            ) : null}
            {server?.registrationOpen !== false ? (
              <button type="button" className="link-btn" onClick={() => setMode(mode === 'login' ? 'register' : 'login')}>
                {mode === 'login' ? tr('Pas encore de compte ? Faire une demande') : tr('J’ai déjà un compte')}
              </button>
            ) : null}
          </>
        )}
      </form>
    </main>
  );
}
