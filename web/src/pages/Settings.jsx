// @ts-check
// Réglages (WEB-06) : enregistrés sur le serveur, les mêmes dans l'appli (CPT-04).
import { useRef, useState, useSyncExternalStore } from 'react';
import { ApiError, request } from '../api/client.js';
import { controller } from '../engine/controller.js';
import { isFirefoxAndroid, setSimpleAudio, simpleAudio } from '../engine/player.js';
import { setWaveform } from '../engine/device.js';
import { clearDiag, diag, setDiag } from '../engine/diag.js';
import { logout, updateSettings } from '../engine/session.js';
import { useApi, useDevice, useSession } from '../hooks.js';
import { DeleteAccount } from '../components/DeleteAccount.jsx';
import { ExportData } from '../components/ExportData.jsx';
import { PairDevice } from '../components/PairDevice.jsx';
import { RemoteShares } from './RemoteShares.jsx';
import { locale, tr, trn } from '../i18n/index.js';

/** @typedef {import('../api/types.js').UserSettings} UserSettings */

export function Settings() {
  const { settings, user, server } = useSession();
  const [status, setStatus] = useState('');
  const lastSave = useRef(0); // « Enregistré » seulement quand le dernier envoi est fait
  const [simple, setSimple] = useState(simpleAudio);
  const dev = useDevice();
  const journal = useSyncExternalStore(diag.subscribe, diag.getState);
  const [copied, setCopied] = useState('');
  const [reloadNeeded, setReloadNeeded] = useState(false);
  const devices = useApi('/me/devices');
  const genresApi = useApi('/genres');
  if (!settings) return null;
  const p = settings.playback;

  /** @param {(s: UserSettings) => UserSettings} change */
  const save = async (change) => {
    const n = ++lastSave.current;
    setStatus(tr('Enregistrement…'));
    try {
      await updateSettings(change);
      controller.refreshGain();
      if (n === lastSave.current) setStatus(tr('Enregistré'));
    } catch (e) {
      setStatus(e instanceof ApiError ? e.message : tr('Échec de l’enregistrement'));
    }
  };
  /** @param {Partial<UserSettings['playback']>} patch */
  const playback = (patch) => save((s) => ({ ...s, playback: { ...s.playback, ...patch } }));
  const c = settings.continuous;
  const genres = /** @type {{ items: import('../api/types.js').Genre[] } | null} */ (genresApi.data)?.items.filter((g) => g.trackCount > 0) ?? [];
  /** @param {Partial<UserSettings['continuous']>} patch */
  const continuous = (patch) => save((s) => ({ ...s, continuous: { ...s.continuous, ...patch } }));

  const list = /** @type {{ items: import('../api/types.js').Device[] } | null} */ (devices.data)?.items ?? [];
  return (
    <section className="settings">
      <div className="page-head"><h1>{tr('Réglages')}</h1><span className="status" role="status">{status}</span></div>

      <h2>{tr('Volume')}</h2>
      <label>{tr('Normalisation')}
        <select value={p.normalization} onChange={(e) => playback({ normalization: /** @type {any} */ (e.target.value) })}>
          <option value="auto">{tr('Automatique (album écouté en entier : volume de l’album)')}</option>
          <option value="track">{tr('Par piste')}</option>
          <option value="album">{tr('Par album')}</option>
          <option value="off">{tr('Désactivée')}</option>
        </select>
      </label>
      <label>{tr('Volume visé : {v} LUFS', { v: p.targetLufs })}
        <input type="range" min={-23} max={-8} step={1} defaultValue={p.targetLufs} disabled={p.normalization === 'off'}
          onPointerUp={(e) => playback({ targetLufs: Number(/** @type {HTMLInputElement} */ (e.target).value) })}
          onKeyUp={(e) => playback({ targetLufs: Number(/** @type {HTMLInputElement} */ (e.target).value) })} />
        <small>{tr('-16 conseillé ; plus haut = plus fort, mais moins de nuances.')}</small>
      </label>
      <label className="check"><input type="checkbox" checked={p.preventClipping} onChange={(e) => playback({ preventClipping: e.target.checked })} /> {tr('Éviter la saturation (le gain est réduit si besoin)')}</label>

      <h2>{tr('Enchaînement')}</h2>
      <label>{tr('Fondu enchaîné : {v}', { v: p.crossfadeMs === 0 ? tr('aucun') : `${p.crossfadeMs / 1000} s` })}
        <input type="range" min={0} max={12000} step={1000} defaultValue={p.crossfadeMs}
          onPointerUp={(e) => playback({ crossfadeMs: Number(/** @type {HTMLInputElement} */ (e.target).value) })}
          onKeyUp={(e) => playback({ crossfadeMs: Number(/** @type {HTMLInputElement} */ (e.target).value) })} />
      </label>
      <label className="check"><input type="checkbox" checked={p.crossfadeWithinAlbum} disabled={p.crossfadeMs === 0} onChange={(e) => playback({ crossfadeWithinAlbum: e.target.checked })} /> {tr('Fondu aussi entre deux pistes d’un même album (déconseillé pour les albums live)')}</label>
      {server?.features.analysis.beatGrid ? (
        <label className="check"><input type="checkbox" checked={p.beatSync !== false} disabled={p.crossfadeMs === 0} onChange={(e) => playback({ beatSync: e.target.checked })} /> {tr('Caler le fondu sur le tempo (quand les deux pistes ont un rythme régulier et des tempos proches)')}</label>
      ) : null}
      <label className="check"><input type="checkbox" checked={p.gapless} onChange={(e) => playback({ gapless: e.target.checked })} /> {tr('Précharger la piste suivante (enchaînement sans attente)')}</label>
      <label className="check">
        <input type="checkbox" checked={simple} onChange={(e) => {
          setSimpleAudio(e.target.checked);
          setSimple(e.target.checked);
          // Web Audio, une fois branché sur le lecteur, ne se débranche pas : il faut recharger la page
          setReloadNeeded(controller.player.ctx !== null || e.target.checked === false);
        }} /> {isFirefoxAndroid()
          ? tr('Son simplifié sur cet appareil (si le son saute ou se coupe par moments ; le volume ne peut alors que baisser ; activé d’office sur Firefox Android)')
          : tr('Son simplifié sur cet appareil (si le son saute ou se coupe par moments ; le volume ne peut alors que baisser)')}
      </label>
      {server?.features.analysis.waveform ? (
        <label className="check"><input type="checkbox" checked={dev.waveform} onChange={(e) => setWaveform(e.target.checked)} /> {tr('Forme d’onde dans le lecteur sur cet appareil')}</label>
      ) : null}
      <label className="check"><input type="checkbox" checked={journal.enabled} onChange={(e) => setDiag(e.target.checked, controller.player)} /> {tr('Journal de diagnostic du son sur cet appareil (note les attentes du flux, les retards et les changements de page, pour comprendre des coupures)')}</label>
      {journal.enabled || journal.lines.length ? (
        <div className="diag">
          <p className="hint">{trn(journal.lines.length, '{n} ligne.', '{n} lignes.')}{' '}
            <button type="button" className="link-btn" onClick={() => {
              navigator.clipboard?.writeText(journal.lines.join('\n')).then(() => setCopied(tr('Journal copié.')), () => setCopied(tr('Copie refusée par le navigateur.')));
            }}>{tr('Copier le journal')}</button>{' '}
            <button type="button" className="link-btn" onClick={() => { clearDiag(); setCopied(''); }}>{tr('Effacer')}</button>
            {copied ? <span> {copied}</span> : null}</p>
          <pre className="diag-log" aria-label={tr('Journal de diagnostic du son')}>{journal.lines.slice(-200).join('\n')}</pre>
        </div>
      ) : null}
      {reloadNeeded ? (
        <p className="hint">{tr('Prend effet au rechargement de la page.')}{' '}
          <button type="button" className="link-btn" onClick={() => window.location.reload()}>{tr('Recharger')}</button></p>
      ) : null}
      <label className="check">
        <input type="checkbox" checked={settings.infinite.enabled} onChange={(e) => {
          const enabled = e.target.checked;
          void save((s) => ({ ...s, infinite: { ...s.infinite, enabled } }));
        }} /> {tr('Mode infini : quand la file se termine, la compléter toute seule')}
      </label>
      {server?.features.continuousPlay ? (
        <fieldset className="continuous" disabled={!settings.infinite.enabled}>
          <legend className="sr-only">{tr('Lecture continue intelligente')}</legend>
          <label className="check">
            <input type="checkbox" checked={c.enabled} onChange={(e) => continuous({ enabled: e.target.checked })} /> {tr('Choisir des pistes qui s’enchaînent bien (sinon : au hasard dans le genre de la piste en cours)')}
          </label>
          {c.enabled ? (
            <div className="continuous-rules">
              {server.features.analysis.key ? (
                <label className="check"><input type="checkbox" checked={c.harmonicMixing} onChange={(e) => continuous({ harmonicMixing: e.target.checked })} /> {tr('Tonalités compatibles (mixage harmonique, roue Camelot)')}</label>
              ) : null}
              {server.features.analysis.bpm ? (
                <label>{tr('Écart de tempo avec la piste précédente')}
                  <select value={c.bpmTolerancePercent ?? ''} onChange={(e) => continuous({ bpmTolerancePercent: e.target.value === '' ? null : Number(e.target.value) })}>
                    <option value="">{tr('Pas de contrainte')}</option>
                    <option value="4">{tr('Très proche (4 %)')}</option>
                    <option value="8">{tr('Proche (8 %)')}</option>
                    <option value="15">{tr('Assez proche (15 %)')}</option>
                  </select>
                </label>
              ) : null}
              {server.features.analysis.energy ? (
                <label>{tr('Énergie d’une piste à l’autre')}
                  <select value={c.energyTrend} onChange={(e) => continuous({ energyTrend: /** @type {any} */ (e.target.value) })}>
                    <option value="any">{tr('Peu importe')}</option>
                    <option value="steady">{tr('Stable')}</option>
                    <option value="rising">{tr('Qui monte')}</option>
                    <option value="falling">{tr('Qui redescend')}</option>
                  </select>
                </label>
              ) : null}
              <label>{tr('Favoris')}
                <select value={String(c.favoritesWeight)} onChange={(e) => continuous({ favoritesWeight: Number(e.target.value) })}>
                  <option value="0">{tr('Comme le reste')}</option>
                  <option value="0.3">{tr('Un peu plus souvent')}</option>
                  <option value="0.6">{tr('Souvent')}</option>
                  <option value="1">{tr('Seulement des favoris (s’il en reste)')}</option>
                </select>
              </label>
              <label>{tr('Ne pas rejouer une piste écoutée depuis moins de')}
                <select value={String(c.noRepeatHours)} onChange={(e) => continuous({ noRepeatHours: Number(e.target.value) })}>
                  <option value="0">{tr('Pas de limite')}</option>
                  <option value="6">{tr('6 heures')}</option>
                  <option value="24">{tr('24 heures')}</option>
                  <option value="72">{tr('3 jours')}</option>
                  <option value="168">{tr('1 semaine')}</option>
                  <option value="720">{tr('30 jours')}</option>
                </select>
              </label>
              <label>{tr('Entre deux pistes d’un même artiste, au moins')}
                <select value={String(c.artistSpacing)} onChange={(e) => continuous({ artistSpacing: Number(e.target.value) })}>
                  <option value="0">{tr('Pas de règle')}</option>
                  <option value="2">{tr('2 pistes')}</option>
                  <option value="3">{tr('3 pistes')}</option>
                  <option value="5">{tr('5 pistes')}</option>
                  <option value="10">{tr('10 pistes')}</option>
                </select>
              </label>
              {genres.length ? (
                <details className="excluded-genres">
                  <summary>{tr('Genres à ne jamais proposer :')} {c.excludedGenres.length
                    ? genres.filter((g) => c.excludedGenres.includes(g.id)).map((g) => g.name).join(', ')
                    : tr('aucun')}</summary>
                  <div className="genre-checks">
                    {genres.map((g) => (
                      <label key={g.id} className="check">
                        <input type="checkbox" checked={c.excludedGenres.includes(g.id)} onChange={(e) => {
                          const on = e.target.checked;
                          continuous({ excludedGenres: on ? [...c.excludedGenres, g.id].sort((x, y) => x - y) : c.excludedGenres.filter((id) => id !== g.id) });
                        }} /> {g.name}
                      </label>
                    ))}
                  </div>
                </details>
              ) : null}
              <small>{tr('Quand plus rien ne respecte toutes les règles, elles sont assouplies une à une plutôt que d’arrêter la musique.')}</small>
            </div>
          ) : null}
        </fieldset>
      ) : null}

      {server?.features.transcoding ? (
        <>
          <h2>{tr('Qualité du flux')}</h2>
          <p className="hint">{tr('Le fichier d’origine (FLAC) est le meilleur, mais il pèse lourd. Une version allégée est convertie à la volée : utile sur téléphone ou avec une connexion lente.')}</p>
          {/** @type {const} */ ([['wifi', tr('En wifi ou filaire')], ['mobile', tr('Avec les données mobiles')]]).map(([kind, label]) => (
            <label key={kind}>{label}
              <select value={p.streamQuality[kind]}
                onChange={(e) => playback({ streamQuality: { ...p.streamQuality, [kind]: /** @type {any} */ (e.target.value) } })}>
                <option value="original">{tr('Origine (sans perte quand il existe : environ 7 Mo par minute en FLAC)')}</option>
                <option value="high">{tr('Haute (256 kbit/s, environ 2 Mo par minute)')}</option>
                <option value="medium">{tr('Moyenne (160 kbit/s, environ 1,2 Mo par minute)')}</option>
                <option value="low">{tr('Économique (96 kbit/s, environ 0,7 Mo par minute)')}</option>
              </select>
            </label>
          ))}
          <small>{tr('Les données mobiles ne sont reconnues que par certains navigateurs (Chrome sur Android) ; ailleurs, c’est le réglage wifi qui s’applique. Le changement vaut à partir de la piste suivante.')}</small>
        </>
      ) : null}

      <h2>{tr('Apparence')}</h2>
      <label>{tr('Thème')}
        <select value={settings.interface.theme} onChange={(e) => save((s) => ({ ...s, interface: { ...s.interface, theme: /** @type {any} */ (e.target.value) } }))}>
          <option value="auto">{tr('Comme le système')}</option>
          <option value="dark">{tr('Sombre')}</option>
          <option value="light">{tr('Clair')}</option>
        </select>
      </label>
      <label className="check">
        <input type="checkbox" checked={settings.interface.background !== false} onChange={(e) => {
          const background = e.target.checked;
          void save((s) => ({ ...s, interface: { ...s.interface, background } }));
        }} /> {tr('Afficher une image de fond (choisie par l’admin, au hasard à chaque visite)')}
      </label>

      {server?.features.remoteControl ? <RemoteShares /> : null}

      <h2>{tr('Appareils connectés')}</h2>
      <ul className="devices">
        {list.map((d) => (
          <li key={d.id}>
            <span>{d.name} <small>{d.kind === 'web' ? tr('navigateur') : d.kind === 'app' ? tr('appli') : tr('agent')}{d.current ? ` · ${tr('cet appareil')}` : ''}{d.lastUsedAt ? ` · ${tr('vu le {date}', { date: new Date(d.lastUsedAt).toLocaleDateString(locale()) })}` : ''}</small></span>
            {!d.current ? (
              <button type="button" className="link-btn" onClick={async () => {
                await request('DELETE', `/me/devices/${d.id}`);
                devices.reload();
              }}>{tr('Déconnecter')}</button>
            ) : null}
          </li>
        ))}
      </ul>
      {server?.features.pairing && user?.role !== 'agent' ? <PairDevice onPaired={devices.reload} /> : null}

      <h2>{tr('Compte')}</h2>
      <p>{user?.displayName} · {user?.email}</p>
      <button type="button" onClick={() => {
        controller.reset();
        void logout();
      }}>{tr('Se déconnecter')}</button>
      {user?.role !== 'agent' ? <><ExportData /><DeleteAccount /></> : null}
    </section>
  );
}
