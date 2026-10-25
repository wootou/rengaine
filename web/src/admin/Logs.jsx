// @ts-check
// Admin > Journal (JRN-07) : les actions des comptes (audit, en base), et les journaux techniques du
// serveur (fichiers) : site et API, tâches de fond, sécurité. Du plus récent au plus ancien.
import { Fragment, useEffect, useState } from 'react';
import { ApiError, get } from '../api/client.js';
import { useDebounced } from '../hooks.js';
import { Link, useRoute } from '../router.jsx';
import { Audit } from './Activity.jsx';
import { labels, locale, tr } from '../i18n/index.js';

/** @typedef {import('../api/types.js').Schemas} Schemas */
/** @typedef {Schemas['LogEntry']} LogEntry */

const kinds = () => /** @type {const} */ ([['actions', tr('Actions')], ['api', tr('Site et API')], ['cli', tr('Tâches de fond')], ['securite', tr('Sécurité')]]);

const LEVEL = /** @type {Record<Schemas['LogLevel'], string>} */ (labels(() => ({ debug: tr('Détail'), info: tr('Info'), warning: tr('Attention'), error: tr('Erreur'), critical: tr('Critique') })));
const LEVEL_CLASS = { debug: '', info: '', warning: 'job-queued', error: 'job-failed', critical: 'job-failed' };

/** Événements du journal de sécurité. */
const EVENT = /** @type {Record<string, string>} */ (labels(() => ({
  login_failed: tr('Échec de connexion'), login_locked: tr('Connexion refusée (compte verrouillé)'), account_locked: tr('Compte verrouillé'),
  token_invalid: tr('Jeton invalide'), csrf_invalid: tr('Jeton anti-CSRF invalide'), pair_failed: tr('Appairage refusé'),
  password_change_failed: tr('Changement de mot de passe refusé'), password_forgot_limit: tr('Trop de demandes de mot de passe'),
  password_forgot_unknown: tr('Mot de passe oublié : adresse inconnue'), password_reset_invalid: tr('Lien de mot de passe invalide'),
  register_existing: tr('Demande de compte : adresse déjà connue'), register_pending_limit: tr('Trop de demandes de compte (réseau)'),
  rate_limited: tr('Trop de requêtes'),
})));

/** Y a-t-il quelque chose à déplier ? (sécurité : l'IP est déjà dans sa colonne) @param {LogEntry} e @param {string} source */
function hasDetails(e, source) {
  if (!e.context) return false;
  return source !== 'securite' || Object.keys(e.context).some((k) => k !== 'ip');
}

/** Date avec les secondes. @param {string} iso */
function formatStamp(iso) {
  return new Date(iso).toLocaleString(locale(), { dateStyle: 'short', timeStyle: 'medium' });
}

export function Journal() {
  const { query } = useRoute();
  const kind = query.get('type') ?? 'actions';
  return (
    <>
      <nav className="tabs subtabs" aria-label={tr('Journaux')}>
        {kinds().map(([k, label]) => (
          <Link key={k} to={k === 'actions' ? '/admin/journal' : `/admin/journal?type=${k}`} className={kind === k ? 'current' : ''}
            aria-current={kind === k ? 'page' : undefined}>{label}</Link>
        ))}
      </nav>
      {kind === 'api' || kind === 'cli' || kind === 'securite' ? <TechLogs key={kind} source={kind} /> : <Audit />}
    </>
  );
}

