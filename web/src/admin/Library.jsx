// @ts-check
// Réglages du serveur, genres et valeurs de genre lues dans les tags (CPT-07).
import { useState } from 'react';
import { get, request } from '../api/client.js';
import { session } from '../engine/session.js';
import { useApi, useDebounced } from '../hooks.js';
import { Link } from '../router.jsx';
import { Feedback, Pager, formatDate, useAction, useGenres, useOffsetPage } from './common.jsx';
import { locale, tr, trn } from '../i18n/index.js';

/** @typedef {import('../api/types.js').Schemas} Schemas */

export function ServerSettings() {
  const api = useApi('/admin/server-settings');
  const current = /** @type {Schemas['ServerSettings'] | null} */ (api.data);
  const [form, setForm] = useState(/** @type {Schemas['ServerSettings'] | null} */ (null));
  const action = useAction();
  if (!current) return <p className="empty">{api.error ? api.error.message : 'Chargement…'}</p>;
  const f = form ?? current;
  /** @type {Schemas['ServerSettingsUpdate']} */
  const changes = {};
  if (f.serverName.trim() !== current.serverName) changes.serverName = f.serverName.trim();
  if (f.language !== current.language) changes.language = f.language;
  if (f.registrationOpen !== current.registrationOpen) changes.registrationOpen = f.registrationOpen;
  if (f.pendingRequestsPerNetwork !== current.pendingRequestsPerNetwork) changes.pendingRequestsPerNetwork = f.pendingRequestsPerNetwork;
  if (f.remoteControlEnabled !== current.remoteControlEnabled) changes.remoteControlEnabled = f.remoteControlEnabled;
  const service = { running: tr('en marche'), stopped: tr('arrêté'), absent: tr('non installé') }[current.realtimeService];

  return (
    <form className="admin-form settings" onSubmit={async (e) => {
      e.preventDefault();
      const ok = await action.run(async () => {
        await request('PATCH', '/admin/server-settings', { body: changes });
        session.set({ server: await get('/server') }); // nom affiché en haut de page
      }, tr('Réglages enregistrés.'));
      if (ok && changes.language) window.location.reload(); // tout le site passe dans la nouvelle langue
      else if (ok) {
        setForm(null);
        api.reload();
      }
    }}>
      <h2 className="admin-title">{tr('Serveur')}</h2>
      <label>{tr('Nom du serveur')}<input required maxLength={100} value={f.serverName} onChange={(e) => setForm({ ...f, serverName: e.target.value })} /></label>
      <label>{tr('Langue du site')}
        <select value={f.language} onChange={(e) => setForm({ ...f, language: /** @type {Schemas['Language']} */ (e.target.value) })}>
          {/* Chaque langue écrite dans sa propre langue */}
          <option value="fr" lang="fr">Français</option>
          <option value="en" lang="en">English</option>
        </select>
        <small>{tr('Pour tous les comptes : textes du site, messages et mails du serveur. Les noms de genres restent ceux de la bibliothèque.')}</small>
      </label>
      <label className="check"><input type="checkbox" checked={f.registrationOpen} onChange={(e) => setForm({ ...f, registrationOpen: e.target.checked })} /> {tr('Demandes de compte ouvertes')}</label>
      {f.registrationOpen ? (
        <p className="hint request-link">{tr('Lien direct vers le formulaire de demande, à partager :')} <code>{`${window.location.origin}/?demande`}</code>{' '}
          <button type="button" className="link-btn" onClick={() => void navigator.clipboard?.writeText(`${window.location.origin}/?demande`)}>{tr('Copier')}</button>
        </p>
      ) : null}
      <label>{tr('Demandes en attente autorisées par réseau')}
        <input type="number" min={1} max={20} required value={f.pendingRequestsPerNetwork}
          onChange={(e) => setForm({ ...f, pendingRequestsPerNetwork: Number(e.target.value) })} />
        <small>{tr('Par adresse IPv4 publique et par réseau IPv6 /64 : limite les demandes en rafale.')}</small>
      </label>
      <fieldset>
        <legend>{tr('Télécommande partagée')}</legend>
        <label className="check"><input type="checkbox" checked={f.remoteControlEnabled} disabled={current.realtimeService === 'absent'}
          onChange={(e) => setForm({ ...f, remoteControlEnabled: e.target.checked })} /> {tr('Liens de télécommande autorisés')}</label>
        <small>{tr('Décocher coupe tout de suite les télécommandes ouvertes et arrête le service temps réel. Les liens ne sont pas supprimés : ils remarchent quand on recoche, s’ils n’ont pas expiré.')}</small>
        <small>{tr('Service temps réel :')} <strong>{service}</strong>{current.remoteControlEnabled && current.realtimeService === 'stopped' ? ` — ${tr('il devrait tourner : voir « systemctl status mercure »')}` : ''}</small>
      </fieldset>
      <Feedback message={action.message} error={action.error} />
      <div className="form-actions">
        <button type="submit" className="primary" disabled={action.busy || Object.keys(changes).length === 0}>{tr('Enregistrer')}</button>
      </div>
    </form>
  );
}

