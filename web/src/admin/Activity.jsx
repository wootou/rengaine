// @ts-check
// Corrections, tâches de fond (scan, analyse) et leurs erreurs, journal d'audit (CPT-07, CPT-08).
import { Fragment, useState } from 'react';
import { request } from '../api/client.js';
import { useDebounced } from '../hooks.js';
import { Link } from '../router.jsx';
import {
  ACTION, CORRECTION_STATUS, FIELD, Feedback, JOB_STATUS, JOB_TYPE, Pager, ROLE,
  formatDate, formatDuration, formatSize, formatValue, useAction, useGenres, useOffsetPage, usePolling,
} from './common.jsx';
import { useApi } from '../hooks.js';
import { labels, tr } from '../i18n/index.js';

/** @typedef {import('../api/types.js').Schemas} Schemas */

/**
 * Élément corrigé, avec un lien quand on sait où le montrer.
 * @param {{ type: string, id?: number | null, alias?: string, label?: string }} props
 */
export function EntityLink({ type, id, alias, label }) {
  if (type === 'genreAlias') return <Link to="/admin/tags">{tr('Tag « {nom} »', { nom: String(alias) })}</Link>;
  if (type === 'album') return <Link to={`/albums/${id}/corriger`}>{label ?? tr('Album #{id}', { id: String(id) })}</Link>;
  if (type === 'artist') return <Link to={`/artistes/${id}/corriger`}>{label ?? tr('Artiste #{id}', { id: String(id) })}</Link>;
  return <span>{label ?? `Piste #${id}`}</span>;
}

/** @param {{ c: Schemas['Correction'], names: Map<number, string> }} props */
export function CorrectionChange({ c, names }) {
  return (
    <span className="change">
      <span className="field">{FIELD[c.field] ?? c.field}</span>{' '}
      <del>{formatValue(c.field, c.previousValue, names)}</del> → <ins>{formatValue(c.field, c.value, names)}</ins>
    </span>
  );
}

