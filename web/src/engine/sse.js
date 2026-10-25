// @ts-check
// Abonnement au hub temps réel (Mercure, FUT-02) : flux SSE lu par fetch(), pour envoyer le jeton
// dans l'en-tête Authorization (EventSource ne le permet pas, et le jeton n'a rien à faire dans
// l'adresse). Le hub ferme le flux à l'expiration du jeton (15 min) : on redemande alors un canal
// (nouveau jeton) et on se reconnecte, en prévenant l'appelant (onOpen) pour qu'il relise l'état,
// des messages ayant pu être manqués entre-temps.

/** @typedef {import('../api/types.js').RealtimeChannel} RealtimeChannel */
/** @typedef {import('../api/types.js').RealtimeEvent} RealtimeEvent */

/**
 * Découpe un morceau de flux SSE en messages (`data` de chaque événement), et rend ce qui reste.
 * @param {string} buffer
 * @returns {{ messages: string[], rest: string }}
 */
export function parseSse(buffer) {
  const text = buffer.replace(/\r\n?/g, '\n');
  const blocks = text.split('\n\n');
  const rest = blocks.pop() ?? '';
  /** @type {string[]} */
  const messages = [];
  for (const block of blocks) {
    const data = block.split('\n').filter((l) => l.startsWith('data:')).map((l) => l.slice(l.startsWith('data: ') ? 6 : 5));
    if (data.length > 0) messages.push(data.join('\n'));
  }
  return { messages, rest };
}

const RETRY_MIN_MS = 1000;
const RETRY_MAX_MS = 30000;

export class Subscription {
  /**
   * @param {() => Promise<RealtimeChannel>} getChannel nouveau canal (jeton frais) à chaque connexion
   * @param {(e: RealtimeEvent) => void} onMessage
   * @param {{ onOpen?: () => void, onFatal?: (e: unknown) => boolean }} [o]
   *   onFatal : erreur en demandant le canal ; true = abandonner (lien révoqué…), false = réessayer
   */
  constructor(getChannel, onMessage, o = {}) {
    this.getChannel = getChannel;
    this.onMessage = onMessage;
    this.onOpen = o.onOpen ?? (() => {});
    this.onFatal = o.onFatal ?? (() => false);
    /** @type {AbortController | null} */
    this.abort = null;
    this.stopped = true;
    this.retryMs = RETRY_MIN_MS;
    /** @type {ReturnType<typeof setTimeout> | null} */
    this.retryTimer = null;
  }

  start() {
    if (!this.stopped) return;
    this.stopped = false;
    void this.loop();
  }

  stop() {
    this.stopped = true;
    this.abort?.abort();
    this.abort = null;
    if (this.retryTimer) clearTimeout(this.retryTimer);
    this.retryTimer = null;
  }

  /** Coupe la connexion en cours : la suivante prend un canal frais (droits ou liens changés). */
  reconnect() {
    this.abort?.abort();
  }

  async loop() {
    while (!this.stopped) {
      const ac = new AbortController();
      this.abort = ac;
      let opened = false;
      try {
        let channel;
        try {
          channel = await this.getChannel();
        } catch (e) {
          if (this.onFatal(e)) {
            this.stop();
            return;
          }
          throw e;
        }
        if (this.stopped) return;
        const url = new URL(channel.hubUrl, window.location.href);
        for (const t of channel.topics) url.searchParams.append('match', t);
        const res = await fetch(url, { headers: { Authorization: `Bearer ${channel.token}`, Accept: 'text/event-stream' }, signal: ac.signal, cache: 'no-store' });
        if (!res.ok || !res.body) throw new Error(`hub : ${res.status}`);
        opened = true;
        this.retryMs = RETRY_MIN_MS;
        this.onOpen();
        const reader = res.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';
        for (;;) {
          const { done, value } = await reader.read();
          if (done) break;
          buffer += decoder.decode(value, { stream: true });
          const { messages, rest } = parseSse(buffer);
          buffer = rest;
          for (const m of messages) {
            try {
              this.onMessage(JSON.parse(m));
            } catch (e) {
              console.warn('Temps réel : message illisible', e);
            }
          }
        }
      } catch (e) {
        if (this.stopped) return;
        if (!(e instanceof DOMException && e.name === 'AbortError')) console.warn('Temps réel :', e);
      }
      if (this.stopped) return;
      // Fin normale (jeton expiré) : on repart aussitôt ; échec : on attend de plus en plus
      const wait = opened ? 0 : this.retryMs;
      if (!opened) this.retryMs = Math.min(RETRY_MAX_MS, this.retryMs * 2);
      if (wait > 0) {
        await new Promise((resolve) => {
          this.retryTimer = setTimeout(resolve, wait);
        });
      }
    }
  }
}
