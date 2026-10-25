// @ts-check
// Correction d'un artiste : nom, nom de tri, fusion dans un autre artiste (doublon, variante
// d'orthographe). Tout est annulable : les tags qui portent l'ancien nom mènent toujours ici.
import { useState } from 'react';
import { request } from '../api/client.js';
import { useApi, useDebounced, useSession } from '../hooks.js';
import { Link, navigate } from '../router.jsx';
import { Cover } from '../components/Cover.jsx';
import { CorrectionChange } from './Activity.jsx';
import { shrink } from './Backgrounds.jsx';
import { Feedback, formatDate, useAction, useGenres } from './common.jsx';
import { tr, trn } from '../i18n/index.js';

/** @typedef {import('../api/types.js').Schemas} Schemas */

/** @param {{ id: string }} props */
export function ArtistEdit({ id }) {
  const admin = useSession().user?.role === 'admin';
  const api = useApi(admin ? `/artists/${id}` : null);
  const artist = /** @type {Schemas['ArtistDetail'] | null} */ (api.data);
  const corr = useApi(admin ? '/admin/corrections' : null, { entityType: 'artist', entityId: id, status: 'active' });
  const corrections = /** @type {{ items: Schemas['Correction'][] } | null} */ (corr.data)?.items ?? [];
  const { names } = useGenres();
  const action = useAction();

  if (!admin) return <p className="empty">{tr('Cette page est réservée à l’admin.')}</p>;
  if (api.error) return <p className="empty">{api.error.status === 404 ? tr('Artiste introuvable.') : api.error.message}</p>;
  if (!artist) return <p className="empty">{tr('Chargement…')}</p>;
  const merged = corrections.find((c) => c.field === 'mergedInto');
  const reload = () => {
    api.reload();
    corr.reload();
  };

  return (
    <section className="artist-edit">
      <p><Link to={`/artistes/${artist.id}`}>{tr('← Retour à l’artiste')}</Link></p>
      <div className="page-head">
        <h1><span className="kind">{tr('Corriger l’artiste')}</span> {artist.name}</h1>
      </div>
      <p className="meta">{trn(artist.albumCount, '{n} album', '{n} albums')} · {trn(artist.appearsOn.length, 'apparaît sur {n} autre', 'apparaît sur {n} autres')}</p>
      <Feedback message={action.message} error={action.error} />
      <div className="artist-photo-edit card">
        <Cover url={artist.imageUrl} size="m" alt={artist.imageUrl ? tr('Photo de {nom}', { nom: artist.name }) : tr('Pas de photo')} className="artist-photo" />
        <div>
          <h2>{tr('Photo')}</h2>
          <p className="hint">{tr('Affichée sur la page de l’artiste et dans la liste. Réduite à 1200 px avant l’envoi.')}</p>
          <div className="form-actions">
            <label className="button file-button">
              {artist.imageUrl ? tr('Changer la photo') : tr('Choisir une photo')}
              <input type="file" accept="image/jpeg,image/png,image/webp" disabled={action.busy} onChange={async (e) => {
                const file = e.target.files?.[0];
                e.target.value = '';
                if (!file) return;
                const ok = await action.run(async () => {
                  const blob = await shrink(file, 1200, 1200);
                  await request('PUT', `/admin/artists/${artist.id}/image`, { blob });
                }, tr('Photo enregistrée.'));
                if (ok) reload();
              }} />
            </label>
            {artist.imageUrl ? (
              <button type="button" className="danger" disabled={action.busy} onClick={async () => {
                if (await action.run(() => request('DELETE', `/admin/artists/${artist.id}/image`), tr('Photo retirée.'))) reload();
              }}>{tr('Retirer')}</button>
            ) : null}
          </div>
        </div>
      </div>
      {merged ? (
        <p className="hint warn">
          {tr('Cet artiste est fusionné dans')} <Link to={`/artistes/${String(merged.value)}`}>{tr('l’artiste #{id}', { id: String(merged.value) })}</Link>{' '}
          {tr(': ses pistes y sont. Annulez la fusion ci-dessous pour le retrouver.')}
        </p>
      ) : null}

      <NameForm key={`${artist.name}|${artist.sortName ?? ''}`} artist={artist} action={action} onSaved={reload} />

      <h2>{tr('Corrections actives')} <small>{corrections.length}</small></h2>
      {corrections.length ? (
        <ul className="corrections">
          {corrections.map((c) => (
            <li key={c.id}>
              <span>
                <CorrectionChange c={c} names={names} />
                <small className="block">{tr('{nom}, le {date}', { nom: c.createdBy.displayName, date: formatDate(c.createdAt) })}</small>
              </span>
              <button type="button" disabled={action.busy} onClick={async () => {
                if (await action.run(() => request('POST', `/admin/corrections/${c.id}/revert`), tr('Correction annulée.'))) reload();
              }}>{tr('Annuler')}</button>
            </li>
          ))}
        </ul>
      ) : <p className="hint">{tr('Aucune : l’artiste porte le nom lu dans les tags.')}</p>}

      {!merged ? <MergeArtist artist={artist} /> : null}
    </section>
  );
}