/** @param {{ source: 'api' | 'cli' | 'securite' }} props */
function TechLogs({ source }) {
  const [level, setLevel] = useState(source === 'securite' ? '' : 'warning');
  const [text, setText] = useState('');
  const q = useDebounced(text.trim(), 300);
  const [requestId, setRequestId] = useState(/** @type {string | null} */ (null));
  const [items, setItems] = useState(/** @type {LogEntry[]} */ ([]));
  const [next, setNext] = useState(/** @type {string | null} */ (null));
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [open, setOpen] = useState(/** @type {number | null} */ (null));

  /** @param {string | null} cursor @param {boolean} append */
  const load = async (cursor, append) => {
    setLoading(true);
    setError('');
    try {
      const page = /** @type {Schemas['LogPage']} */ (await get('/admin/logs', {
        query: { source, level: level || undefined, q: q || undefined, requestId: requestId ?? undefined, cursor: cursor ?? undefined, limit: 100 },
      }));
      setItems((cur) => (append ? [...cur, ...page.items] : page.items));
      setNext(page.next);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : tr('Lecture impossible'));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    setOpen(null);
    void load(null, false);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [source, level, q, requestId]);

  return (
    <section className="logs">
      <div className="page-head">
        <h2 className="admin-title">{kinds().find(([k]) => k === source)?.[1]} <small>{items.length}{next ? '+' : ''}</small></h2>
        <div className="filters">
          {source !== 'securite' ? (
            <select aria-label={tr('Niveau')} value={level} onChange={(e) => setLevel(e.target.value)}>
              <option value="">{tr('Tout')}</option>
              <option value="info">{tr('Infos et plus')}</option>
              <option value="warning">{tr('Alertes et erreurs')}</option>
              <option value="error">{tr('Erreurs')}</option>
            </select>
          ) : null}
          <input type="search" aria-label={tr('Chercher dans le journal')} placeholder={source === 'securite' ? tr('IP, événement…') : tr('Texte, fichier, code…')}
            value={text} onChange={(e) => setText(e.target.value)} />
        </div>
      </div>
      {requestId ? (
        <p className="hint">{tr('Requête')} <code>{requestId}</code>{' '}
          <button type="button" className="link-btn" onClick={() => setRequestId(null)}>{tr('Toutes les requêtes')}</button></p>
      ) : null}
      {error ? <p className="form-error" role="alert">{error}</p> : null}
      <div className="table-wrap">
        <table className="admin-table log-table">
          <thead><tr><th>{tr('Date')}</th><th>{source === 'securite' ? tr('Événement') : tr('Niveau')}</th><th>{source === 'securite' ? tr('IP') : tr('Message')}</th><th /></tr></thead>
          <tbody>
            {items.map((e, i) => (
              <Fragment key={`${e.ts}-${i}`}>
                <tr>
                  <td><small>{formatStamp(e.ts)}</small></td>
                  {source === 'securite' ? (
                    <>
                      <td>{EVENT[e.message] ?? e.message}<small className="block">{e.message}</small></td>
                      <td><code>{String(e.context?.ip ?? '')}</code></td>
                    </>
                  ) : (
                    <>
                      <td><span className={`pill ${LEVEL_CLASS[e.level]}`}>{LEVEL[e.level]}</span></td>
                      <td className="log-message">{e.message}{e.userId !== null ? <small className="block">{tr('compte n° {n}', { n: e.userId })}</small> : null}</td>
                    </>
                  )}
                  <td className="row-actions">
                    {e.requestId && !requestId ? (
                      <button type="button" className="link-btn" title={tr('Toutes les lignes de cette requête')} onClick={() => setRequestId(e.requestId)}>{tr('Requête')}</button>
                    ) : null}
                    {hasDetails(e, source) ? (
                      <button type="button" className="link-btn" aria-expanded={open === i} onClick={() => setOpen(open === i ? null : i)}>
                        {open === i ? tr('Masquer') : tr('Détails')}
                      </button>
                    ) : null}
                  </td>
                </tr>
                {open === i ? (
                  <tr className="details"><td colSpan={4}><pre>{JSON.stringify(e.context, null, 2)}</pre></td></tr>
                ) : null}
              </Fragment>
            ))}
          </tbody>
        </table>
      </div>
      {!loading && items.length === 0 ? <p className="empty">{next ? tr('Rien dans les lignes les plus récentes.') : tr('Aucune ligne.')}</p> : null}
      <div className="pager">
        {loading ? <span>{tr('Lecture…')}</span> : next ? (
          <button type="button" onClick={() => void load(next, true)}>{items.length ? tr('Plus ancien') : tr('Chercher plus loin')}</button>
        ) : items.length ? <span>{tr('Début des journaux atteint')}</span> : null}
      </div>
    </section>
  );
}
