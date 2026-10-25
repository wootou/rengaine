// @ts-check
// Correction d'un album et de ses pistes (CPT-07). Chaque champ modifié devient une correction :
// elle survit aux scans et s'annule ici ou dans l'onglet Corrections.
import { useState } from 'react';
import { request } from '../api/client.js';
import { Cover } from '../components/Cover.jsx';
import { useApi, useDebounced } from '../hooks.js';
import { Link, navigate } from '../router.jsx';
import { CorrectionChange } from './Activity.jsx';
import { Feedback, formatDate, formatSize, useAction, useGenres, useTableLabels } from './common.jsx';
import { tr, trn } from '../i18n/index.js';

/** @typedef {import('../api/types.js').Schemas} Schemas */
/** @typedef {Schemas['AdminAlbumDetail']} AdminAlbum */
/** @typedef {Schemas['Track']} Track */

/** @param {{ id: string }} props */
export function AlbumEdit({ id }) {
  const api = useApi(`/admin/albums/${id}`);
  const album = /** @type {AdminAlbum | null} */ (api.data);
  const genres = useGenres();
  const action = useAction();
  const [selected, setSelected] = useState(/** @type {Set<number>} */ (new Set()));
  const tables = useTableLabels();

  if (api.error) return <p className="empty">{api.error.status === 404 ? tr('Album introuvable.') : api.error.message}</p>;
  if (!album) return <p className="empty">{tr('Chargement…')}</p>;
  const corrected = new Set(album.activeCorrections.map((c) => `${c.entityType}:${c.entityId}:${c.field}`));

  return (
    <section className="album-edit" ref={tables}>
      <p><Link to={`/albums/${album.id}`}>{tr('← Retour à l’album')}</Link></p>
      <div className="album-head compact">
        <Cover url={album.coverUrl} size="m" alt={album.title} className="album-cover small" />
        <div>
          <span className="kind">{tr('Corriger l’album')}</span>
          <h1>{album.title}</h1>
          <p className="album-meta">{album.artistName}{album.year ? ` · ${album.year}` : ''} · {album.trackCount} pistes</p>
          <p className="path">{album.sourceDir || tr('(pas de dossier : album créé par une séparation)')}</p>
          {album.artist ? <p><Link to={`/artistes/${album.artist.id}/corriger`}>{tr('Corriger l’artiste « {nom} »', { nom: album.artist.name })}</Link></p> : null}
        </div>
      </div>
      <Feedback message={action.message} error={action.error} />

      {/* key : le formulaire repart des nouvelles valeurs après chaque enregistrement */}
      <AlbumForm key={JSON.stringify([album.title, album.artistName, album.year, album.isCompilation, album.genres])}
        album={album} genres={genres.list} action={action} corrected={corrected} onSaved={api.reload} />

      <h2>{tr('Pistes')}</h2>
      <p className="hint">{tr('Modifiez une ligne puis enregistrez-la. Les champs déjà corrigés sont soulignés. Cochez des pistes pour en faire un autre album.')}</p>
      <div className="table-wrap">
        <table className="admin-table track-edit">
          <thead><tr><th><span className="sr-only">{tr('Choisir')}</span></th><th>{tr('Disque')}</th><th>{tr('N°')}</th><th>{tr('Titre')}</th><th>{tr('Artiste')}</th><th>{tr('BPM')}</th><th>{tr('Clé')}</th><th /></tr></thead>
          <tbody>
            {album.tracks.map((t) => (
              <TrackRow key={JSON.stringify([t.id, t.title, t.artistCredit, t.discNumber, t.trackNumber, t.playback.bpm, t.playback.key])}
                track={t} action={action} corrected={corrected} onSaved={api.reload}
                selected={selected.has(t.id)} onSelect={(on) => {
                  const next = new Set(selected);
                  if (on) next.add(t.id);
                  else next.delete(t.id);
                  setSelected(next);
                }} />
            ))}
          </tbody>
        </table>
      </div>

      {selected.size ? <SplitForm album={album} trackIds={[...selected]} /> : null}
      <MergeForm album={album} />

      <h2>{tr('Corrections actives')} <small>{album.activeCorrections.length}</small></h2>
      {album.activeCorrections.length ? (
        <ul className="corrections">
          {album.activeCorrections.map((c) => (
            <li key={c.id}>
              <span>
                {c.entityType === 'track' ? <strong>{album.tracks.find((t) => t.id === c.entityId)?.title ?? tr('Piste #{id}', { id: String(c.entityId) })} : </strong> : <strong>{tr('Album :')} </strong>}
                <CorrectionChange c={c} names={genres.names} />
                <small className="block">{tr('{nom}, le {date}', { nom: c.createdBy.displayName, date: formatDate(c.createdAt) })}</small>
              </span>
              <button type="button" disabled={action.busy} onClick={async () => {
                if (await action.run(() => request('POST', `/admin/corrections/${c.id}/revert`), tr('Correction annulée : la valeur des tags revient.'))) api.reload();
              }}>{tr('Annuler')}</button>
            </li>
          ))}
        </ul>
      ) : <p className="hint">{tr('Aucune : l’album affiche les valeurs lues dans les tags.')}</p>}

      <h2>{tr('Fichiers')} <small>{album.files.length}</small></h2>
      <ul className="files">
        {album.files.map((f) => (
          <li key={f.storageKey}>
            <details>
              <summary><span className="path">{f.storageKey}</span> <small>{f.format} · {formatSize(f.sizeBytes)}</small></summary>
              <pre>{JSON.stringify(f.tags ?? {}, null, 2)}</pre>
            </details>
          </li>
        ))}
      </ul>
    </section>
  );
}

