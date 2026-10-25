// @ts-check
// Comptes (CPT-01, CPT-03) : demandes à approuver, rôles, suspension, appareils et jetons.
import { useState } from 'react';
import { request } from '../api/client.js';
import { useApi, useDebounced, useSession } from '../hooks.js';
import { Link } from '../router.jsx';
import { Feedback, ROLE, STATUS, adminEvents, formatDate, useAction, useOffsetPage, Pager } from './common.jsx';
import { labels, tr } from '../i18n/index.js';

/** @typedef {import('../api/types.js').Schemas} Schemas */
/** @typedef {Schemas['AdminUser']} AdminUser */

/** Changements de statut permis par le serveur, avec le libellé du bouton. */
const ACTIONS = /** @type {Record<Schemas['UserStatus'], [Schemas['UserStatus'], string][]>} */ (labels(() => ({
  pending: [['active', tr('Approuver')], ['refused', tr('Refuser')]],
  active: [['suspended', tr('Suspendre')]],
  suspended: [['active', tr('Réactiver')]],
  refused: [['active', tr('Approuver quand même')]],
})));

/** @param {Schemas['UserStatus']} to @param {AdminUser} u */
function confirmText(to, u) {
  if (to === 'suspended') return tr('Suspendre {nom} ? Toutes ses sessions et ses jetons seront révoqués.', { nom: u.displayName });
  if (to === 'refused') return tr('Refuser la demande de {nom} ?', { nom: u.displayName });
  return null;
}

/**
 * @param {AdminUser} u @param {Schemas['UserStatus']} to
 * @param {ReturnType<typeof useAction>} action @param {() => void} done
 */
async function changeStatus(u, to, action, done) {
  const question = confirmText(to, u);
  if (question && !window.confirm(question)) return;
  const okText = {
    active: tr('{nom} : compte activé.', { nom: u.displayName }), refused: tr('{nom} : compte refusé.', { nom: u.displayName }),
    suspended: tr('{nom} : compte suspendu.', { nom: u.displayName }), pending: tr('{nom} : compte remis en attente.', { nom: u.displayName }),
  }[to];
  if (await action.run(() => request('PATCH', `/admin/users/${u.id}`, { body: { status: to } }), okText)) {
    adminEvents.set({ users: adminEvents.getState().users + 1 });
    done();
  }
}