/** @param {{ artist: Schemas['ArtistDetail'], action: ReturnType<typeof useAction>, onSaved: () => void }} props */
function NameForm({ artist, action, onSaved }) {
  const [name, setName] = useState(artist.name);
  const [sortName, setSortName] = useState(artist.sortName ?? '');
  /** @type {Schemas['ArtistPatch']} */
  const patch = {};
  if (name.trim() !== artist.name) patch.name = name.trim();
  if (sortName.trim() !== (artist.sortName ?? '')) patch.sortName = sortName.trim() || null;
  const changed = Object.keys(patch).length > 0;
  return (
    <form className="admin-form album-form" onSubmit={async (e) => {
      e.preventDefault();
      if (await action.run(() => request('PATCH', `/admin/artists/${artist.id}`, { body: patch }), tr('Artiste corrigé.'))) onSaved();
    }}>
      <label>{tr('Nom')}<input required maxLength={255} value={name} onChange={(e) => setName(e.target.value)} /></label>
      <label>{tr('Nom de tri')}<input maxLength={255} placeholder={tr('ex. « Beatles, The » (vide = le nom)')} value={sortName} onChange={(e) => setSortName(e.target.value)} /></label>
      <div className="form-actions">
        <button type="submit" className="primary" disabled={action.busy || !changed || !name.trim()}>{tr('Enregistrer')}</button>
        {changed ? <button type="button" onClick={() => { setName(artist.name); setSortName(artist.sortName ?? ''); }}>{tr('Rétablir')}</button> : null}
      </div>
    </form>
  );
}

/** @param {{ artist: Schemas['ArtistDetail'] }} props */
function MergeArtist({ artist }) {
  const [q, setQ] = useState('');
  const typed = useDebounced(q.trim(), 300);
  const found = useApi(typed.length >= 2 ? '/artists' : null, { q: typed, limit: 8 });
  const results = (/** @type {{ items: Schemas['ArtistSummary'][] } | null} */ (found.data)?.items ?? []).filter((a) => a.id !== artist.id);
  const action = useAction();
  return (
    <details className="merge-form">
      <summary>{tr('Fusionner cet artiste dans un autre')}</summary>
      <p className="hint">
        {tr('Pour un doublon ou une variante d’orthographe : ses albums et ses pistes passent à l’artiste choisi, et les tags « {nom} » y mèneront aussi après un scan. La fusion s’annule dans Admin > Propositions.', { nom: artist.name })}
      </p>
      <label>{tr('Chercher l’artiste qui reste')}<input type="search" value={q} placeholder={tr('Nom de l’artiste')} onChange={(e) => setQ(e.target.value)} /></label>
      <Feedback message={action.message} error={action.error} />
      {results.length ? (
        <ul className="pick-list">
          {results.map((a) => (
            <li key={a.id}>
              <span>{a.name} <small>{trn(a.albumCount, '{n} album', '{n} albums')}</small></span>
              <button type="button" disabled={action.busy} onClick={async () => {
                if (!window.confirm(tr('Fusionner « {nom} » dans « {cible} » ?', { nom: artist.name, cible: a.name }))) return;
                if (await action.run(() => request('POST', `/admin/artists/${artist.id}/merge`, { body: { intoArtistId: a.id } }))) navigate(`/artistes/${a.id}`);
              }}>{tr('Fusionner ici')}</button>
            </li>
          ))}
        </ul>
      ) : typed.length >= 2 && !found.loading ? <p className="empty small">{tr('Aucun autre artiste trouvé.')}</p> : null}
    </details>
  );
}
