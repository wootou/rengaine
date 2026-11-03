// Visionneuse des captures (PUB-11) : un clic sur une capture l'ouvre en grand par-dessus la page, au
// lieu d'aller sur l'image seule. On la ferme par le bouton ×, Échap, ou un clic à côté de l'image ;
// les flèches passent d'une capture à l'autre. Sans JavaScript, le lien mène toujours à l'image.
// Pas de style en ligne (CSP du site : style-src 'self') : tout est dans site.css.
'use strict';
(() => {
  const links = /** @type {HTMLAnchorElement[]} */ ([...document.querySelectorAll('figure.shot > a')]);
  if (links.length === 0 || typeof HTMLDialogElement === 'undefined') return;
  const en = document.documentElement.lang === 'en';
  const text = en
    ? { close: 'Close', prev: 'Previous screenshot', next: 'Next screenshot', label: 'Screenshot' }
    : { close: 'Fermer', prev: 'Capture précédente', next: 'Capture suivante', label: 'Capture d’écran' };

  const dialog = document.createElement('dialog');
  dialog.className = 'viewer';
  dialog.setAttribute('aria-label', text.label);
  const img = document.createElement('img');
  const close = button('viewer-close', text.close, '×');
  const prev = button('viewer-prev', text.prev, '‹');
  const next = button('viewer-next', text.next, '›');
  dialog.append(img, close, prev, next);
  document.body.append(dialog);
  let index = 0;

  /** @param {string} cls @param {string} label @param {string} sign */
  function button(cls, label, sign) {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = cls;
    b.setAttribute('aria-label', label);
    b.textContent = sign;
    return b;
  }

  /** @param {number} i */
  function show(i) {
    index = (i + links.length) % links.length;
    const thumb = links[index].querySelector('img');
    // currentSrc : la version claire ou sombre déjà choisie par le navigateur
    img.src = thumb?.currentSrc || links[index].href;
    img.alt = thumb?.alt ?? '';
    dialog.classList.toggle('phone', links[index].parentElement?.classList.contains('phone') ?? false);
    prev.hidden = next.hidden = links.length < 2;
  }

  links.forEach((a, i) => a.addEventListener('click', (e) => {
    if (e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return; // nouvel onglet : laisser faire
    e.preventDefault();
    show(i);
    dialog.showModal();
    close.focus();
  }));
  close.addEventListener('click', () => dialog.close());
  prev.addEventListener('click', () => show(index - 1));
  next.addEventListener('click', () => show(index + 1));
  // Clic à côté de l'image (sur le fond du dialogue) : on ferme
  dialog.addEventListener('click', (e) => {
    if (e.target === dialog) dialog.close();
  });
  dialog.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowLeft') show(index - 1);
    else if (e.key === 'ArrowRight') show(index + 1);
  });
  // Rendre le focus à la capture d'où l'on venait
  dialog.addEventListener('close', () => links[index].focus());
})();