export function Users() {
  const me = useSession().user;
  const [status, setStatus] = useState('');
  const [q, setQ] = useState('');
  const query = useDebounced(q.trim(), 250);
  const page = useOffsetPage('/admin/users', { status: status || undefined, q: query || undefined });
  const action = useAction();
  const [creating, setCreating] = useState(false);
  const users = /** @type {AdminUser[]} */ (page.items);

  return (
    <section>
      <div className="page-head">
        <h2 className="admin-title">{tr('Comptes')} {page.total !== null ? <small>{page.total}</small> : null}</h2>
        <div className="filters">
          <input type="search" placeholder={tr('Nom ou e-mail')} aria-label={tr('Chercher un compte')} value={q} onChange={(e) => setQ(e.target.value)} />
          <select aria-label={tr('Statut')} value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="">{tr('Tous')}</option>
            {Object.entries(STATUS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
          </select>
          <button type="button" onClick={() => setCreating(!creating)}>{tr('Créer un compte')}</button>
        </div>
      </div>
      {creating ? <CreateUser onDone={() => { setCreating(false); page.reload(); }} /> : null}
      <Feedback message={action.message} error={action.error} />
      {page.error ? <p className="form-error" role="alert">{page.error.message}</p> : null}
      <div className="table-wrap">
        <table className="admin-table">
          <thead><tr><th>{tr('Nom')}</th><th>{tr('Rôle')}</th><th>{tr('Statut')}</th><th>{tr('Créé le')}</th><th>{tr('Dernière connexion')}</th><th /></tr></thead>
          <tbody>
            {users.map((u) => (
              <tr key={u.id} className={u.status === 'pending' ? 'highlight' : ''}>
                <td>
                  <Link to={`/admin/comptes/${u.id}`}><strong>{u.displayName}</strong></Link>
                  <small className="block">{u.email}</small>
                  {u.status === 'pending' && u.requestMessage ? <q className="request-message">{u.requestMessage}</q> : null}
                </td>
                <td>{ROLE[u.role]}</td>
                <td><span className={`pill status-${u.status}`}>{STATUS[u.status]}</span></td>
                <td>{formatDate(u.createdAt)}</td>
                <td>{formatDate(u.lastLoginAt)}</td>
                <td className="row-actions">
                  {u.id !== me?.id ? ACTIONS[u.status].map(([to, label]) => (
                    <button key={to} type="button" className={to === 'active' ? 'primary' : ''} disabled={action.busy}
                      onClick={() => void changeStatus(u, to, action, page.reload)}>{label}</button>
                  )) : <small>{tr('vous')}</small>}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {!page.loading && users.length === 0 ? <p className="empty">{tr('Aucun compte.')}</p> : null}
      <Pager total={page.total} offset={page.offset} limit={page.limit} onChange={page.setOffset} />
    </section>
  );
}

/** @param {{ onDone: () => void }} props */
function CreateUser({ onDone }) {
  const [form, setForm] = useState({ email: '', displayName: '', role: /** @type {Schemas['Role']} */ ('user'), password: '' });
  const action = useAction();
  /** @param {Partial<typeof form>} p */
  const set = (p) => setForm({ ...form, ...p });
  return (
    <form className="admin-form card" onSubmit={async (e) => {
      e.preventDefault();
      const body = form.role === 'agent' ? { email: form.email, displayName: form.displayName, role: form.role } : form;
      if (await action.run(() => request('POST', '/admin/users', { body }))) onDone();
    }}>
      <h3>{tr('Nouveau compte (actif tout de suite)')}</h3>
      <label>{tr('Adresse e-mail')}<input type="email" required value={form.email} onChange={(e) => set({ email: e.target.value })} /></label>
      <label>{tr('Nom affiché')}<input required maxLength={100} value={form.displayName} onChange={(e) => set({ displayName: e.target.value })} /></label>
      <label>{tr('Rôle')}
        <select value={form.role} onChange={(e) => set({ role: /** @type {Schemas['Role']} */ (e.target.value) })}>
          {Object.entries(ROLE).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
        </select>
      </label>
      {form.role === 'agent'
        ? <p className="hint">{tr('Un agent n’a pas de mot de passe : créez-lui ensuite un jeton depuis sa fiche.')}</p>
        : <label>{tr('Mot de passe (10 caractères au moins)')}<input type="password" required minLength={10} autoComplete="new-password" value={form.password} onChange={(e) => set({ password: e.target.value })} /></label>}
      <Feedback message="" error={action.error} />
      <div className="form-actions"><button type="submit" className="primary" disabled={action.busy}>{tr('Créer')}</button></div>
    </form>
  );
}

/** Fiche d'un compte. @param {{ id: string }} props */
export function UserDetail({ id }) {
  const me = useSession().user;
  const api = useApi(`/admin/users/${id}`);
  const u = /** @type {Schemas['AdminUserDetail'] | null} */ (api.data);
  const action = useAction();
  const [name, setName] = useState(/** @type {string | null} */ (null));
  const [token, setToken] = useState(/** @type {Schemas['TokenResponse'] | null} */ (null));
  const [tokenName, setTokenName] = useState('');

  if (api.error) return <p className="empty">{api.error.status === 404 ? tr('Compte introuvable.') : api.error.message}</p>;
  if (!u) return <p className="empty">{tr('Chargement…')}</p>;
  const self = u.id === me?.id;

  return (
    <section className="admin-detail">
      <p><Link to="/admin">{tr('← Comptes')}</Link></p>
      <h2 className="admin-title">{u.displayName} <span className={`pill status-${u.status}`}>{STATUS[u.status]}</span></h2>
      <dl className="facts">
        <dt>{tr('E-mail')}</dt><dd>{u.email}</dd>
        <dt>{tr('Créé le')}</dt><dd>{formatDate(u.createdAt)}</dd>
        <dt>{tr('Décision')}</dt><dd>{formatDate(u.decidedAt)}</dd>
        <dt>{tr('Dernière connexion')}</dt><dd>{formatDate(u.lastLoginAt)}</dd>
        {u.requestMessage ? <><dt>{tr('Message de la demande')}</dt><dd><q>{u.requestMessage}</q></dd></> : null}
      </dl>
      <Feedback message={action.message} error={action.error} />

      <h3>{tr('Compte')}</h3>
      <div className="admin-form inline">
        <label>{tr('Nom affiché')}
          <input maxLength={100} value={name ?? u.displayName} onChange={(e) => setName(e.target.value)} />
        </label>
        <button type="button" disabled={action.busy || name === null || name.trim() === '' || name === u.displayName}
          onClick={async () => {
            if (await action.run(() => request('PATCH', `/admin/users/${u.id}`, { body: { displayName: name?.trim() } }), tr('Nom enregistré.'))) {
              setName(null);
              api.reload();
            }
          }}>{tr('Enregistrer')}</button>
        <label>{tr('Rôle')}
          <select value={u.role} disabled={self || action.busy} title={self ? tr('Vous ne pouvez pas retirer votre propre rôle d’admin') : undefined}
            onChange={(e) => void action.run(() => request('PATCH', `/admin/users/${u.id}`, { body: { role: e.target.value } }), tr('Rôle modifié.')).then((ok) => ok && api.reload())}>
            {Object.entries(ROLE).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
          </select>
        </label>
      </div>
      {!self ? (
        <div className="form-actions">
          {ACTIONS[u.status].map(([to, label]) => (
            <button key={to} type="button" className={to === 'active' ? 'primary' : ''} disabled={action.busy}
              onClick={() => void changeStatus(u, to, action, api.reload)}>{label}</button>
          ))}
        </div>
      ) : null}

      <h3>{tr('Appareils et jetons')} <small>{u.devices.length}</small></h3>
      <ul className="devices">
        {u.devices.map((d) => (
          <li key={d.id}>
            <span>
              {d.name} <small>{d.kind === 'web' ? tr('navigateur') : d.kind === 'app' ? tr('appli') : tr('agent')} · {d.prefix}…{d.current ? ` · ${tr('cette session')}` : ''}</small>
              <small className="block">{tr('créé le {date}', { date: formatDate(d.createdAt) })} · {tr('vu le {date}', { date: formatDate(d.lastUsedAt) })}{d.lastIp ? ` · ${d.lastIp}` : ''}{d.expiresAt ? ` · ${tr('expire le {date}', { date: formatDate(d.expiresAt) })}` : ''}</small>
            </span>
            {!d.current ? (
              <button type="button" className="link-btn" disabled={action.busy} onClick={async () => {
                if (!window.confirm(tr('Révoquer « {nom} » ? L’appareil devra se reconnecter.', { nom: d.name }))) return;
                if (await action.run(() => request('DELETE', `/admin/tokens/${d.id}`), tr('Accès révoqué.'))) api.reload();
              }}>{tr('Révoquer')}</button>
            ) : null}
          </li>
        ))}
        {u.devices.length === 0 ? <li><small>{tr('Aucun appareil connecté.')}</small></li> : null}
      </ul>

      {u.status === 'active' ? (
        <form className="admin-form inline" onSubmit={async (e) => {
          e.preventDefault();
          await action.run(async () => {
            const r = await request('POST', `/admin/users/${u.id}/tokens`, { body: { name: tokenName.trim() } });
            setToken(/** @type {Schemas['TokenResponse']} */ (r.data));
            setTokenName('');
            api.reload();
          });
        }}>
          <label>{tr('Nouveau jeton (appli, agent)')}<input required maxLength={100} placeholder={tr('Nom de l’appareil')} value={tokenName} onChange={(e) => setTokenName(e.target.value)} /></label>
          <button type="submit" disabled={action.busy || !tokenName.trim()}>{tr('Créer le jeton')}</button>
        </form>
      ) : null}
      {token ? (
        <div className="card token-once" role="status">
          <p><strong>{tr('Jeton créé.')}</strong> {tr('Copiez-le maintenant : il ne sera plus jamais affiché.')}</p>
          <code>{token.token}</code>
          <div className="form-actions">
            <button type="button" onClick={() => void navigator.clipboard?.writeText(token.token)}>{tr('Copier')}</button>
            <button type="button" onClick={() => setToken(null)}>{tr('Masquer')}</button>
          </div>
          {u.role === 'agent' ? <McpCommand secret={token.token} /> : null}
        </div>
      ) : null}
    </section>
  );
}

/**
 * Commande qui branche Claude Code sur le serveur MCP (IA-03) avec ce jeton d'agent.
 * @param {{ secret: string }} props
 */
function McpCommand({ secret }) {
  const command = `claude mcp add --transport http rengaine ${window.location.origin}/api/v2/mcp --header "Authorization: Bearer ${secret}"`;
  return (
    <>
      <p>{tr('Pour Claude Code, cette commande branche le serveur MCP de la bibliothèque avec ce jeton :')}</p>
      <code className="mcp-command">{command}</code>
      <div className="form-actions">
        <button type="button" onClick={() => void navigator.clipboard?.writeText(command)}>{tr('Copier la commande')}</button>
      </div>
    </>
  );
}
