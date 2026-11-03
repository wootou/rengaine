// Page d'essai du son (diagnostic) : une piste jouée par un élément <audio>, seul ou branché sur Web Audio,
// et un journal de ce qui peut faire sauter le son : attente de données (waiting, stalled), timeupdate en
// retard, fil principal bloqué (minuteur de 100 ms en retard).
// « Sortie » compare à l'oreille le son direct et plusieurs réglages de Web Audio (son dégradé sous Firefox
// bureau, 2026-11-02). Volume 1 et gain 1 partout : seule la sortie change. Changer de sortie en cours
// d'écoute reprend à la même position (un élément branché sur Web Audio ne peut plus s'en détacher :
// on en crée un neuf).
'use strict';
const log = document.getElementById('log');
const t0 = performance.now();
/** @param {string} msg */
function note(msg) {
  const s = ((performance.now() - t0) / 1000).toFixed(2).padStart(8);
  log.textContent += `${new Date().toLocaleTimeString('fr-FR')} ${s} s  ${msg}\n`;
  log.scrollTop = log.scrollHeight;
}

/** Réglages de sortie : null = élément seul ; sinon les options de l'AudioContext. */
const OUTPUTS = {
  direct: null,
  defaut: {},
  playback: { latencyHint: 'playback' },
  r44: { latencyHint: 'playback', sampleRate: 44100 },
  r48: { latencyHint: 'playback', sampleRate: 48000 },
};

/** @type {HTMLAudioElement} */
let audio;
/** @type {AudioContext | null} */
let ctx = null;
let lastUpdate = 0;
let lastPos = 0;

function newAudio() {
  const a = new Audio();
  a.preload = 'auto';
  for (const ev of ['loadstart', 'loadedmetadata', 'canplay', 'canplaythrough', 'playing', 'pause', 'waiting', 'stalled', 'suspend', 'emptied', 'ended', 'error', 'seeking', 'seeked']) {
    a.addEventListener(ev, () => {
      if (a !== audio) return;
      const b = a.buffered.length ? `${a.buffered.end(a.buffered.length - 1).toFixed(1)} s chargées` : 'rien de chargé';
      note(`${ev.padEnd(15)} pos ${a.currentTime.toFixed(2)} s, ${b}${ev === 'error' && a.error ? ` (code ${a.error.code})` : ''}`);
    });
  }
  // timeupdate : arrive normalement toutes les 250 ms environ ; un trou montre un son ou un fil d'exécution arrêté
  a.addEventListener('timeupdate', () => {
    if (a !== audio) return;
    const now = performance.now();
    if (lastUpdate && !a.paused && now - lastUpdate > 600) note(`timeupdate en retard : ${Math.round(now - lastUpdate)} ms`);
    if (!a.paused && lastUpdate && a.currentTime - lastPos < 0.05 && now - lastUpdate > 200) note(`position figée à ${a.currentTime.toFixed(2)} s`);
    lastUpdate = now;
    lastPos = a.currentTime;
  });
  return a;
}
audio = newAudio();
let wiredOutput = 'direct';

/** Branche l'élément courant sur la sortie choisie (un élément neuf si la sortie change). */
function wire(key) {
  if (key === wiredOutput) return;
  const old = audio;
  const pos = old.currentTime;
  const wasPlaying = !old.paused;
  const src = old.currentSrc || old.src;
  old.pause();
  old.removeAttribute('src');
  old.load();
  if (ctx) void ctx.close();
  ctx = null;
  audio = newAudio();
  wiredOutput = key;
  const opts = OUTPUTS[key];
  if (opts) {
    try {
      ctx = new AudioContext(opts);
      ctx.createMediaElementSource(audio).connect(ctx.destination);
      const lat = (x) => (typeof x === 'number' ? `${Math.round(x * 1000)} ms` : '?');
      note(`Web Audio : ${ctx.sampleRate} Hz, latence de base ${lat(ctx.baseLatency)}, de sortie ${lat(ctx.outputLatency)}, état ${ctx.state}`);
    } catch (e) {
      note(`Web Audio refusé : ${e.message}`);
      ctx = null;
    }
  } else {
    note('sortie directe (sans Web Audio)');
  }
  if (src) {
    audio.src = src;
    audio.currentTime = pos;
    lastUpdate = 0;
    if (wasPlaying) start();
  }
}

