// @ts-check
// Espace d'admin : onglets, réservés au rôle admin (l'API refuse de toute façon les autres).
import { useEffect, useSyncExternalStore } from 'react';
import { useApi, useSession } from '../hooks.js';
import { Link, match } from '../router.jsx';
import { Corrections, Jobs } from './Activity.jsx';
import { Backgrounds } from './Backgrounds.jsx';
import { ChangeSetDetail, ChangeSets } from './ChangeSets.jsx';
import { CollectionsAdmin } from './Collections.jsx';
import { adminEvents, useTableLabels } from './common.jsx';
import { Duplicates } from './Duplicates.jsx';
import { GenresAdmin, ServerSettings, Tags } from './Library.jsx';
import { Journal } from './Logs.jsx';
import { UserDetail, Users } from './Users.jsx';
import { tr, trn } from '../i18n/index.js';

const tabs = () => /** @type {[string, string][]} */ ([
  ['/admin', tr('Comptes')], ['/admin/genres', tr('Genres')], ['/admin/collections', tr('Collections')], ['/admin/tags', tr('Tags')], ['/admin/corrections', tr('Corrections')],
  ['/admin/lots', tr('Propositions')], ['/admin/taches', tr('Tâches')], ['/admin/doublons', tr('Doublons')], ['/admin/journal', tr('Journal')], ['/admin/fonds', tr('Fonds')], ['/admin/serveur', tr('Serveur')],
]);

/** Nombre de demandes de compte en attente (badge du menu), relu après chaque décision. */
export function usePendingCount() {
  const admin = useSession().user?.role === 'admin';
  const { users } = useSyncExternalStore(adminEvents.subscribe, adminEvents.getState);
  const api = useApi(admin ? '/admin/users' : null, { status: 'pending', limit: 1 });
  const reload = api.reload;
  useEffect(() => {
    if (users > 0) reload();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [users]);
  return /** @type {{ total: number } | null} */ (api.data)?.total ?? 0;
}

/** Nombre de lots à décider (pastille de l'onglet Propositions), relu après chaque décision. */
function usePendingSets() {
  const admin = useSession().user?.role === 'admin';
  const { sets } = useSyncExternalStore(adminEvents.subscribe, adminEvents.getState);
  const api = useApi(admin ? '/admin/change-sets' : null, { status: 'pending', limit: 1 });
  const reload = api.reload;
  useEffect(() => {
    if (sets > 0) reload();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [sets]);
  return /** @type {{ total: number } | null} */ (api.data)?.total ?? 0;
}

/** @param {{ path: string }} props */
export function Admin({ path }) {
  const user = useSession().user;
  const proposals = usePendingSets();
  const tables = useTableLabels();
  if (user?.role !== 'admin') return <p className="empty">{tr('Cette page est réservée à l’admin.')}</p>;
  let m;
  /** @type {import('react').ReactNode} */
  let page = <p className="empty">{tr('Page introuvable.')}</p>;
  if (path === '/admin' || path === '/admin/') page = <Users />;
  else if ((m = match('/admin/comptes/:id', path))) page = <UserDetail key={m.id} id={m.id} />;
  else if (path === '/admin/genres') page = <GenresAdmin />;
  else if (path === '/admin/collections') page = <CollectionsAdmin />;
  else if (path === '/admin/tags') page = <Tags />;
  else if (path === '/admin/corrections') page = <Corrections />;
  else if (path === '/admin/lots') page = <ChangeSets />;
  else if ((m = match('/admin/lots/:id', path))) page = <ChangeSetDetail key={m.id} id={m.id} />;
  else if (path === '/admin/taches') page = <Jobs />;
  else if (path === '/admin/doublons') page = <Duplicates />;
  else if (path === '/admin/journal') page = <Journal />;
  else if (path === '/admin/fonds') page = <Backgrounds />;
  else if (path === '/admin/serveur') page = <ServerSettings />;
  const current = path.startsWith('/admin/comptes') ? '/admin' : path.startsWith('/admin/lots') ? '/admin/lots' : path.replace(/\/$/, '');
  return (
    <div className="admin" ref={tables}>
      <div className="page-head"><h1>{tr('Administration')}</h1></div>
      <nav className="tabs" aria-label={tr('Administration')}>
        {tabs().map(([to, label]) => (
          <Link key={to} to={to} className={current === to ? 'current' : ''}>
            {label}{to === '/admin/lots' && proposals > 0 ? <span className="count" title={trn(proposals, '{n} lot à décider', '{n} lots à décider')}>{proposals}</span> : null}
          </Link>
        ))}
      </nav>
      {page}
    </div>
  );
}
