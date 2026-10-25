// @ts-check
import { useState } from 'react';
import { useDebounced, usePaged, useSentinel } from '../hooks.js';
import { Cover } from '../components/Cover.jsx';
import { Link } from '../router.jsx';
import { tr, trn } from '../i18n/index.js';

export function Artists() {
  const [q, setQ] = useState('');
  const term = useDebounced(q.trim(), 250);
  const { items, total, loading, loadMore, done } = usePaged('/artists', { q: term || undefined }, 100);
  const sentinel = useSentinel(loadMore, !done && !loading);
  const artists = /** @type {import('../api/types.js').ArtistSummary[]} */ (items);
  return (
    <section>
      <div className="page-head">
        <h1>{tr('Artistes')} {total !== null ? <small>{total}</small> : null}</h1>
        <input type="search" placeholder={tr('Filtrer…')} aria-label={tr('Filtrer les artistes')} value={q} onChange={(e) => setQ(e.target.value)} />
      </div>
      <ul className="artist-list">
        {artists.map((a) => (
          <li key={a.id}>
            {a.imageUrl ? <Cover url={a.imageUrl} size="s" alt="" className="artist-avatar" /> : <span className="artist-avatar initial" aria-hidden="true">{a.name.trim().charAt(0).toUpperCase()}</span>}
            <Link to={`/artistes/${a.id}`}>{a.name}</Link> <small>{a.albumCount > 0 ? trn(a.albumCount, '{n} album', '{n} albums') : tr('invité')}</small>
          </li>
        ))}
      </ul>
      {!done ? <div ref={sentinel} className="sentinel">{loading ? tr('Chargement…') : ''}</div> : null}
    </section>
  );
}
