// @ts-check
// Préférences propres à cet appareil (gardées dans le navigateur, pas sur le serveur).
import { Store } from './store.js';

const WAVE_KEY = 'rengaine.ondeMasquee';
const ALBUMS_KEY = 'rengaine.vueAlbums';

function read() {
  try {
    return { waveform: localStorage.getItem(WAVE_KEY) !== '1', albumsList: localStorage.getItem(ALBUMS_KEY) === 'liste' };
  } catch {
    return { waveform: true, albumsList: false };
  }
}

/** @type {Store<{ waveform: boolean, albumsList: boolean }>} */
export const device = new Store(read());

/** Forme d'onde affichée ou non sur cet appareil. @param {boolean} on */
export function setWaveform(on) {
  try {
    if (on) localStorage.removeItem(WAVE_KEY);
    else localStorage.setItem(WAVE_KEY, '1');
  } catch {
    // stockage indisponible : valable jusqu'au rechargement
  }
  device.set({ waveform: on });
}

/** Albums en liste plutôt qu'en grille de pochettes, sur cet appareil. @param {boolean} on */
export function setAlbumsList(on) {
  try {
    if (on) localStorage.setItem(ALBUMS_KEY, 'liste');
    else localStorage.removeItem(ALBUMS_KEY);
  } catch {
    // stockage indisponible : valable jusqu'au rechargement
  }
  device.set({ albumsList: on });
}
