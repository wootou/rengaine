// @ts-check
// MP3 en double (LEC-05) : albums dont des pistes existent aussi en sans perte. L'admin retire les MP3
// album par album ; ils partent dans la corbeille du stockage (tâche `dedup`), pas à la poubelle.
// Même enregistrement ailleurs (BAS-03) : pistes reconnues par l'empreinte acoustique, quels que soient
// les tags ; un signalement pour la migration, rien n'est retiré d'ici.
// Fichiers abîmés : trames illisibles relevées par la mesure (accroc à l'écoute), à savoir avant la migration.
import { Fragment, useEffect, useState } from 'react';
import { get, request } from '../api/client.js';
import { controller } from '../engine/controller.js';
import { formatTime } from '../engine/playback.js';
import { useApi } from '../hooks.js';
import { Link } from '../router.jsx';
import { Feedback, formatSize, useAction, usePolling } from './common.jsx';
import { tr, trn } from '../i18n/index.js';

/** @typedef {import('../api/types.js').Schemas} Schemas */

export function Duplicates() {
  const api = useApi('/admin/duplicates');
  const data = /** @type {{ items: Schemas['DuplicateAlbum'][], removableBytes: number } | null} */ (api.data);
  const items = data?.items ?? [];
  const [open, setOpen] = useState(/** @type {number | null} */ (null));
  /** Albums dont le retrait est demandé : la liste se relit jusqu'à ce qu'ils en sortent. */
  const [pending, setPending] = useState(/** @type {number[]} */ ([]));
  const waiting = pending.filter((id) => items.some((a) => a.albumId === id && a.removable > 0));
  usePolling(api.reload, waiting.length > 0, 5000);
  const action = useAction();

  /** @param {Schemas['DuplicateAlbum']} a */
  const remove = async (a) => {
    const name = `${a.artist ? `${a.artist} – ` : ''}${a.title}`;
    if (!window.confirm(tr('Retirer {n} MP3 de « {nom} » ({taille}) ?', { n: a.removable, nom: name, taille: formatSize(a.removableBytes) }) + '\n\n'
      + tr('Le FLAC reste et sera servi à leur place. Les MP3 vont dans la corbeille du stockage, d’où on peut les remettre.'))) return;
    const ok = await action.run(() => request('POST', '/admin/jobs', { body: { type: 'dedup', params: { albumId: a.albumId } } }),
      tr('Retrait demandé pour « {nom} » : il se fait dans la minute.', { nom: name }));
    if (ok) setPending((p) => [...p, a.albumId]);
  };

  return (
    <section>
      <div className="page-head">
        <h2>{tr('MP3 en double')} {data ? <small>{trn(items.length, '{n} album', '{n} albums')} · {tr('{taille} à libérer', { taille: formatSize(data.removableBytes) })}</small> : null}</h2>
      </div>
      <p className="hint">
        {tr('Quand une piste existe en FLAC et en MP3, c’est le FLAC qui est servi (converti à la volée pour le téléphone) : le MP3 ne fait que prendre de la place. Un MP3 n’est retirable que si son FLAC est là et dure pareil, à 2 secondes près ; sinon il est gardé (souvent une autre version du morceau). Les MP3 retirés vont dans la corbeille du stockage')}{' '}
        (<code>corbeille/</code>){tr(', d’où')} <code>rengaine corbeille:restaurer</code> {tr('les remet en place.')}
      </p>
      <Feedback message={action.message} error={action.error} />
      {items.length ? (
        <div className="table-wrap">
          <table className="admin-table">
            <thead><tr><th>{tr('Album')}</th><th>{tr('Retirables')}</th><th>{tr('Gardés')}</th><th>{tr('Place')}</th><th><span className="sr-only">{tr('Actions')}</span></th></tr></thead>
            <tbody>
              {items.map((a) => (
                <Fragment key={a.albumId}>
                  <tr>
                    <td><Link to={`/albums/${a.albumId}`}>{a.title}</Link>{a.artist ? <small className="block">{a.artist}</small> : null}</td>
                    <td>{a.removable}</td>
                    <td>{a.kept}</td>
                    <td>{formatSize(a.removableBytes)}</td>
                    <td className="row-actions">
                      <button type="button" className="link-btn" aria-expanded={open === a.albumId} onClick={() => setOpen(open === a.albumId ? null : a.albumId)}>
                        {open === a.albumId ? tr('Masquer') : tr('Détail')}
                      </button>
                      {a.removable > 0 ? (
                        <button type="button" disabled={action.busy || waiting.includes(a.albumId)} onClick={() => void remove(a)}>
                          {waiting.includes(a.albumId) ? tr('Retrait en cours…') : tr('Retirer {n} MP3', { n: a.removable })}
                        </button>
                      ) : null}
                    </td>
                  </tr>
                  {open === a.albumId ? <tr className="details"><td colSpan={5}><AlbumFiles key={a.removable} id={a.albumId} /></td></tr> : null}
                </Fragment>
              ))}
            </tbody>
          </table>
        </div>
      ) : (!api.loading ? <p className="empty">{tr('Aucun MP3 en double.')}</p> : null)}
      <SameRecordings />
      <DamagedTracks />
    </section>
  );
}

