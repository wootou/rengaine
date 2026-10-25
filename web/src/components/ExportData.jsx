// @ts-check
// Export des données du compte (CPT-10) : un fichier JSON avec tout ce que le serveur garde
// (profil, réglages, file, favoris, playlists, historique, appareils, liens de télécommande).
import { useState } from 'react';
import { ApiError, request } from '../api/client.js';
import { Link } from '../router.jsx';
import { tr } from '../i18n/index.js';

/** Nom du fichier : rengaine-export-AAAAMMJJ.json (date du jour, en local). */
export function exportFileName(now = new Date()) {
  const p = (/** @type {number} */ n) => String(n).padStart(2, '0');
  return `rengaine-export-${now.getFullYear()}${p(now.getMonth() + 1)}${p(now.getDate())}.json`;
}

export function ExportData() {
  const [status, setStatus] = useState('');
  const [busy, setBusy] = useState(false);

  const download = async () => {
    setBusy(true);
    setStatus(tr('Préparation du fichier…'));
    try {
      const r = await request('GET', '/me/export');
      const blob = new Blob([JSON.stringify(r.data, null, 2)], { type: 'application/json' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = exportFileName();
      document.body.append(a);
      a.click();
      a.remove();
      setTimeout(() => URL.revokeObjectURL(url), 10_000);
      setStatus(tr('Fichier téléchargé.'));
    } catch (e) {
      setStatus(e instanceof ApiError && e.status === 429 ? tr('Trop d’exports d’affilée : réessayez dans une heure.') : tr('Export impossible pour l’instant, réessayez.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="export-data">
      <h3>{tr('Mes données')}</h3>
      <p className="hint">{tr('Un fichier avec tout ce que Rengaine garde sur vous : profil, réglages, favoris, playlists, historique d’écoute, appareils.')}</p>
      <button type="button" disabled={busy} onClick={() => void download()}>{tr('Télécharger mes données')}</button>
      <p className="hint"><Link to="/historique">{tr('Voir ou effacer mon historique d’écoute')}</Link></p>
      {status ? <p className="hint" role="status">{status}</p> : null}
    </div>
  );
}
