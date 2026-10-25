// @ts-check
// Image de fond du site : une des images activées par l'admin (Admin > Fonds), tirée au hasard à
// chaque chargement de la page, derrière le contenu et sous un voile de la couleur du thème pour
// que le texte reste lisible. Chaque compte peut la masquer (Réglages > Apparence).
import { useState } from 'react';
import { useApi, useSession } from '../hooks.js';

/** @typedef {import('../api/types.js').Schemas['BackgroundRef']} BackgroundRef */

export function SiteBackdrop() {
  const { settings } = useSession();
  const on = settings !== null && settings.interface.background !== false;
  const api = useApi(on ? '/backgrounds' : null);
  const [draw] = useState(() => Math.random());
  const items = /** @type {{ items: BackgroundRef[] } | null} */ (api.data)?.items ?? [];
  if (!on || items.length === 0) return null;
  const bg = items[Math.floor(draw * items.length)];
  return (
    <div className="site-backdrop" aria-hidden="true">
      <img src={bg.imageUrl} alt="" decoding="async" />
    </div>
  );
}