/**
 * @param {{ album: AdminAlbum, genres: Schemas['Genre'][], action: ReturnType<typeof useAction>, corrected: Set<string>, onSaved: () => void }} props
 */
function AlbumForm({ album, genres, action, corrected, onSaved }) {
  const initial = {
    title: album.title,
    albumArtist: album.artist ? album.artistName : '',
    year: album.year === null ? '' : String(album.year),
    isCompilation: album.isCompilation ?? false,
    genreIds: album.genres.map((g) => g.id),
  };
  const [f, setF] = useState(initial);
  /** @param {string} field */
  const cls = (field) => (corrected.has(`album:${album.id}:${field}`) ? 'corrected' : '');

  /** @type {Schemas['AlbumPatch']} */
  const patch = {};
  if (f.title.trim() !== initial.title) patch.title = f.title.trim();
  if (f.albumArtist.trim() !== initial.albumArtist) patch.albumArtist = f.albumArtist.trim() || null;
  if (f.year !== initial.year) patch.year = f.year === '' ? null : Number(f.year);
  if (f.isCompilation !== initial.isCompilation) patch.isCompilation = f.isCompilation;
  if (JSON.stringify(f.genreIds) !== JSON.stringify(initial.genreIds)) patch.genreIds = f.genreIds;
  const changed = Object.keys(patch).length > 0;

  return (
    <form className="admin-form album-form" onSubmit={async (e) => {
      e.preventDefault();
      if (await action.run(() => request('PATCH', `/admin/albums/${album.id}`, { body: patch }), tr('Album corrigé.'))) onSaved();
    }}>
      <label className={cls('title')}>{tr('Titre')}<input required maxLength={255} value={f.title} onChange={(e) => setF({ ...f, title: e.target.value })} /></label>
      <label className={cls('albumArtist')}>{tr('Artiste de l’album')}
        <input maxLength={255} placeholder={tr('(vide = artistes divers)')} value={f.albumArtist} onChange={(e) => setF({ ...f, albumArtist: e.target.value })} />
      </label>
      <label className={cls('year')}>{tr('Année')}<input type="number" min={1000} max={2100} value={f.year} onChange={(e) => setF({ ...f, year: e.target.value })} /></label>
      <label className={`check ${cls('isCompilation')}`}><input type="checkbox" checked={f.isCompilation} onChange={(e) => setF({ ...f, isCompilation: e.target.checked })} /> {tr('Compilation')}</label>
      <div className={`genre-picker ${cls('genreIds')}`}>
        <span>{tr('Genres')}</span>
        <div>
          {f.genreIds.map((gid) => (
            <span key={gid} className="tag removable">
              {genres.find((g) => g.id === gid)?.name ?? `#${gid}`}
              <button type="button" className="icon-btn" aria-label={tr('Retirer {nom}', { nom: genres.find((g) => g.id === gid)?.name ?? gid })}
                onClick={() => setF({ ...f, genreIds: f.genreIds.filter((x) => x !== gid) })}>×</button>
            </span>
          ))}
          <select aria-label={tr('Ajouter un genre')} value="" onChange={(e) => e.target.value && setF({ ...f, genreIds: [...f.genreIds, Number(e.target.value)] })}>
            <option value="">{tr('+ ajouter un genre')}</option>
            {genres.filter((g) => !f.genreIds.includes(g.id)).map((g) => <option key={g.id} value={g.id}>{g.name}</option>)}
          </select>
        </div>
      </div>
      <div className="form-actions">
        <button type="submit" className="primary" disabled={action.busy || !changed || !f.title.trim()}>{tr('Enregistrer l’album')}</button>
        {changed ? <button type="button" onClick={() => setF(initial)}>{tr('Rétablir')}</button> : null}
      </div>
    </form>
  );
}