/** Choix d'un genre. @param {{ value: number | null, onChange: (id: number | null) => void, genres: Schemas['Genre'][], empty: string, exclude?: number, label: string, disabled?: boolean }} props */
export function GenreSelect({ value, onChange, genres, empty, exclude, label, disabled }) {
  return (
    <select aria-label={label} value={value ?? ''} disabled={disabled} onChange={(e) => onChange(e.target.value ? Number(e.target.value) : null)}>
      <option value="">{empty}</option>
      {genres.filter((g) => g.id !== exclude).map((g) => <option key={g.id} value={g.id}>{g.name}</option>)}
    </select>
  );
}

/** Genres classés en arbre (parent puis enfants), avec leur profondeur. @param {Schemas['Genre'][]} list */
function tree(list) {
  /** @type {Map<number | null, Schemas['Genre'][]>} */
  const children = new Map();
  for (const g of list) {
    const p = g.parentId !== null && list.some((x) => x.id === g.parentId) ? g.parentId : null;
    children.set(p, [...(children.get(p) ?? []), g]);
  }
  /** @type {{ genre: Schemas['Genre'], depth: number }[]} */
  const out = [];
  /** @param {number | null} parent @param {number} depth */
  const walk = (parent, depth) => {
    for (const g of (children.get(parent) ?? []).sort((a, b) => a.name.localeCompare(b.name, locale()))) {
      out.push({ genre: g, depth });
      if (depth < 10) walk(g.id, depth + 1);
    }
  };
  walk(null, 0);
  return out;
}

export function GenresAdmin() {
  const { list, reload } = useGenres();
  const action = useAction();
  const [name, setName] = useState('');
  const [parent, setParent] = useState(/** @type {number | null} */ (null));
  const [editing, setEditing] = useState(/** @type {number | null} */ (null));

  return (
    <section>
      <div className="page-head"><h2 className="admin-title">{tr('Genres')} <small>{list.length}</small></h2></div>
      <form className="admin-form inline" onSubmit={async (e) => {
        e.preventDefault();
        if (await action.run(() => request('POST', '/admin/genres', { body: { name: name.trim(), parentId: parent } }), tr('Genre « {nom} » créé.', { nom: name.trim() }))) {
          setName('');
          reload();
        }
      }}>
        <label>{tr('Nouveau genre')}<input required maxLength={100} value={name} onChange={(e) => setName(e.target.value)} /></label>
        <label>{tr('Dans')}<GenreSelect label={tr('Genre parent')} value={parent} onChange={setParent} genres={list} empty={tr('(premier niveau)')} /></label>
        <button type="submit" disabled={action.busy || !name.trim()}>{tr('Créer')}</button>
      </form>
      <Feedback message={action.message} error={action.error} />
      <p className="hint">{tr('Les albums prennent leurs genres dans les tags : rattachez les valeurs lues dans l’onglet')} <Link to="/admin/tags">{tr('Tags')}</Link>.</p>
      <ul className="genre-list admin-genres">
        {tree(list).map(({ genre: g, depth }) => (
          <li key={g.id} style={{ paddingLeft: `${depth * 1.4 + 0.2}rem` }}>
            {editing === g.id
              ? <GenreEditor genre={g} genres={list} action={action} onDone={(changed) => { setEditing(null); if (changed) reload(); }} />
              : (
                <>
                  <span className="genre-name">
                    <Link to={`/?genre=${g.id}`}>{g.name}</Link>
                    <small>{trn(g.albumCount, '{n} album', '{n} albums')} · {trn(g.trackCount, '{n} piste', '{n} pistes')}</small>
                    {g.aliases.length ? <small className="block">{tr('Tags :')} {g.aliases.join(', ')}</small> : null}
                  </span>
                  <button type="button" className="link-btn" onClick={() => { action.clear(); setEditing(g.id); }}>{tr('Modifier')}</button>
                </>
              )}
          </li>
        ))}
      </ul>
    </section>
  );
}