export function Corrections() {
  const [status, setStatus] = useState('active');
  const [entityType, setEntityType] = useState('');
  const page = useOffsetPage('/admin/corrections', { status: status || undefined, entityType: entityType || undefined });
  const { names } = useGenres();
  const action = useAction();
  const items = /** @type {Schemas['Correction'][]} */ (page.items);

  return (
    <section>
      <div className="page-head">
        <h2 className="admin-title">{tr('Corrections')} {page.total !== null ? <small>{page.total}</small> : null}</h2>
        <div className="filters">
          <select aria-label={tr('Statut')} value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="">{tr('Toutes')}</option>
            {Object.entries({ proposed: tr('Proposées'), active: tr('Actives'), rejected: tr('Refusées'), reverted: tr('Annulées') }).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
          </select>
          <select aria-label={tr('Élément')} value={entityType} onChange={(e) => setEntityType(e.target.value)}>
            <option value="">{tr('Tous les éléments')}</option>
            <option value="album">{tr('Albums')}</option>
            <option value="track">{tr('Pistes')}</option>
            <option value="artist">{tr('Artistes')}</option>
            <option value="genreAlias">{tr('Tags de genre')}</option>
          </select>
        </div>
      </div>
      <p className="hint">{tr('Une correction survit aux scans. L’annuler rend la valeur lue dans les tags.')}</p>
      <Feedback message={action.message} error={action.error} />
      <div className="table-wrap">
        <table className="admin-table">
          <thead><tr><th>{tr('Date')}</th><th>{tr('Élément')}</th><th>{tr('Changement')}</th><th>{tr('Par')}</th><th>{tr('Statut')}</th><th /></tr></thead>
          <tbody>
            {items.map((c) => (
              <tr key={c.id}>
                <td>{formatDate(c.createdAt)}</td>
                <td><EntityLink type={c.entityType} id={c.entityId} alias={c.alias} />{c.changeSetId ? <small className="block"><Link to={`/admin/lots/${c.changeSetId}`}>{tr('lot n° {n}', { n: c.changeSetId })}</Link></small> : null}</td>
                <td><CorrectionChange c={c} names={names} /></td>
                <td>{c.createdBy.displayName}{c.createdBy.role === 'agent' ? ' (agent)' : ''}</td>
                <td><span className={`pill correction-${c.status}`}>{CORRECTION_STATUS[c.status]}</span>{c.revertedAt ? <small className="block">{formatDate(c.revertedAt)}</small> : null}</td>
                <td className="row-actions">
                  {c.status === 'active' ? (
                    <button type="button" disabled={action.busy} onClick={async () => {
                      if (await action.run(() => request('POST', `/admin/corrections/${c.id}/revert`), tr('Correction annulée.'))) page.reload();
                    }}>{tr('Annuler')}</button>
                  ) : null}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {!page.loading && items.length === 0 ? <p className="empty">{tr('Aucune correction.')}</p> : null}
      <Pager total={page.total} offset={page.offset} limit={page.limit} onChange={page.setOffset} />
    </section>
  );
}

/** Compteurs d'une tâche (« vus 297 · ajoutés 3 »). */
const STAT = /** @type {Record<string, string>} */ (labels(() => ({
  seen: tr('vus'), unchanged: tr('inchangés'), new: tr('nouveaux'), changed: tr('modifiés'), missing: tr('disparus'), reappeared: tr('revenus'), probed: tr('lus'),
  errors: tr('erreurs'), tracks_new: tr('pistes ajoutées'), albums_new: tr('albums ajoutés'), albums_rebuilt: tr('albums recalculés'), albums_missing: tr('albums disparus'),
  folders: tr('dossiers'), filed: tr('rangés'), waiting: tr('en attente'), incomplete: tr('incomplets'), blocked: tr('bloqués'), files_moved: tr('fichiers déplacés'),
  analyzed: tr('analysées'), hashed: tr('hachés'), silent: tr('silencieuses'), failed: tr('échecs'), skipped: tr('ignorés'), requeued: tr('remises en file'), created: tr('créés'),
  mp3_moved: tr('MP3 mis à la corbeille'), mp3_kept: tr('MP3 gardés'), bytes_freed: tr('octets libérés'),
})));

/** @param {{ stats: Record<string, number> | null | undefined }} props */
function Stats({ stats }) {
  if (!stats) return null;
  const parts = Object.entries(stats).filter(([k, v]) => typeof v === 'number' && k !== 'duration_s');
  if (!parts.length) return null;
  return <small className="block">{parts.map(([k, v]) => `${STAT[k] ?? k} ${v}`).join(' · ')}</small>;
}

export function Jobs() {
  const [type, setType] = useState('');
  const page = useOffsetPage('/admin/jobs', { type: type || undefined }, 20);
  const items = /** @type {Schemas['Job'][]} */ (page.items);
  const busy = items.some((j) => j.status === 'queued' || j.status === 'running');
  usePolling(page.reload, busy, 5000);
  const action = useAction();
  const [prefix, setPrefix] = useState('');
  const [force, setForce] = useState(false);

  /** @param {Schemas['JobInput']} body @param {string} ok */
  const launch = async (body, ok) => {
    if (await action.run(() => request('POST', '/admin/jobs', { body }), ok)) page.reload();
  };

  return (
    <section>
      <div className="page-head"><h2 className="admin-title">{tr('Tâches')}</h2></div>
      <div className="job-launch card">
        <form className="admin-form inline" onSubmit={(e) => {
          e.preventDefault();
          /** @type {Record<string, unknown>} */
          const params = {};
          if (prefix.trim()) params.prefix = prefix.trim().replace(/^\/+/, '');
          if (force) params.force = true;
          void launch({ type: 'index', params }, tr('Scan demandé : il démarre dans la minute.'));
        }}>
          <label>{tr('Dossier (vide = toute la bibliothèque)')}<input placeholder={tr('Artiste/Album')} value={prefix} onChange={(e) => setPrefix(e.target.value)} /></label>
          <label className="check"><input type="checkbox" checked={force} onChange={(e) => setForce(e.target.checked)} /> {tr('Relire aussi les fichiers inchangés')}</label>
          <button type="submit" className="primary" disabled={action.busy}>{tr('Lancer un scan')}</button>
        </form>
        <div className="admin-form inline">
          <button type="button" disabled={action.busy} onClick={() => void launch({ type: 'analyze' }, tr('Analyse demandée : elle démarre dans la minute.'))}>{tr('Lancer l’analyse audio')}</button>
          <small>{tr('Mesure le volume et les silences des pistes nouvelles ou modifiées. Elle tourne aussi toute seule toutes les 10 minutes.')}</small>
        </div>
        <Feedback message={action.message} error={action.error} />
      </div>

      <InboxPanel busy={busy} onLaunch={() => void launch({ type: 'inbox' }, tr('Rangement demandé : il démarre dans la minute.'))} launching={action.busy} />

      <div className="page-head">
        <h3>{tr('Historique')} {busy ? <small>{tr('actualisé toutes les 5 s')}</small> : null}</h3>
        <select aria-label={tr('Type de tâche')} value={type} onChange={(e) => setType(e.target.value)}>
          <option value="">{tr('Toutes')}</option>
          {Object.entries(JOB_TYPE).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
        </select>
      </div>
      <div className="table-wrap">
        <table className="admin-table">
          <thead><tr><th>{tr('N°')}</th><th>{tr('Tâche')}</th><th>{tr('Statut')}</th><th>{tr('Demandée')}</th><th>{tr('Durée')}</th><th>{tr('Par')}</th></tr></thead>
          <tbody>
            {items.map((j) => (
              <tr key={j.id}>
                <td>{j.id}</td>
                <td>
                  {JOB_TYPE[j.type]}
                  {j.params?.prefix ? <small className="block">{String(j.params.prefix)}</small> : null}
                  {j.params?.force ? <small className="block">{tr('relecture complète')}</small> : null}
                  <Stats stats={j.stats} />
                </td>
                <td><span className={`pill job-${j.status}`}>{JOB_STATUS[j.status]}</span></td>
                <td>{formatDate(j.createdAt)}</td>
                <td>{j.status === 'running' ? tr('en cours') : formatDuration(j.startedAt, j.finishedAt)}</td>
                <td>{j.requestedBy ? j.requestedBy.displayName : <small>{tr('automatique')}</small>}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {!page.loading && items.length === 0 ? <p className="empty">{tr('Aucune tâche.')}</p> : null}
      <Pager total={page.total} offset={page.offset} limit={page.limit} onChange={page.setOffset} />
      <JobErrors />
    </section>
  );
}

const INBOX_STATUS = /** @type {Record<Schemas['InboxItem']['status'], string>} */ (labels(() => ({
  waiting: tr('En attente'), incomplete: tr('Incomplet'), blocked: tr('À ranger à la main'), filed: tr('Rangé'),
})));
const INBOX_CLASS = { waiting: 'job-queued', incomplete: 'job-queued', blocked: 'job-failed', filed: 'job-done' };

/**
 * Albums déposés dans inbox/ : en attente, bloqués (avec la raison) ou rangés (30 derniers jours).
 * @param {{ busy: boolean, launching: boolean, onLaunch: () => void }} props
 */
function InboxPanel({ busy, launching, onLaunch }) {
  const api = useApi('/admin/inbox');
  usePolling(api.reload, busy, 5000);
  const items = /** @type {{ items: Schemas['InboxItem'][] } | null} */ (api.data)?.items ?? [];
  const pending = items.filter((i) => i.status !== 'filed').length;
  return (
    <>
      <div className="page-head">
        <h3>{tr('Albums déposés')} {pending ? <small>{tr('{n} en attente', { n: pending })}</small> : null}</h3>
        <button type="button" disabled={launching} onClick={onLaunch}>{tr('Ranger maintenant')}</button>
      </div>
      <p className="hint">
        {tr('Déposez un dossier par album dans')} <code>inbox/</code> {tr('(rclone, Nextcloud…). Quand rien n’y a bougé pendant 10 minutes, il est rangé d’après ses tags sous')}{' '}
        <code>{tr('Artiste/Album (année)/01 - Titre')}</code>{tr(', puis ajouté à la bibliothèque. C’est vérifié à chaque scan (toutes les 15 minutes). Rien n’est jamais écrasé : un cas douteux reste dans le dépôt, avec sa raison.')}
      </p>
      {items.length ? (
        <div className="table-wrap">
          <table className="admin-table">
            <thead><tr><th>{tr('Dossier déposé')}</th><th>{tr('État')}</th><th>{tr('Rangement')}</th><th>{tr('Fichiers')}</th><th>{tr('Date')}</th></tr></thead>
            <tbody>
              {items.map((i) => (
                <tr key={i.id}>
                  <td className="path">{i.sourceDir.replace(/^inbox\//, '')}</td>
                  <td><span className={`pill ${INBOX_CLASS[i.status]}`}>{INBOX_STATUS[i.status]}</span></td>
                  <td>
                    {i.targetDir ? <span className="path">{i.targetDir}</span> : '—'}
                    {i.message ? <small className="block">{i.message}</small> : null}
                  </td>
                  <td>{i.fileCount}<small className="block">{formatSize(i.sizeBytes)}</small></td>
                  <td>{formatDate(i.filedAt ?? i.updatedAt)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (!api.loading ? <p className="empty">{tr('Le dépôt est vide.')}</p> : null)}
    </>
  );
}

function JobErrors() {
  const [open, setOpen] = useState(true);
  const page = useOffsetPage('/admin/job-errors', { open }, 30);
  const items = /** @type {Schemas['JobError'][]} */ (page.items);
  const action = useAction();
  /** @param {{ ids: number[] } | { all: true }} body */
  const resolve = async (body) => {
    if (await action.run(() => request('POST', '/admin/job-errors/resolve', { body }))) page.reload();
  };
  return (
    <>
      <div className="page-head">
        <h3>{tr('Erreurs de lecture des fichiers')} {page.total !== null ? <small>{page.total}</small> : null}</h3>
        <label className="check"><input type="checkbox" checked={!open} onChange={(e) => setOpen(!e.target.checked)} /> {tr('Montrer aussi les erreurs résolues')}</label>
        {open && page.total ? (
          <button type="button" disabled={action.busy} onClick={() => {
            if (window.confirm(tr('Marquer les {n} erreurs en cours comme résolues ?', { n: page.total ?? 0 }))) resolve({ all: true });
          }}>{tr('Tout marquer comme résolu')}</button>
        ) : null}
      </div>
      <p className="hint">{tr('Une erreur se clôt d’elle-même quand le fichier est relu sans erreur, analysé, rangé ou supprimé.')}</p>
      <Feedback message={action.message} error={action.error} />
      {items.length ? (
        <div className="table-wrap">
          <table className="admin-table">
            <thead><tr><th>{tr('Fichier')}</th><th>{tr('Erreur')}</th><th>{tr('Tâche')}</th><th>{tr('Date')}</th><th /></tr></thead>
            <tbody>
              {items.map((e) => (
                <tr key={e.id} className={e.resolvedAt ? 'resolved' : ''}>
                  <td className="path">{e.storageKey ?? '—'}</td>
                  <td><code>{e.code}</code><small className="block">{e.message}</small></td>
                  <td>{e.jobId}</td>
                  <td>{formatDate(e.createdAt)}{e.resolvedAt ? <small className="block">{tr('résolue le {date}', { date: formatDate(e.resolvedAt) })}</small> : null}</td>
                  <td className="row-actions">
                    {e.resolvedAt ? null : (
                      <button type="button" className="link-btn" disabled={action.busy} onClick={() => resolve({ ids: [e.id] })}>{tr('Marquer comme résolue')}</button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (!page.loading ? <p className="empty">{open ? tr('Aucune erreur en cours.') : tr('Aucune erreur.')}</p> : null)}
      <Pager total={page.total} offset={page.offset} limit={page.limit} onChange={page.setOffset} />
    </>
  );
}

const actionGroups = () => [['', tr('Toutes')], ['user.', tr('Comptes')], ['auth.', tr('Connexions')], ['token.', tr('Appareils')], ['album.', tr('Albums')], ['track.', tr('Pistes')],
  ['artist.', tr('Artistes')], ['correction.', tr('Corrections')], ['change_set.', tr('Lots')], ['genre', tr('Genres')], ['job', tr('Tâches')], ['server.', tr('Serveur')]];

/** Cible d'un événement, avec un lien quand il y a une page. @param {{ e: Schemas['AuditEntry'] }} props */
function Target({ e }) {
  if (!e.targetType) return null;
  if (e.targetType === 'user' && e.targetId) return <Link to={`/admin/comptes/${e.targetId}`}>{tr('Compte #{id}', { id: e.targetId })}</Link>;
  if ((e.targetType === 'album' || e.targetType === 'track' || e.targetType === 'artist') && e.targetId) return <EntityLink type={e.targetType} id={e.targetId} />;
  if (e.targetType === 'change_set' && e.targetId) return <Link to={`/admin/lots/${e.targetId}`}>{tr('Lot n° {n}', { n: e.targetId })}</Link>;
  return <span>{e.targetType}{e.targetId ? ` #${e.targetId}` : ''}</span>;
}

export function Audit() {
  const [group, setGroup] = useState('');
  const [text, setText] = useState('');
  const typed = useDebounced(text.trim(), 300);
  const page = useOffsetPage('/admin/audit', { action: typed || group || undefined });
  const items = /** @type {Schemas['AuditEntry'][]} */ (page.items);
  const [openId, setOpenId] = useState(/** @type {number | null} */ (null));

  return (
    <section>
      <div className="page-head">
        <h2 className="admin-title">{tr('Journal')} {page.total !== null ? <small>{page.total}</small> : null}</h2>
        <div className="filters">
          <select aria-label={tr('Type d’action')} value={group} onChange={(e) => { setGroup(e.target.value); setText(''); }}>
            {actionGroups().map(([k, v]) => <option key={k} value={k}>{v}</option>)}
          </select>
          <input type="search" placeholder={tr('Action (ex. user.approve)')} aria-label={tr('Action')} value={text} onChange={(e) => setText(e.target.value)} />
        </div>
      </div>
      <div className="table-wrap">
        <table className="admin-table">
          <thead><tr><th>{tr('Date')}</th><th>{tr('Qui')}</th><th>{tr('Action')}</th><th>{tr('Sur')}</th><th>{tr('IP')}</th><th /></tr></thead>
          <tbody>
            {items.map((e) => (
              <Fragment key={e.id}>
                <tr>
                  <td>{formatDate(e.createdAt)}</td>
                  <td>{e.actor ? <>{e.actor.displayName}{e.actor.role !== 'user' ? <small> ({ROLE[e.actor.role]})</small> : null}</> : <small>{tr('système')}</small>}</td>
                  <td>{ACTION[e.action] ?? e.action}<small className="block">{e.action}</small></td>
                  <td><Target e={e} /></td>
                  <td><small>{e.ip ?? ''}</small></td>
                  <td className="row-actions">
                    {e.before != null || e.after != null ? (
                      <button type="button" className="link-btn" aria-expanded={openId === e.id} onClick={() => setOpenId(openId === e.id ? null : e.id)}>
                        {openId === e.id ? tr('Masquer') : tr('Détails')}
                      </button>
                    ) : null}
                  </td>
                </tr>
                {openId === e.id ? (
                  <tr className="details">
                    <td colSpan={6}>
                      <div className="before-after">
                        <div><strong>{tr('Avant')}</strong><pre>{JSON.stringify(e.before ?? null, null, 2)}</pre></div>
                        <div><strong>{tr('Après')}</strong><pre>{JSON.stringify(e.after ?? null, null, 2)}</pre></div>
                      </div>
                      {e.requestId ? <small>{tr('Requête {id}', { id: e.requestId })}</small> : null}
                    </td>
                  </tr>
                ) : null}
              </Fragment>
            ))}
          </tbody>
        </table>
      </div>
      {!page.loading && items.length === 0 ? <p className="empty">{tr('Aucun événement.')}</p> : null}
      <Pager total={page.total} offset={page.offset} limit={page.limit} onChange={page.setOffset} />
    </section>
  );
}
