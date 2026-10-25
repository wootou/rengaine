// @ts-check
import { AlbumGrid } from '../components/AlbumGrid.jsx';
import { Icon } from '../components/Icon.jsx';
import { setAlbumsList } from '../engine/device.js';
import { useApi, useDevice, usePaged, useSentinel } from '../hooks.js';
import { navigate } from '../router.jsx';
import { decadeName } from './Genres.jsx';
import { tr } from '../i18n/index.js';

const sorts = () => [['name', tr('Nom')], ['added', tr('Ajouts récents')], ['year', tr('Année')], ['artist', tr('Artiste')], ['random', tr('Au hasard')]];

/** @param {{ query: URLSearchParams }} props */
export function Albums({ query }) {
  const sort = query.get('sort') ?? 'name';
  const genre = query.get('genre') ?? '';
  const seed = query.get('seed') ?? '';
  const decade = Number(query.get('decennie') ?? '') || null;
  const order = sort === 'added' || sort === 'year' ? 'desc' : 'asc';
  const { items, total, loading, loadMore, done } = usePaged('/albums', {
    sort, order, genre: genre || undefined, seed: seed || undefined,
    yearFrom: decade ?? undefined, yearTo: decade !== null ? decade + 9 : undefined,
  });
  const sentinel = useSentinel(loadMore, !done && !loading);
  const genres = /** @type {{ items: import('../api/types.js').Genre[] } | null} */ (useApi('/genres').data);
  const list = useDevice().albumsList;
  const decades = /** @type {{ items: import('../api/types.js').Schemas['Decade'][] } | null} */ (useApi('/decades').data)?.items ?? [];

  /** @param {Record<string, string>} change */
  const go = (change) => {
    const q = new URLSearchParams(query);
    for (const [k, v] of Object.entries(change)) v ? q.set(k, v) : q.delete(k);
    if (q.get('sort') === 'random' && !q.get('seed')) q.set('seed', String(Math.floor(Math.random() * 1e6)));
    if (q.get('sort') !== 'random') q.delete('seed');
    navigate(`/?${q}`, true);
  };

  return (
    <section>
      <div className="page-head">
        <h1>{tr('Albums')} {total !== null ? <small>{total}</small> : null}</h1>
        <button type="button" className="icon-btn boxed view-toggle" title={list ? tr('Afficher en grille') : tr('Afficher en liste')}
          aria-label={list ? tr('Afficher en grille') : tr('Afficher en liste')} onClick={() => setAlbumsList(!list)}>
          <Icon name={list ? 'albums' : 'list'} />
        </button>
        <div className="filters">
          <select aria-label={tr('Tri')} value={sort} onChange={(e) => go({ sort: e.target.value })}>
            {sorts().map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </select>
          <select aria-label={tr('Genre')} value={genre} onChange={(e) => go({ genre: e.target.value })}>
            <option value="">{tr('Tous les genres')}</option>
            {genres?.items.filter((g) => g.albumCount > 0 || genres.items.some((c) => c.parentId === g.id)).map((g) => <option key={g.id} value={g.id}>{g.name}</option>)}
          </select>
          {decades.length > 1 ? (
            <select aria-label={tr('Décennie')} value={decade ?? ''} onChange={(e) => go({ decennie: e.target.value })}>
              <option value="">{tr('Toutes les années')}</option>
              {decades.map((d) => <option key={d.decade} value={d.decade}>{decadeName(d.decade)}</option>)}
            </select>
          ) : null}
        </div>
      </div>
      <AlbumGrid albums={/** @type {import('../api/types.js').AlbumSummary[]} */ (items)} list={list} />
      {!done ? <div ref={sentinel} className="sentinel">{loading ? tr('Chargement…') : ''}</div> : null}
      {done && items.length === 0 ? <p className="empty">{tr('Aucun album.')}</p> : null}
    </section>
  );
}
