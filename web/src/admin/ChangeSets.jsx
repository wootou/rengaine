// @ts-check
// Lots de changements (IA-02) : propositions de l'agent à appliquer ou refuser, et fusions ou
// séparations faites par l'admin, annulables d'un coup.
import { useEffect, useRef, useState } from 'react';
import { request } from '../api/client.js';
import { useApi } from '../hooks.js';
import { Link } from '../router.jsx';
import { EntityLink } from './Activity.jsx';
import {
  adminEvents, CHANGE_SET_STATUS, CORRECTION_STATUS, FIELD, Feedback, JOB_STATUS, Pager, formatDate, formatValue, useAction, useGenres, useOffsetPage,
  usePolling,
} from './common.jsx';
import { tr, trn } from '../i18n/index.js';

/** @typedef {import('../api/types.js').Schemas} Schemas */
/** @typedef {NonNullable<Schemas['ChangeSet']['changes']>[number]} Change */

/** @param {{ status: Schemas['ChangeSetStatus'] }} props */
function SetStatus({ status }) {
  return <span className={`pill set-${status}`}>{CHANGE_SET_STATUS[status]}</span>;
}

/** @param {{ user: Schemas['UserRef'] }} props */
function Author({ user }) {
  return <>{user.displayName}{user.role === 'agent' ? <small> ({tr('agent')})</small> : null}</>;
}

