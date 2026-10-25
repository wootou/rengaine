// @ts-check
// Admin > Collections (CAT-08) : ensembles d'albums choisis par l'admin, visibles de tous (page
// Explorer). Chaque changement d'albums est enregistré tout de suite (liste complète, dans l'ordre).
import { useEffect, useState } from 'react';
import { get, request } from '../api/client.js';
import { Cover } from '../components/Cover.jsx';
import { Icon } from '../components/Icon.jsx';
import { useApi, useDebounced } from '../hooks.js';
import { Link, navigate, useRoute } from '../router.jsx';
import { Feedback, useAction } from './common.jsx';
import { tr } from '../i18n/index.js';

/** @typedef {import('../api/types.js').Schemas} Schemas */

export function CollectionsAdmin() {
  const { query } = useRoute();
  const selected = Number(query.get('id') ?? '') || null;
  const api = useApi('/collections', { kind: 'collection' });
  const list = /** @type {{ items: Schemas['Collection'][] } | null} */ (api.data)?.items ?? [];
  const action = useAction();
  const [name, setName] = useState('');
  const [notice, setNotice] = useState('');

  return (
    <section className="collections-admin">
      <div className="page-head">
        <h2 className="admin-title">{tr('Collections')} <small>{list.length}</small></h2>
        <form className="inline-form" onSubmit={async (e) => {
          e.preventDefault();
          /** @type {Schemas['Collection'] | null} */
          let created = null;
          const ok = await action.run(async () => {
            created = /** @type {Schemas['Collection']} */ ((await request('POST', '/admin/collections', { body: { name: name.trim() } })).data);
          }, tr('Collection « {nom} » créée.', { nom: name.trim() }));
          if (ok && created) {
            setName('');
            api.reload();
            navigate(`/admin/collections?id=${/** @type {Schemas['Collection']} */ (created).id}`, true);
          }
        }}>
          <input type="text" aria-label={tr('Nom de la nouvelle collection')} placeholder={tr('Nouvelle collection…')} maxLength={100} value={name} onChange={(e) => setName(e.target.value)} />
          <button type="submit" className="primary" disabled={action.busy || !name.trim()}>{tr('Créer')}</button>
        </form>
      </div>
      <p className="hint">{tr('Des ensembles d’albums (« Noël », « Soirée », « Bandes originales »…), visibles de tous dans la page Explorer.')}</p>
      <Feedback message={action.message || notice} error={action.error} />
      {list.length ? (
        <nav className="tabs subtabs" aria-label={tr('Collections')}>
          {list.map((c) => (
            <Link key={c.id} to={`/admin/collections?id=${c.id}`} className={selected === c.id ? 'current' : ''}>
              {c.name} <span className="count">{c.albumCount}</span>
            </Link>
          ))}
        </nav>
      ) : <p className="empty">{tr('Pas encore de collection.')}</p>}
      {selected !== null ? (
        <CollectionEditor key={selected} id={selected} onChanged={() => api.reload()} onDeleted={(n) => {
          setNotice(tr('Collection « {nom} » supprimée.', { nom: n }));
          api.reload();
          navigate('/admin/collections', true);
        }} />
      ) : null}
    </section>
  );
}