/**
 * @param {{ track: Track, action: ReturnType<typeof useAction>, corrected: Set<string>, onSaved: () => void,
 *   selected: boolean, onSelect: (on: boolean) => void }} props
 */
function TrackRow({ track: t, action, corrected, onSaved, selected, onSelect }) {
  const initial = {
    discNumber: String(t.discNumber), trackNumber: t.trackNumber === null ? '' : String(t.trackNumber), title: t.title,
    artistCredit: t.artistCredit, bpm: t.playback.bpm === null ? '' : String(t.playback.bpm), key: t.playback.key ?? '',
  };
  const [f, setF] = useState(initial);
  /** @param {keyof typeof initial} k @param {string} v */
  const set = (k, v) => setF({ ...f, [k]: v });
  /** @param {string} field */
  const cls = (field) => (corrected.has(`track:${t.id}:${field}`) ? 'corrected' : '');

  /** @type {Schemas['TrackPatch']} */
  const patch = {};
  if (f.discNumber !== initial.discNumber) patch.discNumber = Number(f.discNumber);
  if (f.trackNumber !== initial.trackNumber) patch.trackNumber = f.trackNumber === '' ? null : Number(f.trackNumber);
  if (f.title.trim() !== initial.title) patch.title = f.title.trim();
  if (f.artistCredit.trim() !== initial.artistCredit) patch.artistCredit = f.artistCredit.trim() || null;
  if (f.bpm !== initial.bpm) patch.bpm = f.bpm === '' ? null : Number(f.bpm.replace(',', '.'));
  if (f.key.trim() !== initial.key) patch.key = f.key.trim() || null;
  const changed = Object.keys(patch).length > 0;
  const keyValid = f.key.trim() === '' || /^[A-G][#b]?m?$/.test(f.key.trim());

  return (
    <tr className={changed ? 'dirty' : ''}>
      <td><input type="checkbox" aria-label={tr('Choisir « {titre} »', { titre: t.title })} checked={selected} onChange={(e) => onSelect(e.target.checked)} /></td>
      <td className={cls('discNumber')}><input aria-label={tr('Disque ({titre})', { titre: t.title })} type="number" min={1} max={99} required value={f.discNumber} onChange={(e) => set('discNumber', e.target.value)} /></td>
      <td className={cls('trackNumber')}><input aria-label={tr('Numéro ({titre})', { titre: t.title })} type="number" min={0} max={999} value={f.trackNumber} onChange={(e) => set('trackNumber', e.target.value)} /></td>
      <td className={cls('title')}><input aria-label={tr('Titre ({titre})', { titre: t.title })} maxLength={255} value={f.title} onChange={(e) => set('title', e.target.value)} /></td>
      <td className={cls('artistCredit')}><input aria-label={tr('Artiste ({titre})', { titre: t.title })} maxLength={500} value={f.artistCredit} onChange={(e) => set('artistCredit', e.target.value)} /></td>
      <td className={cls('bpm')}><input aria-label={tr('BPM ({titre})', { titre: t.title })} inputMode="decimal" value={f.bpm} onChange={(e) => set('bpm', e.target.value)} /></td>
      <td className={cls('key')}><input aria-label={tr('Clé ({titre})', { titre: t.title })} maxLength={3} placeholder="Am" value={f.key} aria-invalid={!keyValid} title={tr('Notation anglaise : C, Am, F#m…')} onChange={(e) => set('key', e.target.value)} /></td>
      <td className="row-actions">
        {changed ? (
          <>
            <button type="button" className="primary" disabled={action.busy || !f.title.trim() || !f.discNumber || !keyValid} onClick={async () => {
              if (await action.run(() => request('PATCH', `/admin/tracks/${t.id}`, { body: patch }), tr('« {titre} » corrigée.', { titre: f.title.trim() }))) onSaved();
            }}>{tr('Enregistrer')}</button>
            <button type="button" className="link-btn" onClick={() => setF(initial)}>{tr('Rétablir')}</button>
          </>
        ) : null}
      </td>
    </tr>
  );
}

/**
 * Séparer : les pistes cochées deviennent un nouvel album (annulable dans Admin > Propositions).
 * @param {{ album: AdminAlbum, trackIds: number[] }} props
 */
function SplitForm({ album, trackIds }) {
  const [title, setTitle] = useState('');
  const [artist, setArtist] = useState(album.artist ? album.artistName : '');
  const [year, setYear] = useState(album.year === null ? '' : String(album.year));
  const action = useAction();
  return (
    <form className="admin-form card split-form" onSubmit={async (e) => {
      e.preventDefault();
      let createdId = 0;
      const ok = await action.run(async () => {
        const r = await request('POST', '/admin/albums', {
          body: { title: title.trim(), albumArtist: artist.trim() || null, year: year === '' ? null : Number(year), trackIds },
        });
        createdId = /** @type {{ id: number }} */ (r.data).id;
      });
      if (ok && createdId) navigate(`/albums/${createdId}/corriger`);
    }}>
      <h3>{trn(trackIds.length, 'Séparer {n} piste en un nouvel album', 'Séparer {n} pistes en un nouvel album')}</h3>
      <label>{tr('Titre du nouvel album')}<input required maxLength={255} value={title} onChange={(e) => setTitle(e.target.value)} /></label>
      <label>{tr('Artiste')}<input maxLength={255} placeholder={tr('(vide = artistes divers)')} value={artist} onChange={(e) => setArtist(e.target.value)} /></label>
      <label>{tr('Année')}<input type="number" min={1000} max={2100} value={year} onChange={(e) => setYear(e.target.value)} /></label>
      <Feedback message={action.message} error={action.error} />
      <div className="form-actions">
        <button type="submit" className="primary" disabled={action.busy || !title.trim()}>{tr('Créer l’album')}</button>
        <small>{tr('Les pistes restent liées à leurs fichiers ; un scan ne défait pas la séparation.')}</small>
      </div>
    </form>
  );
}

/**
 * Fusionner cet album dans un autre (un album réparti dans deux dossiers).
 * @param {{ album: AdminAlbum }} props
 */
function MergeForm({ album }) {
  const [q, setQ] = useState('');
  const typed = useDebounced(q.trim(), 300);
  const found = useApi(typed.length >= 2 ? '/albums' : null, { q: typed, limit: 8 });
  const results = (/** @type {{ items: Schemas['AlbumSummary'][] } | null} */ (found.data)?.items ?? []).filter((a) => a.id !== album.id);
  const action = useAction();
  return (
    <details className="merge-form">
      <summary>{tr('Fusionner cet album dans un autre')}</summary>
      <p className="hint">
        {trn(album.trackCount, 'Sa piste passe dans l’album choisi, et celui-ci disparaît de la liste. La fusion s’annule dans Admin > Propositions.', 'Ses {n} pistes passent dans l’album choisi, et celui-ci disparaît de la liste. La fusion s’annule dans Admin > Propositions.')}
      </p>
      <label>{tr('Chercher l’album qui reçoit les pistes')}<input type="search" value={q} placeholder={tr('Titre de l’album')} onChange={(e) => setQ(e.target.value)} /></label>
      <Feedback message={action.message} error={action.error} />
      {results.length ? (
        <ul className="pick-list">
          {results.map((a) => (
            <li key={a.id}>
              <span>{a.title} <small>{a.artistName}{a.year ? ` · ${a.year}` : ''} · {trn(a.trackCount, '{n} piste', '{n} pistes')}</small></span>
              <button type="button" disabled={action.busy} onClick={async () => {
                if (!window.confirm(trn(album.trackCount, 'Déplacer la piste de « {source} » dans « {cible} » ?', 'Déplacer les {n} pistes de « {source} » dans « {cible} » ?', { source: album.title, cible: a.title }))) return;
                if (await action.run(() => request('POST', `/admin/albums/${album.id}/merge`, { body: { intoAlbumId: a.id } }))) navigate(`/albums/${a.id}/corriger`);
              }}>{tr('Fusionner ici')}</button>
            </li>
          ))}
        </ul>
      ) : typed.length >= 2 && !found.loading ? <p className="empty small">{tr('Aucun autre album trouvé.')}</p> : null}
    </details>
  );
}