export function ChangeSets() {
  const [status, setStatus] = useState('pending');
  const page = useOffsetPage('/admin/change-sets', { status: status || undefined }, 30);
  const items = /** @type {Schemas['ChangeSet'][]} */ (page.items);
  return (
    <section>
      <div className="page-head">
        <h2 className="admin-title">{tr('Propositions et lots')} {page.total !== null ? <small>{page.total}</small> : null}</h2>
        <select aria-label={tr('Statut du lot')} value={status} onChange={(e) => setStatus(e.target.value)}>
          <option value="">{tr('Tous')}</option>
          {Object.entries(CHANGE_SET_STATUS).filter(([k]) => k !== 'draft').map(([k, v]) => <option key={k} value={k}>{v}</option>)}
        </select>
      </div>
      <p className="hint">
        {tr('L’agent propose des changements par lots : rien ne change avant que vous appliquiez le lot. Les fusions et séparations faites depuis « Corriger » forment aussi un lot, qu’on peut annuler d’un coup.')}
      </p>
      <AskAi onDone={page.reload} />
      <div className="table-wrap">
        <table className="admin-table">
          <thead><tr><th>{tr('N°')}</th><th>{tr('Lot')}</th><th>{tr('Par')}</th><th>{tr('Date')}</th><th>{tr('Changements')}</th><th>{tr('Statut')}</th></tr></thead>
          <tbody>
            {items.map((s) => (
              <tr key={s.id}>
                <td>{s.id}</td>
                <td><Link to={`/admin/lots/${s.id}`}>{s.title}</Link>{s.description ? <small className="block">{s.description}</small> : null}</td>
                <td><Author user={s.createdBy} /></td>
                <td>{formatDate(s.createdAt)}</td>
                <td>{s.changeCount}</td>
                <td><SetStatus status={s.status} /></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {!page.loading && items.length === 0 ? <p className="empty">{status === 'pending' ? tr('Aucune proposition en attente.') : tr('Aucun lot.')}</p> : null}
      <Pager total={page.total} offset={page.offset} limit={page.limit} onChange={page.setOffset} />
    </section>
  );
}

/**
 * Réorganisation par IA (IA-04) : l'admin écrit une consigne, le modèle configuré sur le serveur
 * (section [ia] de rengaine.ini) travaille en tâche de fond et propose des lots, listés ci-dessous.
 * @param {{ onDone: () => void }} props
 */
function AskAi({ onDone }) {
  const settings = useApi('/admin/server-settings');
  const assistant = /** @type {Schemas['ServerSettings'] | null} */ (settings.data)?.assistant ?? null;
  const jobs = useApi(assistant ? '/admin/jobs' : null, { type: 'organize', limit: 3 });
  const list = /** @type {{ items: Schemas['Job'][] } | null} */ (jobs.data)?.items ?? [];
  const working = list.some((j) => j.status === 'queued' || j.status === 'running');
  const [instructions, setInstructions] = useState('');
  const action = useAction();
  const wasWorking = useRef(false);
  usePolling(jobs.reload, working, 5000);
  // Tâche finie : ses lots arrivent dans la liste
  useEffect(() => {
    if (wasWorking.current && !working) onDone();
    wasWorking.current = working;
  }, [working, onDone]);

  if (!settings.data) return null;
  if (!assistant) {
    return (
      <details className="ask-ai">
        <summary>{tr('Demander à une IA')}</summary>
        <p className="hint">{tr('Deux façons de confier la bibliothèque à une IA : brancher un client MCP (Claude Code, ou tout autre client compatible) avec un jeton de compte agent, créé dans Comptes ; ou ajouter une clé API dans la section [ia] de rengaine.ini pour lancer le travail d’ici.')}</p>
      </details>
    );
  }
  return (
    <div className="card ask-ai">
      <form className="admin-form" onSubmit={async (e) => {
        e.preventDefault();
        if (await action.run(() => request('POST', '/admin/jobs', { body: { type: 'organize', params: { instructions: instructions.trim() } } }),
          tr('Demande envoyée : l’IA travaille en tâche de fond, ses propositions arriveront dans cette liste.'))) {
          setInstructions('');
          jobs.reload();
        }
      }}>
        <label>{tr('Demander à une IA')} <small>{assistant.provider} · {assistant.model}</small>
          <textarea rows={3} maxLength={4000} required value={instructions} onChange={(e) => setInstructions(e.target.value)}
            placeholder={tr('Ex. : rattache les genres des tags qui ne le sont pas encore, et repère les artistes en double.')} />
        </label>
        <p className="hint">{tr('L’IA lit la bibliothèque et ne fait que proposer : rien ne change avant que vous appliquiez un lot.')}</p>
        <div className="form-actions">
          <button type="submit" className="primary" disabled={action.busy || working || !instructions.trim()}>{tr('Lancer')}</button>
        </div>
        <Feedback message={action.message} error={action.error} />
      </form>
      {list.length > 0 ? (
        <ul className="ai-jobs">
          {list.map((j) => (
            <li key={j.id}>
              <span className={`pill job-${j.status}`}>{JOB_STATUS[j.status]}</span> <small>{formatDate(j.createdAt)}</small>
              <p className="ai-ask">{String(j.params?.instructions ?? '')}</p>
              {j.summary ? <p className="ai-summary">{j.summary}</p> : null}
            </li>
          ))}
        </ul>
      ) : null}
    </div>
  );
}

/** Valeur actuelle différente de celle d'avant : quelqu'un a changé l'élément depuis la proposition. */
function changedSince(/** @type {Change} */ c) {
  return JSON.stringify(c.currentValue ?? null) !== JSON.stringify(c.previousValue ?? null);
}

/** @param {{ id: string }} props */
export function ChangeSetDetail({ id }) {
  const api = useApi(`/admin/change-sets/${id}`);
  const set = /** @type {Schemas['ChangeSet'] | null} */ (api.data);
  const { names } = useGenres();
  const action = useAction();

  if (api.error) return <p className="empty">{api.error.status === 404 ? tr('Lot introuvable.') : api.error.message}</p>;
  if (!set) return <p className="empty">{tr('Chargement…')}</p>;
  const changes = set.changes ?? [];
  const pending = set.status === 'pending';

  /** @param {'apply' | 'reject' | 'revert'} act @param {string} ok @param {string} [confirmText] */
  const decide = async (act, ok, confirmText) => {
    if (confirmText && !window.confirm(confirmText)) return;
    if (await action.run(() => request('POST', `/admin/change-sets/${set.id}/${act}`), ok)) {
      api.reload();
      adminEvents.set({ sets: adminEvents.getState().sets + 1 });
    }
  };

  return (
    <section className="change-set">
      <p><Link to="/admin/lots">{tr('← Tous les lots')}</Link></p>
      <div className="page-head">
        <h2 className="admin-title">{set.title} <SetStatus status={set.status} /></h2>
        <div className="row-actions">
          {pending ? (
            <>
              <button type="button" className="primary" disabled={action.busy} onClick={() => void decide('apply', tr('Lot appliqué : les changements sont en place.'))}>{tr('Appliquer le lot')}</button>
              <button type="button" disabled={action.busy} onClick={() => void decide('reject', tr('Lot refusé : rien n’a changé.'), trn(changes.length, 'Refuser le changement de ce lot ?', 'Refuser les {n} changements de ce lot ?'))}>{tr('Refuser')}</button>
            </>
          ) : null}
          {set.status === 'applied' ? (
            <button type="button" disabled={action.busy} onClick={() => void decide('revert', tr('Lot annulé : les valeurs d’avant reviennent.'), trn(changes.length, 'Annuler le changement de ce lot ?', 'Annuler les {n} changements de ce lot ?'))}>{tr('Annuler le lot')}</button>
          ) : null}
        </div>
      </div>
      <p className="meta">
        {tr('Proposé par')} <Author user={set.createdBy} />, {tr('le {date}', { date: formatDate(set.createdAt) })}
        {set.decidedAt ? <> · {set.decidedBy ? tr('décidé par {nom} le {date}', { nom: set.decidedBy.displayName, date: formatDate(set.decidedAt) }) : tr('décidé le {date}', { date: formatDate(set.decidedAt) })}</> : null}
      </p>
      {set.description ? <p className="description">{set.description}</p> : null}
      {pending && set.createdBy?.role === 'agent' ? (
        <p className="hint warn">{tr('Lot proposé par une IA : vérifiez chaque changement. Elle lit les tags des fichiers, qui peuvent contenir du texte trompeur ; titre, description et motifs viennent d’elle.')}</p>
      ) : null}
      <Feedback message={action.message} error={action.error} />
      {pending && changes.some(changedSince) ? (
        <p className="hint warn">{tr('Certains éléments ont changé depuis la proposition (colonne « Maintenant ») : vérifiez avant d’appliquer.')}</p>
      ) : null}
      <div className="table-wrap">
        <table className="admin-table">
          <thead>
            <tr><th>{tr('Élément')}</th><th>{tr('Champ')}</th><th>{tr('Avant')}</th><th>{tr('Proposé')}</th>{pending ? <th>{tr('Maintenant')}</th> : null}<th>{tr('Statut')}</th></tr>
          </thead>
          <tbody>
            {changes.map((c) => (
              <tr key={c.correctionId}>
                <td><EntityLink type={c.entityType} id={c.entityId} alias={c.alias} label={c.label} />{c.reason ? <small className="block reason">{c.reason}</small> : null}</td>
                <td>{FIELD[c.field] ?? c.field}</td>
                <td><del>{formatValue(c.field, c.previousValue, names)}</del></td>
                <td><ins>{formatValue(c.field, c.value, names)}</ins></td>
                {pending ? <td className={changedSince(c) ? 'changed' : ''}>{formatValue(c.field, c.currentValue, names)}</td> : null}
                <td><span className={`pill correction-${c.status}`}>{CORRECTION_STATUS[c.status]}</span></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  );
}