/** @param {{ genre: Schemas['Genre'], genres: Schemas['Genre'][], action: ReturnType<typeof useAction>, onDone: (changed: boolean) => void }} props */
function GenreEditor({ genre, genres, action, onDone }) {
  const [name, setName] = useState(genre.name);
  const [parent, setParent] = useState(genre.parentId);
  const [into, setInto] = useState(/** @type {number | null} */ (null));
  return (
    <div className="genre-editor">
      <div className="admin-form inline">
        <label>{tr('Nom')}<input maxLength={100} value={name} onChange={(e) => setName(e.target.value)} /></label>
        <label>{tr('Dans')}<GenreSelect label={tr('Genre parent')} value={parent} onChange={setParent} genres={genres} exclude={genre.id} empty={tr('(premier niveau)')} /></label>
        <button type="button" className="primary" disabled={action.busy || !name.trim() || (name.trim() === genre.name && parent === genre.parentId)}
          onClick={async () => {
            /** @type {Schemas['GenreInput']} */
            const body = {};
            if (name.trim() !== genre.name) body.name = name.trim();
            if (parent !== genre.parentId) body.parentId = parent;
            if (await action.run(() => request('PATCH', `/admin/genres/${genre.id}`, { body }), tr('Genre modifié.'))) onDone(true);
          }}>{tr('Enregistrer')}</button>
        <button type="button" onClick={() => onDone(false)}>{tr('Fermer')}</button>
      </div>
      <div className="admin-form inline danger-zone">
        <label>{tr('Supprimer, en donnant ses albums à')}
          <GenreSelect label={tr('Genre qui reçoit les albums')} value={into} onChange={setInto} genres={genres} exclude={genre.id} empty={tr('(aucun : les albums perdent ce genre)')} />
        </label>
        <button type="button" className="danger" disabled={action.busy} onClick={async () => {
          const target = genres.find((g) => g.id === into)?.name;
          const q = target ? tr('Fusionner « {nom} » dans « {cible} » ?', { nom: genre.name, cible: target })
            : trn(genre.albumCount, 'Supprimer « {nom} » ? {n} album perdra ce genre.', 'Supprimer « {nom} » ? {n} albums perdront ce genre.', { nom: genre.name });
          if (!window.confirm(q)) return;
          if (await action.run(() => request('DELETE', `/admin/genres/${genre.id}`, { query: { mergeInto: into ?? undefined } }), tr('Genre supprimé.'))) onDone(true);
        }}>{into ? tr('Fusionner') : tr('Supprimer')}</button>
      </div>
    </div>
  );
}

/** Valeurs de genre lues dans les tags : un tag ne crée jamais de genre tout seul. */
export function Tags() {
  const [mapped, setMapped] = useState('false');
  const [q, setQ] = useState('');
  const query = useDebounced(q.trim(), 250);
  const page = useOffsetPage('/admin/genre-aliases', { mapped: mapped || undefined, q: query || undefined });
  const genres = useGenres();
  const action = useAction();
  const items = /** @type {Schemas['GenreAlias'][]} */ (page.items);

  /** @param {string} alias @param {number | null} genreId */
  const map = async (alias, genreId) => {
    if (await action.run(() => request('POST', '/admin/genre-aliases', { body: { alias, genreId } }),
      genreId ? tr('« {nom} » rattaché à {genre}.', { nom: alias, genre: genres.names.get(genreId) ?? '' }) : tr('« {nom} » détaché.', { nom: alias }))) {
      page.reload();
      genres.reload();
    }
  };

  return (
    <section>
      <div className="page-head">
        <h2 className="admin-title">{tr('Tags de genre')} {page.total !== null ? <small>{page.total}</small> : null}</h2>
        <div className="filters">
          <input type="search" placeholder={tr('Valeur')} aria-label={tr('Chercher une valeur')} value={q} onChange={(e) => setQ(e.target.value)} />
          <select aria-label={tr('Rattachement')} value={mapped} onChange={(e) => setMapped(e.target.value)}>
            <option value="false">{tr('À rattacher')}</option>
            <option value="true">{tr('Rattachées')}</option>
            <option value="">{tr('Toutes')}</option>
          </select>
        </div>
      </div>
      <p className="hint">{tr('Rattachez chaque valeur lue dans les tags à un genre : les albums concernés sont mis à jour aussitôt.')}</p>
      <Feedback message={action.message} error={action.error} />
      <div className="table-wrap">
        <table className="admin-table">
          <thead><tr><th>{tr('Valeur du tag')}</th><th>{tr('Pistes')}</th><th>{tr('Vue le')}</th><th>{tr('Genre')}</th></tr></thead>
          <tbody>
            {items.map((a) => (
              <tr key={a.alias}>
                <td><strong>{a.alias}</strong></td>
                <td>{a.trackCount}</td>
                <td>{formatDate(a.firstSeenAt)}</td>
                <td className="row-actions">
                  <GenreSelect label={tr('Genre de « {nom} »', { nom: a.alias })} value={a.genre?.id ?? null} genres={genres.list} empty={tr('— non rattachée —')}
                    disabled={action.busy} onChange={(id) => void map(a.alias, id)} />
                  {!a.genre && !genres.list.some((g) => g.name.localeCompare(a.alias, locale(), { sensitivity: 'base' }) === 0) ? (
                    <button type="button" className="link-btn" disabled={action.busy} onClick={async () => {
                      if (await action.run(() => request('POST', '/admin/genres', { body: { name: a.alias } }), tr('Genre « {nom} » créé et rattaché.', { nom: a.alias }))) {
                        page.reload();
                        genres.reload();
                      }
                    }}>{tr('Créer ce genre')}</button>
                  ) : null}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {!page.loading && items.length === 0 ? <p className="empty">{mapped === 'false' ? tr('Toutes les valeurs sont rattachées.') : tr('Aucune valeur.')}</p> : null}
      <Pager total={page.total} offset={page.offset} limit={page.limit} onChange={page.setOffset} />
    </section>
  );
}
