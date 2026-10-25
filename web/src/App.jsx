// @ts-check
import { useEffect, useState } from 'react';
import { Admin, usePendingCount } from './admin/Admin.jsx';
import { AlbumEdit } from './admin/AlbumEdit.jsx';
import { ArtistEdit } from './admin/ArtistEdit.jsx';
import { Icon, Logo } from './components/Icon.jsx';
import { PlayerBar } from './components/PlayerBar.jsx';
import { QueuePanel } from './components/QueuePanel.jsx';
import { SearchBox } from './components/SearchBox.jsx';
import { SiteBackdrop } from './components/SiteBackdrop.jsx';
import { controller, remote } from './engine/controller.js';
import { restore } from './engine/session.js';
import { useSession } from './hooks.js';
import { Album } from './pages/Album.jsx';
import { Albums } from './pages/Albums.jsx';
import { Artist } from './pages/Artist.jsx';
import { Artists } from './pages/Artists.jsx';
import { Collection } from './pages/Collection.jsx';
import { Genres } from './pages/Genres.jsx';
import { Favorites } from './pages/Favorites.jsx';
import { History } from './pages/History.jsx';
import { Playlist } from './pages/Playlist.jsx';
import { Playlists } from './pages/Playlists.jsx';
import { PlaylistPicker } from './components/PlaylistPicker.jsx';
import { Login } from './pages/Login.jsx';
import { Random } from './pages/Random.jsx';
import { Remote } from './pages/Remote.jsx';
import { ResetPassword } from './pages/ResetPassword.jsx';
import { Search } from './pages/Search.jsx';
import { Settings } from './pages/Settings.jsx';
import { Link, match, useRoute } from './router.jsx';
import { tr, trn } from './i18n/index.js';

/** @param {{ path: string, query: URLSearchParams }} r */
function Page({ path, query }) {
  let m;
  if (path === '/') return <Albums query={query} />;
  if ((m = match('/albums/:id', path))) return <Album key={m.id} id={m.id} />;
  if ((m = match('/albums/:id/corriger', path))) return <AlbumEdit key={m.id} id={m.id} />;
  if (path === '/admin' || path.startsWith('/admin/')) return <Admin path={path} />;
  if (path === '/artistes') return <Artists />;
  if ((m = match('/artistes/:id', path))) return <Artist key={m.id} id={m.id} />;
  if ((m = match('/artistes/:id/corriger', path))) return <ArtistEdit key={m.id} id={m.id} />;
  if (path === '/genres') return <Genres />;
  if ((m = match('/collections/:id', path))) return <Collection key={m.id} id={m.id} />;
  if (path === '/aleatoire') return <Random />;
  if (path === '/favoris') return <Favorites query={query} />;
  if (path === '/historique') return <History />;
  if (path === '/playlists') return <Playlists />;
  if ((m = match('/playlists/:id', path))) return <Playlist key={m.id} id={m.id} />;
  if (path === '/recherche') return <Search q={query.get('q') ?? ''} />;
  if (path === '/reglages') return <Settings />;
  return <p className="empty">{tr('Page introuvable.')} <Link to="/">{tr('Retour aux albums')}</Link></p>;
}

export function App() {
  const s = useSession();
  const route = useRoute();
  const [queueOpen, setQueueOpen] = useState(false);
  const pending = usePendingCount();

  useEffect(() => {
    restore().catch((e) => console.error(e));
  }, []);

  // La file enregistrée (sur cet appareil ou un autre) revient à la connexion
  useEffect(() => {
    if (s.status === 'ready') void controller.restore();
    if (s.status === 'anonymous') controller.reset(); // session expirée ou déconnexion
  }, [s.status]);

  // Télécommande partagée : le lecteur écoute les commandes des invités (FUT-02)
  const remoteControl = s.server?.features.remoteControl === true;
  const onRemotePage = route.path === '/telecommande';
  useEffect(() => {
    if (s.status === 'ready' && remoteControl && !onRemotePage) remote.start();
    else remote.stop();
  }, [s.status, remoteControl, onRemotePage]);

  const theme = s.settings?.interface.theme ?? 'auto';
  useEffect(() => {
    if (theme === 'auto') document.documentElement.removeAttribute('data-theme');
    else document.documentElement.setAttribute('data-theme', theme);
  }, [theme]);

  // Lien reçu par mail, lien de télécommande : accessibles connecté ou non
  if (route.path === '/mot-de-passe') return <ResetPassword />;
  if (onRemotePage) return <Remote />;
  if (s.status === 'loading') return <p className="empty splash">{tr('Chargement…')}</p>;
  if (s.status === 'anonymous') return <Login />;

  const nav = /** @type {[string, string, import('./components/Icon.jsx').IconName][]} */ ([
    ['/', tr('Albums'), 'albums'], ['/artistes', tr('Artistes'), 'artist'], ['/genres', tr('Explorer'), 'genre'], ['/aleatoire', tr('Aléatoire'), 'shuffle'], ['/playlists', tr('Playlists'), 'playlist'], ['/favoris', tr('Favoris'), 'heart'],
  ]);
  if (s.user?.role === 'admin') nav.push(['/admin', tr('Admin'), 'admin']);
  const isCurrent = (/** @type {string} */ to) => route.path === to || (to !== '/' && route.path.startsWith(to))
    || (to === '/' && route.path.startsWith('/albums/'));
  return (
    <div className={`app ${queueOpen ? 'with-queue' : ''}`}>
      <header className="top">
        <Link to="/" className="brand" title={s.server?.name ?? 'Rengaine'}>
          <Logo size={34} />
          <span className="brand-name">{s.server?.name ?? 'Rengaine'}</span>
        </Link>
        <SearchBox />
        <nav className="app-nav" aria-label={tr('Navigation')}>
          {nav.map(([to, label, icon]) => (
            <Link key={to} to={to} className={`${isCurrent(to) ? 'current' : ''} ${to === '/favoris' ? 'nav-secondary' : ''}`}>
              <Icon name={icon} size={20} />
              <span className="nav-label">{label}</span>
              {to === '/admin' && pending > 0 ? <span className="count" title={trn(pending, '{n} demande de compte en attente', '{n} demandes de compte en attente')}>{pending}</span> : null}
            </Link>
          ))}
        </nav>
        <Link to="/reglages" className={`icon-btn settings-link ${route.path === '/reglages' ? 'current' : ''}`} title={tr('Réglages')}>
          <Icon name="settings" /><span className="nav-label">{tr('Réglages')}</span>
        </Link>
      </header>
      <SiteBackdrop />
      <main className="content"><Page path={route.path} query={route.query} /></main>
      {queueOpen ? <QueuePanel onClose={() => setQueueOpen(false)} /> : null}
      <PlayerBar queueOpen={queueOpen} onToggleQueue={() => setQueueOpen(!queueOpen)} />
      <PlaylistPicker />
    </div>
  );
}