function start() {
  if (ctx?.state === 'suspended') void ctx.resume();
  audio.play().catch((e) => note(`lecture refusée : ${e.message}`));
}

// Fil principal bloqué : un minuteur de 100 ms qui arrive avec plus de 150 ms de retard
let tick = performance.now();
setInterval(() => {
  const now = performance.now();
  if (now - tick > 250) note(`fil principal bloqué ${Math.round(now - tick - 100)} ms`);
  tick = now;
}, 100);

/** @param {any} t */
function trackOption(t) {
  const o = document.createElement('option');
  o.value = t.streamUrl;
  const f = t.formats[0];
  const fmt = f ? ` [${f.format}${f.bitrateKbps ? ` ${f.bitrateKbps} kbit/s` : ''}${f.sampleRateHz ? ` ${f.sampleRateHz} Hz` : ''}]` : '';
  o.textContent = `${t.title} — ${t.artistCredit}${fmt}`;
  return o;
}

const select = document.getElementById('track');
fetch('/api/v2/tracks?limit=200&sort=name').then((r) => {
  if (r.status === 401) throw new Error('pas connecté : se connecter d’abord sur le lecteur, puis revenir ici');
  return r.json();
}).then((page) => {
  for (const t of page.items) select.append(trackOption(t));
  note(`${page.items.length} pistes ; navigateur : ${navigator.userAgent}`);
}).catch((e) => note(`ERREUR : ${e.message}`));

// Une piste précise par son numéro (celui du journal du serveur, ou ?piste=123 dans l'adresse)
const trackId = document.getElementById('track-id');
function loadTrackId() {
  const id = trackId.value.trim();
  if (!/^\d+$/.test(id)) return;
  fetch(`/api/v2/tracks/${id}`).then((r) => {
    if (!r.ok) throw new Error(`piste ${id} : HTTP ${r.status}`);
    return r.json();
  }).then((t) => {
    const o = trackOption(t);
    select.prepend(o);
    select.value = o.value;
    note(`piste ${id} ajoutée en tête : ${o.textContent}`);
  }).catch((e) => note(`ERREUR : ${e.message}`));
}
document.getElementById('track-load').addEventListener('click', loadTrackId);
const wanted = new URLSearchParams(location.search).get('piste');
if (wanted) {
  trackId.value = wanted;
  loadTrackId();
}

const format = document.getElementById('format');
const output = document.getElementById('output');
output.addEventListener('change', () => {
  note(`--- sortie : ${output.selectedOptions[0]?.textContent}`);
  wire(output.value);
});
document.getElementById('play').addEventListener('click', () => {
  wire(output.value);
  const url = select.value + (format.value ? `?${format.value}` : '');
  if (audio.src !== new URL(url, location.href).href) {
    note(`--- ${select.selectedOptions[0]?.textContent} — ${format.selectedOptions[0]?.textContent} — ${output.selectedOptions[0]?.textContent}`);
    audio.src = url;
  }
  lastUpdate = 0;
  start();
});
document.getElementById('stop').addEventListener('click', () => audio.pause());
document.getElementById('copy').addEventListener('click', () => {
  navigator.clipboard?.writeText(log.textContent).then(() => note('journal copié'), () => note('copie refusée'));
});

// De quoi faire défiler
const rows = document.getElementById('rows');
for (let i = 1; i <= 400; i++) {
  const d = document.createElement('div');
  d.className = 'row';
  d.textContent = `Ligne ${i} — faire défiler pendant l'écoute`;
  rows.append(d);
}
