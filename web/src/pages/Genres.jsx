// @ts-check
// Explorer (WEB-05, CAT-08) : ambiances calculées, collections de l'admin, genres par famille,
// décennies. Chacun s'écoute en mode infini (au hasard, la file se complète toute seule).
import { Icon } from '../components/Icon.jsx';
import { controller } from '../engine/controller.js';
import { useApi } from '../hooks.js';
import { Link } from '../router.jsx';
import { Mosaic } from './Playlists.jsx';
import { tr, trn } from '../i18n/index.js';

/** @typedef {import('../api/types.js').Schemas} Schemas */

/** « Années 80 », « Années 2010 ». @param {number} decade */
export function decadeName(decade) {
  return tr('Années {d}', { d: decade >= 1920 && decade < 2000 ? String(decade % 100).padStart(2, '0') : decade });
}

export function Genres() {
  const genres = /** @type {{ items: Schemas['Genre'][] } | null} */ (useApi('/genres').data)?.items ?? [];
  const moods = /** @type {{ items: Schemas['Mood'][] } | null} */ (useApi('/moods').data)?.items.filter((m) => m.trackCount > 0) ?? [];
  const decades = /** @type {{ items: Schemas['Decade'][] } | null} */ (useApi('/decades').data)?.items ?? [];
  const collections = /** @type {{ items: Schemas['Collection'][] } | null} */ (useApi('/collections', { kind: 'collection' }).data)?.items
    .filter((c) => c.albumCount > 0) ?? [];

  // Familles : un genre de premier niveau et ses sous-genres (ceux qui ont des pistes)
  /** @type {Map<number, Schemas['Genre'][]>} */
  const children = new Map();
  for (const g of genres) {
    if (g.parentId !== null && g.trackCount > 0) children.set(g.parentId, [...(children.get(g.parentId) ?? []), g]);
  }
  const top = genres.filter((g) => (g.parentId === null || !genres.some((p) => p.id === g.parentId)) && (g.trackCount > 0 || children.has(g.id)));

  return (
    <section className="explore">
      <div className="page-head">
        <h1>{tr('Explorer')}</h1>
        <button type="button" className="primary" onClick={() => void controller.startInfinite([])}><Icon name="infinite" size={20} /> {tr('Tout, au hasard')}</button>
      </div>

      {moods.length ? (
        <>
          <h2>{tr('Ambiances')}</h2>
          <ul className="moods">
            {moods.map((m) => (
              <li key={m.id} className="mood" data-mood={m.id}>
                <h3>{m.name}</h3>
                <p>{m.description}</p>
                <div className="mood-foot">
                  <span className="mood-count">{trn(m.trackCount, '{n} piste', '{n} pistes')}</span>
                  <button type="button" className="mood-play" title={tr('Écouter « {nom} » en continu', { nom: m.name })}
                    onClick={() => void controller.startInfinite([], { mood: m.id })}>
                    <Icon name="play" size={24} />
                  </button>
                </div>
              </li>
            ))}
          </ul>
        </>
      ) : null}

      {collections.length ? (
        <>
          <h2>{tr('Collections')}</h2>
          <ul className="album-grid">
            {collections.map((c) => (
              <li key={c.id}>
                <Link to={`/collections/${c.id}`} className="album-card playlist-card" title={c.name}>
                  <Mosaic urls={c.coverUrls} />
                  <span className="album-title">{c.name}</span>
                  <span className="album-artist">{trn(c.albumCount, '{n} album', '{n} albums')}</span>
                </Link>
              </li>
            ))}
          </ul>
        </>
      ) : null}

      <h2>{tr('Genres')}</h2>
      <ul className="genre-list">
        {top.map((g) => (
          <li key={g.id} className={children.has(g.id) ? 'genre-family' : ''}>
            <GenreRow genre={g} family={children.has(g.id)} />
            {children.has(g.id) ? (
              <ul className="genre-children">
                {(children.get(g.id) ?? []).map((c) => <li key={c.id}><GenreRow genre={c} /></li>)}
              </ul>
            ) : null}
          </li>
        ))}
      </ul>

      {decades.length ? (
        <>
          <h2>{tr('Décennies')}</h2>
          <ul className="genre-list decade-list">
            {decades.map((d) => (
              <li key={d.decade}>
                <div className="genre-row">
                  <Link to={`/?decennie=${d.decade}`} className="genre-name">{decadeName(d.decade)}</Link>
                  <small>{trn(d.albumCount, '{n} album', '{n} albums')} · {trn(d.trackCount, '{n} piste', '{n} pistes')}</small>
                  <button type="button" className="icon-btn" title={tr('Écoute infinie : {nom}', { nom: decadeName(d.decade).toLowerCase() })}
                    onClick={() => void controller.startInfinite([], { yearFrom: d.decade, yearTo: d.decade + 9 })}>
                    <Icon name="infinite" />
                  </button>
                </div>
              </li>
            ))}
          </ul>
        </>
      ) : null}
    </section>
  );
}

/** @param {{ genre: Schemas['Genre'], family?: boolean }} props */
function GenreRow({ genre, family = false }) {
  return (
    <div className="genre-row">
      <Link to={`/?genre=${genre.id}`} className="genre-name">{genre.name}</Link>
      <small>{family ? tr('famille') : `${trn(genre.albumCount, '{n} album', '{n} albums')} · ${trn(genre.trackCount, '{n} piste', '{n} pistes')}`}</small>
      <button type="button" className="icon-btn" title={tr('Écoute infinie : {nom}', { nom: genre.name })} onClick={() => void controller.startInfinite([genre.id])}>
        <Icon name="infinite" />
      </button>
    </div>
  );
}
