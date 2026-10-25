// @ts-check
// Recherche pendant la frappe (WEB-03) : résultats groupés, sans accents ni casse.
import { useEffect, useRef, useState } from 'react';
import { controller } from '../engine/controller.js';
import { useApi, useDebounced } from '../hooks.js';
import { Link, navigate } from '../router.jsx';
import { Cover } from './Cover.jsx';
import { Icon } from './Icon.jsx';
import { tr } from '../i18n/index.js';

export function SearchBox() {
  const [q, setQ] = useState('');
  const [open, setOpen] = useState(false);
  const term = useDebounced(q.trim(), 200);
  const box = useRef(/** @type {HTMLDivElement | null} */ (null));
  const { data } = useApi(/** @type {string | null} */ (term ? '/search' : null), { q: term, limit: 6 });
  const r = /** @type {import('../api/types.js').SearchResults | null} */ (data);

  useEffect(() => {
    /** @param {MouseEvent} e */
    const close = (e) => box.current && !box.current.contains(/** @type {Node} */ (e.target)) && setOpen(false);
    document.addEventListener('mousedown', close);
    return () => document.removeEventListener('mousedown', close);
  }, []);

  const empty = r && r.artists.length + r.albums.length + r.tracks.length === 0;
  const done = () => {
    setOpen(false);
    setQ('');
  };

  return (
    <div className="search" ref={box}>
      <Icon name="search" size={18} />
      <input
        type="search"
        placeholder={tr('Artiste, album, titre…')}
        aria-label={tr('Rechercher')}
        value={q}
        onChange={(e) => {
          setQ(e.target.value);
          setOpen(true);
        }}
        onFocus={() => setOpen(true)}
        onKeyDown={(e) => {
          if (e.key === 'Escape') setOpen(false);
          if (e.key === 'Enter' && q.trim()) {
            navigate(`/recherche?q=${encodeURIComponent(q.trim())}`);
            done();
          }
        }}
      />
      {open && term && r ? (
        <div className="search-results" role="listbox">
          {empty ? <p className="empty">{tr('Aucun résultat pour « {terme} »', { terme: term })}</p> : null}
          {r.artists.length ? <h3>{tr('Artistes')}</h3> : null}
          {r.artists.map((a) => (
            <div key={`ar${a.id}`} onClick={done}><Link to={`/artistes/${a.id}`} className="result">{a.name}</Link></div>
          ))}
          {r.albums.length ? <h3>{tr('Albums')}</h3> : null}
          {r.albums.map((a) => (
            <div key={`al${a.id}`} onClick={done}>
              <Link to={`/albums/${a.id}`} className="result"><Cover url={a.coverUrl} size="s" /> <span>{a.title}<small>{a.artistName}</small></span></Link>
            </div>
          ))}
          {r.tracks.length ? <h3>{tr('Pistes')}</h3> : null}
          {r.tracks.map((t, i) => (
            <button type="button" key={`t${t.id}`} className="result" disabled={!t.available} onClick={() => {
              controller.playTracks(r.tracks, i);
              done();
            }}>
              <Cover url={t.coverUrl} size="s" /> <span>{t.title}<small>{t.artistCredit} · {t.album.title}</small></span>
            </button>
          ))}
          {!empty ? <div onClick={done}><Link to={`/recherche?q=${encodeURIComponent(term)}`} className="result more">{tr('Tous les résultats')}</Link></div> : null}
        </div>
      ) : null}
    </div>
  );
}