/** Même enregistrement dans plusieurs pistes (empreinte acoustique, BAS-03). */
function SameRecordings() {
  const [minScore, setMinScore] = useState(0.8);
  const [groups, setGroups] = useState(/** @type {Schemas['SameRecordingGroup'][]} */ ([]));
  const [info, setInfo] = useState(/** @type {{ total: number, fingerprinted: number, pending: number } | null} */ (null));
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  /** @param {number} offset */
  const load = async (offset) => {
    setLoading(true);
    setError('');
    try {
      const page = /** @type {any} */ (await get('/admin/same-recordings', { query: { minScore, limit: 50, offset } }));
      setGroups((cur) => (offset ? [...cur, ...page.items] : page.items));
      setInfo({ total: page.total, fingerprinted: page.fingerprinted, pending: page.pending });
    } catch (e) {
      setError(e instanceof Error ? e.message : tr('Lecture impossible'));
    } finally {
      setLoading(false);
    }
  };
  useEffect(() => {
    void load(0);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [minScore]);

  return (
    <section className="same-recordings">
      <div className="page-head">
        <h2>{tr('Même enregistrement ailleurs')} {info ? <small>{trn(info.total, '{n} groupe', '{n} groupes')}</small> : null}</h2>
        <div className="filters">
          <select aria-label={tr('Ressemblance')} value={minScore} onChange={(e) => setMinScore(Number(e.target.value))}>
            <option value={0.8}>{tr('Identiques et très proches')}</option>
            <option value={0.95}>{tr('Identiques seulement')}</option>
          </select>
        </div>
      </div>
      <p className="hint">
        {tr('Reconnus à l’oreille, par l’empreinte acoustique des deux premières minutes, quels que soient le titre, l’album, le format ou un décalage du début. « Identique » : le même enregistrement encodé autrement ; « très proche » : une autre extraction ou une version remasterisée. Rien n’est retiré d’ici : c’est un repère pour la migration.')}
        {info ? ` ${info.pending ? trn(info.fingerprinted, '{n} piste analysée, {attente} en attente.', '{n} pistes analysées, {attente} en attente.', { attente: info.pending }) : trn(info.fingerprinted, '{n} piste analysée.', '{n} pistes analysées.')}` : ''}
      </p>
      {error ? <p className="form-error" role="alert">{error}</p> : null}
      {groups.map((g) => (
        <div className="same-group" key={g.tracks.map((t) => t.id).join('-')}>
          <p className="same-head">
            <span className={`pill ${g.verdict === 'same' ? 'job-done' : 'job-queued'}`}>{g.verdict === 'same' ? tr('Identique') : tr('Très proche')}</span>
            <small>{tr('ressemblance {n} %', { n: Math.round(g.score * 100) })}</small>
          </p>
          <ul>
            {g.tracks.map((t, i) => (
              <li key={t.id}>
                <span className="same-track">
                  <strong>{t.title}</strong>
                  <small className="block">
                    {t.artistCredit} · <Link to={`/albums/${t.album.id}`}>{t.album.title}</Link> · {formatTime(t.durationMs)}
                    {t.formats[0] ? ` · ${t.formats[0].format.toUpperCase()}${t.formats[0].bitrateKbps ? ` ${t.formats[0].bitrateKbps} kbit/s` : ''}` : ''}
                  </small>
                </span>
                <button type="button" className="link-btn" onClick={() => controller.playTracks(g.tracks, i)}>{tr('Écouter')}</button>
              </li>
            ))}
          </ul>
        </div>
      ))}
      {!loading && info && groups.length === 0 ? <p className="empty">{tr('Aucun enregistrement en double.')}</p> : null}
      <div className="pager">
        {loading ? <span>{tr('Lecture…')}</span> : info && groups.length < info.total ? (
          <button type="button" onClick={() => void load(groups.length)}>{tr('Plus de groupes')}</button>
        ) : null}
      </div>
    </section>
  );
}

/** Pistes d'un album : retirable ou gardée, et pourquoi. @param {{ id: number }} props */
function AlbumFiles({ id }) {
  const api = useApi(`/admin/duplicates/${id}`);
  const d = /** @type {Schemas['DuplicateAlbumDetail'] | null} */ (api.data);
  if (!d) return api.loading ? <p className="hint">{tr('Chargement…')}</p> : null;
  if (!d.files.length) return <p className="hint">{tr('Plus de MP3 en double dans cet album.')}</p>;
  return (
    <ul className="dup-files">
      {d.files.map((f) => (
        <li key={f.mp3.storageKey} className={f.removable ? '' : 'kept'}>
          <span className={`pill ${f.removable ? 'job-done' : 'job-queued'}`}>{f.removable ? tr('Retirable') : tr('Gardé')}</span>
          <span>
            {f.trackNumber ? `${f.trackNumber}. ` : ''}{f.title}
            <small className="block">
              MP3 {f.mp3.bitrateKbps ? `${f.mp3.bitrateKbps} kbit/s, ` : ''}{formatSize(f.mp3.sizeBytes)}, {fmt(f.mp3.durationMs)}
              {' · '}{f.lossless.format.toUpperCase()} {fmt(f.lossless.durationMs)}
              {f.reason ? ` · ${f.reason}` : ''}
            </small>
          </span>
        </li>
      ))}
    </ul>
  );
}

/** @param {number} ms */
function fmt(ms) {
  const s = Math.round(ms / 1000);
  return `${Math.floor(s / 60)} min ${String(s % 60).padStart(2, '0')}`;
}

/** Pistes dont le fichier servi a des trames illisibles. */
function DamagedTracks() {
  const api = useApi('/admin/damaged-tracks', { limit: 200 });
  const d = /** @type {{ items: Schemas['DamagedTrack'][], total: number, checked: number, pending: number } | null} */ (api.data);
  return (
    <section className="damaged-tracks">
      <div className="page-head">
        <h2>{tr('Fichiers abîmés')} {d ? <small>{trn(d.total, '{n} piste', '{n} pistes')}</small> : null}</h2>
      </div>
      <p className="hint">
        {tr('Des trames que le décodeur n’a pas pu lire : le lecteur les saute, mais on entend un accroc à cet endroit. Relevé pendant la mesure du volume, sur le fichier servi ; une fin de fichier coupée (inaudible) n’est pas comptée. Avant la migration, mieux vaut garder une autre copie de ces morceaux s’il y en a une.')}
        {d ? ` ${d.pending ? trn(d.checked, '{n} piste vérifiée, {attente} en attente.', '{n} pistes vérifiées, {attente} en attente.', { attente: d.pending }) : trn(d.checked, '{n} piste vérifiée.', '{n} pistes vérifiées.')}` : ''}
      </p>
      {d && d.items.length ? (
        <ul className="damaged-list">
          {d.items.map((x) => (
            <li key={x.track.id}>
              <span className="same-track">
                <strong>{x.track.title}</strong>
                <small className="block">
                  {x.track.artistCredit} · <Link to={`/albums/${x.track.album.id}`}>{x.track.album.title}</Link>
                  {' · '}{trn(x.errors, '{n} trame', '{n} trames')}{x.detail ? ` ${x.detail}` : ''}
                </small>
                <small className="block"><code>{x.storageKey}</code></small>
              </span>
              <button type="button" className="link-btn" onClick={() => controller.playTracks([x.track], 0)}>{tr('Écouter')}</button>
            </li>
          ))}
        </ul>
      ) : (d && !api.loading ? <p className="empty">{tr('Aucun fichier abîmé.')}</p> : null)}
    </section>
  );
}
