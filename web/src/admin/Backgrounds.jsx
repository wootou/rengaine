// @ts-check
// Admin > Fonds : les images de fond du site. Le navigateur réduit chaque image avant l'envoi
// (2560 px de large au plus, WebP ou JPEG) : une photo de téléphone de 5 Mo devient ~300 Ko.
import { useState } from 'react';
import { request } from '../api/client.js';
import { useApi } from '../hooks.js';
import { Feedback, formatSize, useAction } from './common.jsx';
import { tr, trn } from '../i18n/index.js';

/** @typedef {import('../api/types.js').Schemas} Schemas */

const MAX_WIDTH = 2560;
const MAX_HEIGHT = 1600;

/**
 * Réduit une image dans le navigateur. WebP si le navigateur sait l'écrire, sinon JPEG.
 * @param {File} file @param {number} [maxWidth] @param {number} [maxHeight] @returns {Promise<Blob>}
 */
export async function shrink(file, maxWidth = MAX_WIDTH, maxHeight = MAX_HEIGHT) {
  const bitmap = await createImageBitmap(file);
  const scale = Math.min(1, maxWidth / bitmap.width, maxHeight / bitmap.height);
  const canvas = document.createElement('canvas');
  canvas.width = Math.round(bitmap.width * scale);
  canvas.height = Math.round(bitmap.height * scale);
  const ctx = canvas.getContext('2d');
  if (!ctx) throw new Error(tr('Dessin impossible dans ce navigateur'));
  ctx.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
  bitmap.close();
  /** @param {string} type @returns {Promise<Blob | null>} */
  const encode = (type) => new Promise((resolve) => canvas.toBlob(resolve, type, 0.85));
  const webp = await encode('image/webp');
  if (webp && webp.type === 'image/webp') return webp;
  const jpeg = await encode('image/jpeg');
  if (!jpeg) throw new Error(tr('Image impossible à convertir'));
  return jpeg;
}

export function Backgrounds() {
  const api = useApi('/admin/backgrounds');
  const items = /** @type {{ items: Schemas['Background'][] } | null} */ (api.data)?.items ?? [];
  const action = useAction();
  const [progress, setProgress] = useState('');

  /** @param {FileList | null} files */
  const upload = async (files) => {
    const list = [...(files ?? [])];
    if (!list.length) return;
    let done = 0;
    const ok = await action.run(async () => {
      for (const file of list) {
        setProgress(`Envoi ${done + 1} / ${list.length} : ${file.name}`);
        const blob = await shrink(file);
        const name = file.name.replace(/\.[^.]+$/, '').slice(0, 100);
        await request('POST', '/admin/backgrounds', { blob, query: { name } });
        done++;
      }
    }, trn(list.length, 'Image ajoutée.', '{n} images ajoutées.'));
    setProgress(ok || done === 0 ? '' : trn(done, '{n} image ajoutée avant l’erreur.', '{n} images ajoutées avant l’erreur.'));
    api.reload();
  };

  /** @param {Schemas['Background']} b @param {{ name?: string, active?: boolean }} patch @param {string} ok */
  const update = async (b, patch, ok) => {
    if (await action.run(() => request('PATCH', `/admin/backgrounds/${b.id}`, { body: patch }), ok)) api.reload();
  };

  return (
    <section className="backgrounds-admin">
      <div className="page-head">
        <h2 className="admin-title">{tr('Images de fond')} <small>{items.length} / 30</small></h2>
        <label className="button primary file-button">
          {tr('Ajouter des images')}
          <input type="file" accept="image/jpeg,image/png,image/webp" multiple disabled={action.busy}
            onChange={(e) => { void upload(e.target.files); e.target.value = ''; }} />
        </label>
      </div>
      <p className="hint">
        {tr('Une image active est tirée au hasard à chaque visite et s’affiche derrière tout le site, voilée pour que le texte reste lisible. Chacun peut la masquer dans Réglages. Les images sont réduites à 2560 px avant l’envoi.')}
      </p>
      {progress ? <p className="hint" role="status">{progress}</p> : null}
      <Feedback message={action.message} error={action.error} />
      {items.length ? (
        <ul className="bg-grid">
          {items.map((b) => (
            <li key={b.id} className={`bg-card card ${b.active ? '' : 'inactive'}`}>
              <img src={b.imageUrl} alt={b.name} loading="lazy" decoding="async" />
              <div className="bg-card-body">
                <input aria-label={tr('Nom de l’image {nom}', { nom: b.name })} defaultValue={b.name} maxLength={100}
                  onBlur={(e) => {
                    const name = e.target.value.trim();
                    if (name && name !== b.name) void update(b, { name }, tr('Nom enregistré.'));
                    else e.target.value = b.name;
                  }}
                  onKeyDown={(e) => e.key === 'Enter' && /** @type {HTMLInputElement} */ (e.target).blur()} />
                <small>{b.width} × {b.height} px · {formatSize(b.sizeBytes)}</small>
                <div className="bg-card-actions">
                  <label className="check">
                    <input type="checkbox" className="switch" checked={b.active} disabled={action.busy}
                      onChange={(e) => void update(b, { active: e.target.checked }, e.target.checked ? tr('Image activée.') : tr('Image désactivée.'))} />
                    {tr('Active')}
                  </label>
                  <button type="button" className="danger" disabled={action.busy} onClick={async () => {
                    if (!window.confirm(tr('Supprimer l’image « {nom} » ?', { nom: b.name }))) return;
                    if (await action.run(() => request('DELETE', `/admin/backgrounds/${b.id}`), tr('Image supprimée.'))) api.reload();
                  }}>{tr('Supprimer')}</button>
                </div>
              </div>
            </li>
          ))}
        </ul>
      ) : (!api.loading ? <p className="empty">{tr('Aucune image de fond : le site garde son fond uni.')}</p> : null)}
    </section>
  );
}
