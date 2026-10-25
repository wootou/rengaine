// @ts-check
// Pochette : vignette à la taille d'affichage (s/m/l), chargée seulement quand elle devient visible.
import { imageUrl } from '../api/client.js';
import { Icon } from './Icon.jsx';
import { tr } from '../i18n/index.js';

/** @param {{ url: string | null | undefined, size: 's' | 'm' | 'l', alt?: string, className?: string }} props */
export function Cover({ url, size, alt = '', className = '' }) {
  if (!url) {
    return (
      <div className={`cover cover-empty ${className}`} role="img" aria-label={alt || tr('Pas de pochette')}>
        <Icon name="disc" size={size === 's' ? 24 : 56} />
      </div>
    );
  }
  const bigger = size === 's' ? 'm' : 'l';
  const px = { s: 96, m: 300, l: 600 }[size];
  return (
    <img
      className={`cover ${className}`}
      src={imageUrl(url, size) ?? undefined}
      srcSet={`${imageUrl(url, size)} 1x, ${imageUrl(url, bigger)} 2x`}
      width={px}
      height={px}
      loading="lazy"
      decoding="async"
      alt={alt}
    />
  );
}
