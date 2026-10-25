// @ts-check
// Petit état observable, branché sur React par useSyncExternalStore.

/** @template T */
export class Store {
  /** @param {T} initial */
  constructor(initial) {
    /** @type {T} */
    this.state = initial;
    /** @type {Set<() => void>} */
    this.listeners = new Set();
    this.subscribe = this.subscribe.bind(this);
    this.getState = this.getState.bind(this);
  }

  getState() {
    return this.state;
  }

  /** @param {() => void} fn */
  subscribe(fn) {
    this.listeners.add(fn);
    return () => {
      this.listeners.delete(fn);
    };
  }

  /** @param {Partial<T>} patch */
  set(patch) {
    this.state = { ...this.state, ...patch };
    this.listeners.forEach((fn) => fn());
  }
}
