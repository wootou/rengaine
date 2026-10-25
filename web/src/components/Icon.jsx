// @ts-check
// Icônes en SVG intégré (aucune police d'icônes à charger).
// Plusieurs tracés reprennent ou adaptent ceux de Material Icons (Google, Apache License 2.0) :
// voir THIRD_PARTY_NOTICES.md.

const PATHS = {
  play: 'M8 5v14l11-7z',
  pause: 'M6 5h4v14H6zm8 0h4v14h-4z',
  next: 'M6 18l8.5-6L6 6v12zM16 6h2v12h-2z',
  prev: 'M6 6h2v12H6zm3.5 6l8.5 6V6z',
  volume: 'M3 9v6h4l5 5V4L7 9H3zm13.5 3A4.5 4.5 0 0 0 14 8v8a4.5 4.5 0 0 0 2.5-4zM14 3.2v2.1a7 7 0 0 1 0 13.4v2.1a9 9 0 0 0 0-17.6z',
  volumeOff: 'M16.5 12A4.5 4.5 0 0 0 14 8v2.2l2.5 2.5V12zm2.5 0c0 .9-.2 1.8-.5 2.6l1.5 1.5A8.8 8.8 0 0 0 21 12a9 9 0 0 0-7-8.8v2.1a7 7 0 0 1 5 6.7zM4.3 3L3 4.3 7.7 9H3v6h4l5 5v-6.7l4.3 4.2c-.7.5-1.4.9-2.3 1.2v2.1a9 9 0 0 0 3.7-1.8l2 2 1.3-1.3-9-9zM12 4L9.9 6.1 12 8.2V4z',
  queue: 'M3 6h12v2H3zm0 5h12v2H3zm0 5h8v2H3zm14-5v6l5-3z',
  close: 'M19 6.4L17.6 5 12 10.6 6.4 5 5 6.4 10.6 12 5 17.6 6.4 19 12 13.4 17.6 19 19 17.6 13.4 12z',
  add: 'M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6z',
  minus: 'M19 13H5v-2h14z',
  playNext: 'M3 10h11v2H3zm0-4h11v2H3zm0 8h7v2H3zm13-1v8l6-4z',
  heart: 'M12 21.4l-1.5-1.3C5.4 15.4 2 12.3 2 8.5 2 5.4 4.4 3 7.5 3c1.7 0 3.4.8 4.5 2.1C13.1 3.8 14.8 3 16.5 3 19.6 3 22 5.4 22 8.5c0 3.8-3.4 6.9-8.5 11.5z',
  heartOutline: 'M16.5 3c-1.7 0-3.4.8-4.5 2.1C10.9 3.8 9.2 3 7.5 3 4.4 3 2 5.4 2 8.5c0 3.8 3.4 6.9 8.5 11.5L12 21.4l1.5-1.4C18.6 15.4 22 12.3 22 8.5 22 5.4 19.6 3 16.5 3zm-4.4 15.6l-.1.1-.1-.1C7.1 14.2 4 11.4 4 8.5 4 6.5 5.5 5 7.5 5c1.5 0 3 1 3.6 2.4h1.9C13.5 6 15 5 16.5 5c2 0 3.5 1.5 3.5 3.5 0 2.9-3.1 5.7-7.9 10.1z',
  repeat: 'M7 7h10v3l4-4-4-4v3H5v6h2zm10 10H7v-3l-4 4 4 4v-3h12v-6h-2z',
  repeatOne: 'M7 7h10v3l4-4-4-4v3H5v6h2zm10 10H7v-3l-4 4 4 4v-3h12v-6h-2zm-4-2V9h-1l-2 1v1h1.5v4z',
  infinite: 'M18.6 6.6c-1.4 0-2.8.6-3.8 1.6L12 10.7 10.8 9.6l-.1-.1 0 0C9.6 7.8 7.5 6.6 5.4 6.6 2.4 6.6 0 9 0 12s2.4 5.4 5.4 5.4c1.4 0 2.8-.6 3.8-1.6l2.8-2.5 1.2 1.1c1 1 2.4 1.6 3.8 1.6 3 0 5.4-2.4 5.4-5.4s-2.4-5.4-5.4-5.4zM7.8 14.8c-.6.6-1.5 1-2.4 1-1.9 0-3.4-1.5-3.4-3.4S3.5 8.9 5.4 8.9c.9 0 1.8.4 2.5 1.1L10.5 12l-2.7 2.8zm10.8 1c-.9 0-1.8-.4-2.5-1.1L13.5 12l2.7-2.7c.6-.6 1.5-1 2.4-1 1.9 0 3.4 1.5 3.4 3.4s-1.5 3.1-3.4 3.1z',
  shuffle: 'M10.6 9.2L5.4 4 4 5.4l5.2 5.2zM14.5 4l2 2L4 18.6 5.4 20 18 7.5l2 2V4zm.3 9.4l-1.4 1.4 3.1 3.1-2 2H20v-5.5l-2 2z',
  search: 'M15.5 14h-.8l-.3-.3A6.5 6.5 0 1 0 9.5 16a6.5 6.5 0 0 0 4.2-1.6l.3.3v.8l5 5 1.5-1.5zm-6 0C7 14 5 12 5 9.5S7 5 9.5 5 14 7 14 9.5 12 14 9.5 14z',
  settings: 'M19.4 13a7.5 7.5 0 0 0 0-2l2.1-1.6-2-3.5-2.5 1a7.3 7.3 0 0 0-1.7-1l-.4-2.6h-4l-.4 2.6c-.6.2-1.2.6-1.7 1l-2.5-1-2 3.5L4.6 11a7.5 7.5 0 0 0 0 2l-2.1 1.6 2 3.5 2.5-1c.5.4 1.1.8 1.7 1l.4 2.6h4l.4-2.6c.6-.2 1.2-.6 1.7-1l2.5 1 2-3.5zM12 15.5a3.5 3.5 0 1 1 0-7 3.5 3.5 0 0 1 0 7z',
  edit: 'M3 17.25V21h3.75L17.8 9.94l-3.75-3.75zM20.7 7.04a1 1 0 0 0 0-1.41l-2.34-2.34a1 1 0 0 0-1.41 0l-1.83 1.83 3.75 3.75z',
  disc: 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm0 14.5a4.5 4.5 0 1 1 0-9 4.5 4.5 0 0 1 0 9zm0-5.5a1 1 0 1 0 0 2 1 1 0 0 0 0-2z',
  list: 'M3 14h4v-4H3v4zm0 5h4v-4H3v4zM3 9h4V5H3v4zm5 5h13v-4H8v4zm0 5h13v-4H8v4zM8 5v4h13V5H8z',
  albums: 'M4 4h7v7H4zm9 0h7v7h-7zM4 13h7v7H4zm9 0h7v7h-7z',
  artist: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm0 2c-3.3 0-8 1.7-8 5v1h16v-1c0-3.3-4.7-5-8-5z',
  genre: 'M21.4 11.6l-9-9A2 2 0 0 0 11 2H4a2 2 0 0 0-2 2v7c0 .6.2 1.1.6 1.4l9 9a2 2 0 0 0 2.8 0l7-7a2 2 0 0 0 0-2.8zM6.5 8A1.5 1.5 0 1 1 6.5 5a1.5 1.5 0 0 1 0 3z',
  admin: 'M12 2L4 5v6c0 5 3.4 9.7 8 11 4.6-1.3 8-6 8-11V5z',
  more: 'M12 8a2 2 0 1 0 0-4 2 2 0 0 0 0 4zm0 2a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm0 6a2 2 0 1 0 0 4 2 2 0 0 0 0-4z',
  chevronDown: 'M7.4 8.6L12 13.2l4.6-4.6L18 10l-6 6-6-6z',
  chevronUp: 'M7.4 15.4L12 10.8l4.6 4.6L18 14l-6-6-6 6z',
  playlist: 'M15 6H3v2h12V6zm0 4H3v2h12v-2zM3 16h8v-2H3v2zM17 6v8.2a3 3 0 1 0 2 2.8V8h3V6h-5z',
  playlistAdd: 'M14 10H3v2h11v-2zm0-4H3v2h11V6zm4 8v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zM3 16h7v-2H3v2z',
  clearAll: 'M5 13h14v-2H5v2zm-2 4h14v-2H3v2zM7 7v2h14V7H7z',
  share: 'M18 16.1c-.8 0-1.4.3-2 .8l-7.1-4.2c.1-.2.1-.5.1-.7s0-.5-.1-.7l7-4.1A3 3 0 1 0 15 5c0 .2 0 .5.1.7L8 9.8A3 3 0 1 0 8 14.2l7.1 4.2c-.1.2-.1.4-.1.6a3 3 0 1 0 3-2.9z',
  trash: 'M6 19a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z',
};

/** @typedef {keyof typeof PATHS} IconName */

/**
 * Monogramme Rengaine : un disque dont le sillon revient sur lui-même (la chanson qu'on se repasse).
 * Couleurs reprises des variables du thème (--accent, --on-accent).
 * @param {{ size?: number }} props
 */
export function Logo({ size = 32 }) {
  return (
    <svg className="logo-mark" width={size} height={size} viewBox="0 0 32 32" aria-hidden="true">
      <rect width="32" height="32" rx="9" fill="var(--accent)" />
      <path d="M24.08 13.06A8.6 8.6 0 1 1 14.51 7.53" fill="none" stroke="var(--on-accent)" strokeWidth="2.6" strokeLinecap="round" />
      <path d="M19.04 6.73L13.88 3.99l1.25 7.09z" fill="var(--on-accent)" />
      <circle cx="16" cy="16" r="2.4" fill="var(--on-accent)" />
    </svg>
  );
}

/** @param {{ name: IconName, size?: number, title?: string }} props */
export function Icon({ name, size = 22, title }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" aria-hidden={title ? undefined : true} role={title ? 'img' : undefined} fill="currentColor">
      {title ? <title>{title}</title> : null}
      <path d={PATHS[name]} />
    </svg>
  );
}
