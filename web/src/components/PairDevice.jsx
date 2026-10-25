// @ts-check
// Appairer l'appli (WEB-14) : le site crée un code (POST /me/pairing-codes) et l'affiche en QR,
// avec le code en clair et l'adresse du serveur pour la saisie à la main. Le code vaut 5 minutes.
// Pendant ce temps on relit /me/devices pour annoncer l'appareil dès qu'il est appairé.
import { useEffect, useMemo, useRef, useState } from 'react';
import qrcode from 'qrcode-generator';
import { ApiError, request } from '../api/client.js';
import { tr } from '../i18n/index.js';

const POLL_MS = 4000;

/** Chemin SVG des modules sombres d'un QR, avec une marge de 4 modules (norme). */
export function qrPath(/** @type {string} */ text) {
  const qr = qrcode(0, 'M');
  qr.addData(text);
  qr.make();
  const n = qr.getModuleCount();
  let d = '';
  for (let r = 0; r < n; r++) {
    for (let c = 0; c < n; c++) if (qr.isDark(r, c)) d += `M${c + 4} ${r + 4}h1v1h-1z`;
  }
  return { d, size: n + 8 };
}

/** Adresse du serveur lue dans le QR (`rengaine://pair?server=…`), sinon celle du site. */
export function serverFromPayload(/** @type {string} */ payload) {
  try {
    const s = new URL(payload).searchParams.get('server');
    if (s) return s;
  } catch { /* adresse illisible : repli */ }
  return window.location.origin;
}

/** « 4:59 » */
export function formatLeft(/** @type {number} */ ms) {
  const s = Math.max(0, Math.ceil(ms / 1000));
  return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
}

/** @param {{ onPaired?: () => void }} props */
export function PairDevice({ onPaired }) {
  const [pairing, setPairing] = useState(/** @type {{ code: string, qrPayload: string, end: number } | null} */ (null));
  const [now, setNow] = useState(Date.now());
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');
  const known = useRef(/** @type {Set<number> | null} */ (null));
  const paired = useRef(onPaired);
  paired.current = onPaired;

  const start = async () => {
    setBusy(true);
    setMessage('');
    try {
      const before = /** @type {{ items: { id: number }[] }} */ ((await request('GET', '/me/devices')).data);
      known.current = new Set(before.items.map((d) => d.id));
      const r = /** @type {{ code: string, qrPayload: string, expiresAt: string }} */ ((await request('POST', '/me/pairing-codes')).data);
      // L'horloge du navigateur peut différer de celle du serveur : au pire on compte 5 minutes.
      let end = Date.parse(r.expiresAt);
      const left = end - Date.now();
      if (!(left > 0 && left <= 6 * 60_000)) end = Date.now() + 5 * 60_000;
      setNow(Date.now());
      setPairing({ code: r.code, qrPayload: r.qrPayload, end });
    } catch (e) {
      setMessage(e instanceof ApiError && e.status === 429 ? tr('Trop de codes demandés : réessayez dans une heure.') : tr('Impossible de créer un code pour l’instant, réessayez.'));
    } finally {
      setBusy(false);
    }
  };

  const expired = pairing !== null && now >= pairing.end;

  useEffect(() => {
    if (!pairing || expired) return undefined;
    const tick = setInterval(() => setNow(Date.now()), 1000);
    const poll = setInterval(async () => {
      try {
        const list = /** @type {{ items: { id: number, name: string, kind: string }[] }} */ ((await request('GET', '/me/devices')).data);
        const added = list.items.find((d) => d.kind === 'app' && !known.current?.has(d.id));
        if (added) {
          setPairing(null);
          setMessage(tr('« {nom} » est appairé.', { nom: added.name }));
          paired.current?.();
        }
      } catch { /* on réessaiera au tour suivant */ }
    }, POLL_MS);
    return () => {
      clearInterval(tick);
      clearInterval(poll);
    };
  }, [pairing, expired]);

  const card = useRef(/** @type {HTMLDivElement | null} */ (null));
  const shown = pairing !== null;
  // Sur téléphone la carte s'ouvre sous le pli : on l'amène à l'écran
  useEffect(() => { if (shown) card.current?.scrollIntoView?.({ block: 'nearest', behavior: 'smooth' }); }, [shown]);

  const qr = useMemo(() => (pairing ? qrPath(pairing.qrPayload) : null), [pairing]);

  return (
    <div className="pair-device">
      {!pairing ? (
        <>
          <p className="hint">{tr('Pour connecter l’appli Android : scannez un QR code depuis l’appli, ou tapez le code à la main.')}</p>
          <button type="button" disabled={busy} onClick={() => void start()}>{tr('Appairer un appareil')}</button>
        </>
      ) : (
        <div className="pair-card" ref={card}>
          {expired ? (
            <p>{tr('Ce code a expiré.')}</p>
          ) : (
            <>
              <svg className="pair-qr" role="img" aria-label={tr('QR code d’appairage')} viewBox={`0 0 ${qr?.size} ${qr?.size}`} shapeRendering="crispEdges">
                <rect width="100%" height="100%" fill="#fff" />
                <path d={qr?.d} fill="#000" />
              </svg>
              <dl className="pair-info">
                <dt>{tr('Code')}</dt>
                <dd className="pair-code">{pairing.code}</dd>
                <dt>{tr('Serveur')}</dt>
                <dd className="pair-server">{serverFromPayload(pairing.qrPayload)}</dd>
                <dt>{tr('Valable encore')}</dt>
                <dd><time>{formatLeft(pairing.end - now)}</time></dd>
              </dl>
            </>
          )}
          <div className="pair-actions">
            {expired ? <button type="button" disabled={busy} onClick={() => void start()}>{tr('Nouveau code')}</button> : null}
            <button type="button" className="link-btn" onClick={() => setPairing(null)}>{tr('Fermer')}</button>
          </div>
        </div>
      )}
      <p className="hint" aria-live="polite">{message}</p>
    </div>
  );
}