/** @param {{ id: number, onChanged: () => void, onDeleted: (name: string) => void }} props */
function CollectionEditor({ id, onChanged, onDeleted }) {
  const api = useApi(`/collections/${id}`);
  const [c, setC] = useState(/** @type {Schemas['CollectionDetail'] | null} */ (null));
  const action = useAction();
  const [term, setTerm] = useState('');
  const q = useDebounced(term.trim(), 250);
  const [found, setFound] = useState(/** @type {Schemas['AlbumSummary'][]} */ ([]));

  useEffect(() => {
    if (api.data) setC(/** @type {Schemas['CollectionDetail']} */ (api.data));
  }, [api.data]);

  useEffect(() => {
    let live = true;
    if (q.length < 2) {
      setFound([]);
      return undefined;
    }
    get('/albums', { query: { q, limit: 20, sort: 'name' } })
      .then((r) => live && setFound(/** @type {{ items: Schemas['AlbumSummary'][] }} */ (r).items))
      .catch(() => live && setFound([]));
    return () => {
      live = false;
    };
  }, [q]);

  if (api.error) return <p className="empty">{api.error.status === 404 ? tr('Collection introuvable.') : api.error.message}</p>;
  if (!c) return <p className="empty">{tr('Chargement…')}</p>;
  const ids = c.albums.map((a) => a.id);

  /** @param {number[]} albumIds @param {string} ok */
  const save = async (albumIds, ok) => {
    await action.run(async () => {
      setC(/** @type {Schemas['CollectionDetail']} */ ((await request('PUT', `/admin/collections/${id}/albums`, { body: { albumIds } })).data));
      onChanged();
    }, ok);
  };
  /** @param {number} i @param {-1 | 1} d */
  const move = (i, d) => {
    const next = [...ids];
    const [it] = next.splice(i, 1);
    next.splice(i + d, 0, it);
    void save(next, '');
  };

  return (
    <div className="collection-editor">
      <form className="admin-form card" onSubmit={async (e) => {
        e.preventDefault();
        const f = new FormData(e.currentTarget);
        await action.run(async () => {
          await request('PATCH', `/admin/collections/${id}`, { body: { name: String(f.get('name') ?? '').trim(), description: String(f.get('description') ?? '').trim() || null } });
          onChanged();
        }, tr('Enregistré.'));
      }}>
        <label>{tr('Nom')}<input type="text" name="name" required maxLength={100} defaultValue={c.name} /></label>
        <label>{tr('Description')}<textarea name="description" rows={2} maxLength={1000} defaultValue={c.description ?? ''} /></label>
        <div className="form-actions">
          <button type="submit" className="primary" disabled={action.busy}>{tr('Enregistrer')}</button>
          <Link to={`/collections/${id}`} className="button">{tr('Voir la page')}</Link>
          <button type="button" className="danger" disabled={action.busy} onClick={async () => {
            if (!window.confirm(tr('Supprimer la collection « {nom} » ? Les albums restent dans la bibliothèque.', { nom: c.name }))) return;
            if (await action.run(() => request('DELETE', `/admin/collections/${id}`))) onDeleted(c.name);
          }}><Icon name="trash" size={18} /> {tr('Supprimer')}</button>
        </div>
      </form>
      <Feedback message={action.message} error={action.error} />

      <h3>{tr('Albums')} <small>{c.albums.length}</small></h3>
      <label className="album-search">{tr('Ajouter un album')}
        <input type="search" placeholder={tr('Titre ou artiste…')} value={term} onChange={(e) => setTerm(e.target.value)} />
      </label>
      {found.length ? (
        <ul className="pick-list">
          {found.map((a) => (
            <li key={a.id}>
              <span className="pick-album"><Cover url={a.coverUrl} size="s" alt="" /> <span>{a.title}<small className="block">{a.artistName}{a.year ? ` · ${a.year}` : ''}</small></span></span>
              <button type="button" disabled={action.busy || ids.includes(a.id)} onClick={() => void save([...ids, a.id], tr('« {titre} » ajouté.', { titre: a.title }))}>
                {ids.includes(a.id) ? tr('Déjà dedans') : tr('Ajouter')}
              </button>
            </li>
          ))}
        </ul>
      ) : null}
      {c.albums.length ? (
        <ol className="pick-list collection-albums">
          {c.albums.map((a, i) => (
            <li key={a.id}>
              <span className="pick-album"><Cover url={a.coverUrl} size="s" alt="" /> <span>{a.title}<small className="block">{a.artistName}{a.year ? ` · ${a.year}` : ''}</small></span></span>
              <span className="row-actions">
                <button type="button" className="icon-btn" title={tr('Monter')} disabled={action.busy || i === 0} onClick={() => move(i, -1)}><Icon name="chevronUp" /></button>
                <button type="button" className="icon-btn" title={tr('Descendre')} disabled={action.busy || i === c.albums.length - 1} onClick={() => move(i, 1)}><Icon name="chevronDown" /></button>
                <button type="button" className="icon-btn" title={tr('Retirer de la collection')} disabled={action.busy}
                  onClick={() => void save(ids.filter((x) => x !== a.id), tr('« {titre} » retiré.', { titre: a.title }))}><Icon name="close" /></button>
              </span>
            </li>
          ))}
        </ol>
      ) : <p className="empty">{tr('Aucun album : cherchez-en un ci-dessus.')}</p>}
    </div>
  );
}
