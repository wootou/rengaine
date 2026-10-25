// @ts-check
import { useState } from 'react';
import { request } from '../api/client.js';
import { Icon } from './Icon.jsx';
import { tr } from '../i18n/index.js';

/**
 * Cœur des favoris (WEB-08). `label` : texte visible à côté du cœur (menu d'actions sur téléphone) ;
 * caché aux lecteurs d'écran, le nom accessible reste le `title`.
 * @param {{ type: 'track' | 'album' | 'artist', id: number, initial: boolean, label?: string }} props
 */
export function Favorite({ type, id, initial, label }) {
  const [on, setOn] = useState(initial);
  const [busy, setBusy] = useState(false);
  return (
    <button
      type="button"
      className={`icon-btn favorite ${on ? 'on' : ''}`}
      aria-pressed={on}
      title={on ? tr('Retirer des favoris') : tr('Ajouter aux favoris')}
      disabled={busy}
      onClick={async (e) => {
        e.stopPropagation();
        setBusy(true);
        try {
          await request(on ? 'DELETE' : 'PUT', `/me/favorites/${type}/${id}`);
          setOn(!on);
        } finally {
          setBusy(false);
        }
      }}
    >
      <Icon name={on ? 'heart' : 'heartOutline'} size={20} />
      {label ? <span className="action-label" aria-hidden="true">{label}</span> : null}
    </button>
  );
}
