// Page d'essai du son (diagnostic Firefox Android) : un élément <audio> seul, et un journal de ce qui
// peut faire sauter le son : attente de données (waiting, stalled), timeupdate en retard, main thread
// bloqué (minuteur de 100 ms en retard).
'use strict';
const log = document.getElementById('log');
const t0 = performance.now();
/** @param {string} msg */
function note(msg) {
  const s = ((performance.now() - t0) / 1000).toFixed(2).padStart(8);
  log.textContent += `${new Date().toLocaleTimeString('fr-FR')} ${s} s  ${msg}\n`;
  log.scrollTop = log.scrollHeight;
}

const audio = new Audio();
audio.preload = 'auto';
for (const ev of ['loadstart', 'loadedmetadata', 'canplay', 'canplaythrough', 'playing', 'pause', 'waiting', 'stalled', 'suspend', 'emptied', 'ended', 'error', 'seeking', 'seeked']) {
  audio.addEventListener(ev, () => {
    const b = audio.buffered.length ? `${audio.buffered.end(audio.buffered.length - 1).toFixed(1)} s chargées` : 'rien de chargé';
    note(`${ev.padEnd(15)} pos ${audio.currentTime.toFixed(2)} s, ${b}${ev === 'error' && audio.error ? ` (code ${audio.error.code})` : ''}`);
  });
}
// timeupdate : arrive normalement toutes les 250 ms environ ; un trou montre un son ou un fil d'exécution arrêté
let lastUpdate = 0;
let lastPos = 0;
audio.addEventListener('timeupdate', () => {
  const now = performance.now();
  if (lastUpdate && !audio.paused && now - lastUpdate > 600) note(`timeupdate en retard : ${Math.round(now - lastUpdate)} ms`);
  if (!audio.paused && lastUpdate && audio.currentTime - lastPos < 0.05 && now - lastUpdate > 200) note(`position figée à ${audio.currentTime.toFixed(2)} s`);
  lastUpdate = now;
  lastPos = audio.currentTime;
});
// Fil principal bloqué : un minuteur de 100 ms qui arrive avec plus de 150 ms de retard
let tick = performance.now();
setInterval(() => {
  const now = performance.now();
  if (now - tick > 250) note(`fil principal bloqué ${Math.round(now - tick - 100)} ms`);
  tick = now;
}, 100);

const select = document.getElementById('track');
fetch('/api/v2/tracks?limit=200&sort=name').then((r) => {
  if (r.status === 401) throw new Error('pas connecté : se connecter d’abord sur le lecteur, puis revenir ici');
  return r.json();
}).then((page) => {
  for (const t of page.items) {
    const o = document.createElement('option');
    o.value = t.streamUrl;
    const fmt = t.formats[0] ? ` [${t.formats[0].format}${t.formats[0].bitrateKbps ? ` ${t.formats[0].bitrateKbps} kbit/s` : ''}]` : '';
    o.textContent = `${t.title} — ${t.artistCredit}${fmt}`;
    select.append(o);
  }
  note(`${page.items.length} pistes ; navigateur : ${navigator.userAgent}`);
}).catch((e) => note(`ERREUR : ${e.message}`));

const format = document.getElementById('format');
document.getElementById('play').addEventListener('click', () => {
  const url = select.value + (format.value ? `?${format.value}` : '');
  if (audio.src !== new URL(url, location.href).href) {
    note(`--- ${select.selectedOptions[0]?.textContent} — ${format.selectedOptions[0]?.textContent}`);
    audio.src = url;
  }
  lastUpdate = 0;
  audio.play().catch((e) => note(`lecture refusée : ${e.message}`));
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
