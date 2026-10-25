// @ts-check
// Télécommande partagée, côté invité (FUT-02) : ouverte par un lien « /telecommande#<secret> », sans
// compte. Le secret est échangé contre un cookie (puis retiré de l'adresse), l'état arrive par le
// hub temps réel (« changed » → on relit), les actions partent en commandes vers le lecteur qui joue.
import { useCallback, useEffect, useRef, useState } from 'react';
import { ApiError, request } from '../api/client.js';
import { Cover } from '../components/Cover.jsx';
import { Icon, Logo } from '../components/Icon.jsx';
import { formatTime } from '../engine/playback.js';
import { Subscription } from '../engine/sse.js';
import { useDebounced } from '../hooks.js';
import { locale, tr, trn } from '../i18n/index.js';

/** @typedef {import('../api/types.js').RemoteState} RemoteState */
/** @typedef {import('../api/types.js').RemoteCommand} RemoteCommand */
/** @typedef {import('../api/types.js').Track} Track */
/** @typedef {import('../api/types.js').AlbumSummary} AlbumSummary */

/** Secret du lien dans l'adresse (#…), retiré aussitôt lu : il ne reste ni dans l'historique ni à l'écran. */
function takeSecret() {
  const h = window.location.hash.replace(/^#/, '');
  if (!h) return null;
  window.history.replaceState(null, '', window.location.pathname);
  return decodeURIComponent(h);
}

const NAME_KEY = 'rengaine.guestName';

/** Prénom retenu par ce téléphone (stockage parfois indisponible : navigation privée…). */
function storedName() {
  try {
    return window.localStorage.getItem(NAME_KEY);
  } catch {
    return null;
  }
}

/** @param {string} name */
function storeName(name) {
  try {
    window.localStorage.setItem(NAME_KEY, name);
  } catch {
    // tant pis : demandé à nouveau la prochaine fois
  }
}

/** @param {unknown} e */
function message(e) {
  if (e instanceof ApiError) {
    if (e.code === 'player_offline') return tr('Aucun lecteur allumé : la musique doit jouer (ou être en pause) chez la personne qui a partagé.');
    return e.message;
  }
  return tr('Action impossible pour l’instant');
}

/** Position estimée : celle du dernier rapport, plus le temps écoulé si la musique joue. @param {RemoteState['player']} p @param {number} now */
function positionNow(p, now) {
  if (!p.playing || !p.reportedAt) return p.positionMs;
  return p.positionMs + Math.max(0, now - Date.parse(p.reportedAt));
}

export function Remote() {
  const [state, setState] = useState(/** @type {RemoteState | null} */ (null));
  const [error, setError] = useState(/** @type {string | null} */ (null));
  const [ended, setEnded] = useState(/** @type {string | null} */ (null));
  const [notice, setNotice] = useState('');
  const [now, setNow] = useState(Date.now());
  // Prénom : null = pas encore demandé (« » = l'invité a préféré ne pas en donner)
  const [name, setName] = useState(/** @type {string | null} */ (storedName()));
  const [editingName, setEditingName] = useState(false);
  const csrf = useRef('');
  const first = useRef(/** @type {RemoteState | null} */ (null));

  const apply = useCallback((/** @type {RemoteState} */ s) => {
    csrf.current = s.csrfToken;
    setState(s);
    return s;
  }, []);

  const refresh = useCallback(async () => {
    try {
      return apply(/** @type {RemoteState} */ ((await request('GET', '/remote', { quiet401: true })).data));
    } catch (e) {
      if (e instanceof ApiError && e.status === 401) setEnded(tr('Ce lien a expiré ou a été révoqué.'));
      throw e;
    }
  }, [apply]);

  // Ouverture : secret de l'adresse, sinon cookie d'une visite précédente ; puis abonnement
  useEffect(() => {
    let sub = /** @type {Subscription | null} */ (null);
    let alive = true;
    const secret = takeSecret();
    (async () => {
      try {
        const s = secret
          ? apply(/** @type {RemoteState} */ ((await request('POST', '/remote/session', { body: { token: secret }, quiet401: true })).data))
          : await refresh();
        if (!alive) return;
        first.current = s;
        let timer = /** @type {ReturnType<typeof setTimeout> | null} */ (null);
        sub = new Subscription(
          // Premier canal : celui de l'état reçu ; ensuite, un état frais (et son jeton)
          async () => {
            const f = first.current;
            first.current = null;
            return (f ?? (await refresh())).channel;
          },
          (m) => {
            if (m.type === 'revoked' || m.type === 'disabled') {
              setEnded(m.type === 'revoked' ? tr('Ce lien vient d’être révoqué.') : tr('La télécommande vient d’être désactivée sur ce serveur.'));
              sub?.stop();
            } else if (m.type === 'changed') {
              // Plusieurs changements d'affilée (file + lecteur) : une seule relecture
              if (timer) clearTimeout(timer);
              timer = setTimeout(() => void refresh().catch(() => {}), 150);
            }
          },
          { onFatal: (e) => e instanceof ApiError && (e.status === 401 || e.status === 503) },
        );
        sub.start();
      } catch (e) {
        if (!alive) return;
        if (e instanceof ApiError && e.status === 401) setEnded(tr('Ce lien n’est pas (ou plus) valable. Demandez-en un nouveau.'));
        else setError(message(e));
      }
    })();
    return () => {
      alive = false;
      sub?.stop();
    };
  }, [apply, refresh]);

  // Avance de la position affichée
  const playing = state?.player.playing === true;
  useEffect(() => {
    if (!playing) return undefined;
    const t = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(t);
  }, [playing]);

  /** @param {RemoteCommand} cmd @param {string} [done] */
  const send = async (cmd, done) => {
    setError(null);
    try {
      await request('POST', '/remote/commands', { body: name ? { ...cmd, name } : cmd, csrf: csrf.current, quiet401: true });
      if (done) setNotice(done);
    } catch (e) {
      if (e instanceof ApiError && e.status === 401) setEnded(tr('Ce lien a expiré ou a été révoqué.'));
      else setError(message(e));
    }
  };

  if (ended) {
    return (
      <main className="remote">
        <RemoteHead />
        <p className="empty" role="alert">{ended}</p>
      </main>
    );
  }
  if (!state) {
    return (
      <main className="remote">
        <RemoteHead />
        {error ? <p className="form-error" role="alert">{error}</p> : <p className="empty">{tr('Connexion…')}</p>}
      </main>
    );
  }

  const { share, player, queue } = state;
  const cur = queue.currentIndex;
  const current = cur !== null ? queue.tracks[cur] ?? null : null;
  const upcoming = queue.tracks.map((t, i) => ({ t, i })).filter(({ i }) => cur === null || i > cur);
  const pos = positionNow(player, now);

  return (
    <main className="remote">
      <RemoteHead owner={share.ownerName} />
      <p className="remote-status" role="status">
        {player.online ? (player.playing ? tr('Lecture en cours') : tr('En pause')) : tr('Lecteur éteint : rien ne joue en ce moment')}
        {' · '}{tr('lien valable jusqu’au {date}', { date: new Date(share.expiresAt).toLocaleString(locale(), { weekday: 'long', hour: '2-digit', minute: '2-digit' }) })}
      </p>
      {!share.rights.add && !share.rights.edit && !share.rights.control ? null : name === null || editingName ? (
        <GuestName initial={name ?? ''} onDone={(n) => {
          storeName(n);
          setName(n);
          setEditingName(false);
        }} />
      ) : name ? (
        <p className="remote-me">{tr('Vous êtes')} <strong>{name}</strong> <button type="button" className="link-btn" onClick={() => setEditingName(true)}>{tr('Changer')}</button></p>
      ) : null}
      {error ? <p className="form-error" role="alert">{error}</p> : null}
      {notice && !error ? <p className="remote-note">{notice}</p> : null}

      <section className="remote-now" aria-label={tr('En cours')}>
        <Cover url={current?.coverUrl} size="m" alt="" />
        <div className="remote-now-text">
          <strong>{current?.title ?? tr('Rien en cours')}</strong>
          {current ? <span>{current.artistCredit} · {current.album.title}</span> : null}
          {current ? <small>{formatTime(Math.min(pos, current.durationMs))} / {formatTime(current.durationMs)}</small> : null}
        </div>
        {share.rights.control ? (
          <div className="remote-controls">
            <button type="button" className="play-btn" disabled={!player.online || !current}
              aria-label={player.playing ? tr('Pause') : tr('Lecture')} title={player.playing ? tr('Pause') : tr('Lecture')}
              onClick={() => void send({ type: player.playing ? 'pause' : 'play' })}>
              <Icon name={player.playing ? 'pause' : 'play'} size={28} />
            </button>
            <button type="button" className="icon-btn" disabled={!player.online || !current} aria-label={tr('Piste suivante')} title={tr('Piste suivante')}
              onClick={() => void send({ type: 'next' })}>
              <Icon name="next" />
            </button>
          </div>
        ) : null}
        {share.rights.control && player.volume !== null ? (
          <RemoteVolume value={player.volume} disabled={!player.online} send={send} />
        ) : null}
      </section>

      {share.rights.add ? <RemoteSearch send={send} disabled={!player.online} canPlay={share.rights.control} /> : null}

      <section className="remote-queue">
        <h2>{tr('À suivre')} <small>{trn(upcoming.length, '{n} piste', '{n} pistes')}</small></h2>
        {upcoming.length === 0 ? <p className="empty">{tr('Rien après la piste en cours.')}</p> : (
          <ol className="queue-list">
            {upcoming.map(({ t, i }, n) => (
              <li key={`${i}-${t.id}`}>
                <Cover url={t.coverUrl} size="s" alt="" />
                <span className="q-text"><span className="q-title">{t.title}</span><span className="q-artist">{t.artistCredit}</span></span>
                {share.rights.control || share.rights.edit ? (
                  <span className="remote-row-actions">
                    {share.rights.control ? (
                      <button type="button" className="icon-btn" disabled={!player.online} aria-label={tr('Lire « {titre} » maintenant', { titre: t.title })} title={tr('Lire maintenant')}
                        onClick={() => void send({ type: 'jump', index: i, trackId: t.id }, tr('« {titre} » lancée', { titre: t.title }))}><Icon name="play" /></button>
                    ) : null}
                    {share.rights.edit ? (<>
                    <button type="button" className="icon-btn" disabled={n === 0 || !player.online} aria-label={tr('Monter « {titre} »', { titre: t.title })} title={tr('Monter')}
                      onClick={() => void send({ type: 'move', from: i, to: i - 1, trackId: t.id })}><Icon name="chevronUp" /></button>
                    <button type="button" className="icon-btn" disabled={n === upcoming.length - 1 || !player.online} aria-label={tr('Descendre « {titre} »', { titre: t.title })} title={tr('Descendre')}
                      onClick={() => void send({ type: 'move', from: i, to: i + 1, trackId: t.id })}><Icon name="chevronDown" /></button>
                    <button type="button" className="icon-btn" disabled={!player.online} aria-label={tr('Retirer « {titre} »', { titre: t.title })} title={tr('Retirer de la file')}
                      onClick={() => void send({ type: 'remove', index: i, trackId: t.id })}><Icon name="trash" /></button>
                    </>) : null}
                  </span>
                ) : <span className="q-duration">{formatTime(t.durationMs)}</span>}
              </li>
            ))}
          </ol>
        )}
      </section>
    </main>
  );
}

/**
 * Volume du lecteur, réglé à distance. Pendant qu'on fait glisser, au plus une commande toutes
 * les 300 ms (et la dernière valeur à la fin). Le curseur garde la valeur choisie jusqu'à ce que
 * le lecteur la confirme, 1,5 s au plus : ensuite il suit de nouveau l'état reçu, quoi qu'il arrive.
 * @param {{ value: number, disabled: boolean, send: (cmd: RemoteCommand) => Promise<void> }} props
 */
function RemoteVolume({ value, disabled, send }) {
  const [wanted, setWanted] = useState(/** @type {number | null} */ (null));
  const last = useRef(0);
  const timer = useRef(/** @type {ReturnType<typeof setTimeout> | null} */ (null));
  const pending = useRef(/** @type {number | null} */ (null));
  const hold = useRef(/** @type {ReturnType<typeof setTimeout> | null} */ (null));

  // Le lecteur a confirmé la valeur demandée : on suit de nouveau son état
  useEffect(() => {
    if (wanted !== null && pending.current === null && value === wanted) setWanted(null);
  }, [value, wanted]);
  useEffect(() => () => {
    if (timer.current) clearTimeout(timer.current);
    if (hold.current) clearTimeout(hold.current);
  }, []);

  const flush = () => {
    timer.current = null;
    const v = pending.current;
    pending.current = null;
    if (v === null) return;
    last.current = Date.now();
    if (hold.current) clearTimeout(hold.current);
    hold.current = setTimeout(() => {
      hold.current = null;
      if (pending.current === null && !timer.current) setWanted(null);
    }, 1500);
    void send({ type: 'volume', volume: v });
  };
  /** @param {number} v */
  const change = (v) => {
    const clamped = Math.max(0, Math.min(100, Math.round(v)));
    setWanted(clamped);
    pending.current = clamped;
    if (timer.current) return;
    const wait = Math.max(0, 300 - (Date.now() - last.current));
    timer.current = setTimeout(flush, wait);
  };
  const shown = wanted ?? value;

  return (
    <div className="remote-volume">
      <Icon name={shown === 0 ? 'volumeOff' : 'volume'} />
      <button type="button" className="icon-btn" disabled={disabled || shown <= 0} aria-label={tr('Baisser le volume')} title={tr('Baisser le volume')}
        onClick={() => change(shown - 10)}>
        <Icon name="minus" />
      </button>
      <input type="range" min={0} max={100} step={5} value={shown} disabled={disabled}
        aria-label={tr('Volume du lecteur')} aria-valuetext={`${shown} %`}
        onChange={(e) => change(Number(e.target.value))} />
      <button type="button" className="icon-btn remote-volume-up" disabled={disabled || shown >= 100} aria-label={tr('Monter le volume')} title={tr('Monter le volume')}
        onClick={() => change(shown + 10)}>
        <Icon name="add" />
      </button>
      <output className="remote-volume-value" aria-hidden="true">{shown} %</output>
    </div>
  );
}

/** Prénom de l'invité : affiché chez la personne qui partage (« Paul : pause »). @param {{ initial: string, onDone: (name: string) => void }} props */
function GuestName({ initial, onDone }) {
  const [value, setValue] = useState(initial);
  return (
    <form className="remote-name" onSubmit={(e) => {
      e.preventDefault();
      onDone(value.trim());
    }}>
      <label>{tr('Votre prénom')}
        <input type="text" maxLength={40} autoComplete="given-name" value={value} placeholder={tr('Par exemple : Paul')} onChange={(e) => setValue(e.target.value)} />
      </label>
      <small>{tr('Il s’affiche à côté de vos actions, chez la personne qui a partagé.')}</small>
      <div className="remote-name-actions">
        <button type="submit" className="primary" disabled={value.trim() === ''}>OK</button>
        <button type="button" className="link-btn" onClick={() => onDone('')}>{tr('Sans prénom')}</button>
      </div>
    </form>
  );
}

/** @param {{ owner?: string }} props */
function RemoteHead({ owner }) {
  return (
    <header className="remote-head">
      <Logo size={34} />
      <div>
        <h1>{tr('Télécommande')}</h1>
        {owner ? <p>{tr('File d’attente de {nom}', { nom: owner })}</p> : null}
      </div>
    </header>
  );
}

/** @param {{ send: (cmd: RemoteCommand, done?: string) => Promise<void>, disabled: boolean, canPlay: boolean }} props */
function RemoteSearch({ send, disabled, canPlay }) {
  const [q, setQ] = useState('');
  const query = useDebounced(q.trim(), 300);
  const [results, setResults] = useState(/** @type {{ tracks: Track[], albums: AlbumSummary[] } | null} */ (null));
  useEffect(() => {
    if (query === '') {
      setResults(null);
      return undefined;
    }
    const ac = new AbortController();
    request('GET', '/remote/search', { query: { q: query }, signal: ac.signal, quiet401: true })
      .then((r) => setResults(/** @type {any} */ (r.data)))
      .catch(() => {});
    return () => ac.abort();
  }, [query]);

  return (
    <section className="remote-search">
      <label>{tr('Ajouter des morceaux')}
        <input type="search" value={q} placeholder={tr('Titre, artiste ou album')} onChange={(e) => setQ(e.target.value)} />
      </label>
      {disabled ? <small>{tr('Le lecteur est éteint : l’ajout reviendra quand la musique jouera.')}</small> : null}
      {results ? (
        results.tracks.length === 0 && results.albums.length === 0 ? <p className="empty">{tr('Rien trouvé.')}</p> : (
          <ul className="remote-results">
            {results.albums.map((a) => (
              <li key={`a${a.id}`}>
                <Cover url={a.coverUrl} size="s" alt="" />
                <span className="q-text"><span className="q-title">{a.title}</span><span className="q-artist">{tr('Album')} · {a.artistName}</span></span>
                <button type="button" className="icon-btn" disabled={disabled} aria-label={tr('Ajouter l’album « {titre} » à la fin', { titre: a.title })} title={tr('Ajouter l’album à la fin')}
                  onClick={() => void send({ type: 'add', albumId: a.id, where: 'end' }, tr('Album « {titre} » ajouté à la fin', { titre: a.title }))}><Icon name="add" /></button>
              </li>
            ))}
            {results.tracks.filter((t) => t.available).map((t) => (
              <li key={`t${t.id}`}>
                <Cover url={t.coverUrl} size="s" alt="" />
                <span className="q-text"><span className="q-title">{t.title}</span><span className="q-artist">{t.artistCredit}</span></span>
                {canPlay ? (
                  <button type="button" className="icon-btn" disabled={disabled} aria-label={tr('Lire « {titre} » maintenant', { titre: t.title })} title={tr('Lire maintenant')}
                    onClick={() => void send({ type: 'add', trackIds: [t.id], where: 'now' }, tr('« {titre} » lancée', { titre: t.title }))}><Icon name="play" /></button>
                ) : null}
                <button type="button" className="icon-btn" disabled={disabled} aria-label={tr('Lire « {titre} » ensuite', { titre: t.title })} title={tr('Lire ensuite')}
                  onClick={() => void send({ type: 'add', trackIds: [t.id], where: 'next' }, tr('« {titre} » passera ensuite', { titre: t.title }))}><Icon name="playNext" /></button>
                <button type="button" className="icon-btn" disabled={disabled} aria-label={tr('Ajouter « {titre} » à la fin', { titre: t.title })} title={tr('Ajouter à la fin')}
                  onClick={() => void send({ type: 'add', trackIds: [t.id], where: 'end' }, tr('« {titre} » ajouté à la fin', { titre: t.title }))}><Icon name="add" /></button>
              </li>
            ))}
          </ul>
        )
      ) : null}
    </section>
  );
}
