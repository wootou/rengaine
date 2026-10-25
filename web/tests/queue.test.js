// @ts-check
import { describe, expect, it } from 'vitest';
import * as Q from '../src/engine/queue.js';
import { track } from './fixtures.js';

const ids = (/** @type {Q.Queue} */ q) => q.items.map((i) => i.track.id);
const cur = (/** @type {Q.Queue} */ q) => Q.currentItem(q)?.track.id ?? null;

describe('file d’attente', () => {
  it('écoute un album à partir d’une piste', () => {
    const q = Q.replace(Q.emptyQueue(), [track(1), track(2), track(3)], 1);
    expect(ids(q)).toEqual([1, 2, 3]);
    expect(cur(q)).toBe(2);
    expect(Q.remaining(q)).toBe(1);
  });

  it('ajouter à la file ne change pas la piste en cours', () => {
    let q = Q.replace(Q.emptyQueue(), [track(1), track(2)], 0);
    q = Q.append(q, [track(9)]);
    expect(ids(q)).toEqual([1, 2, 9]);
    expect(cur(q)).toBe(1);
  });

  it('« lire ensuite » insère juste après la piste en cours', () => {
    let q = Q.replace(Q.emptyQueue(), [track(1), track(2), track(3)], 1);
    q = Q.playNext(q, [track(8), track(9)]);
    expect(ids(q)).toEqual([1, 2, 8, 9, 3]);
    expect(Q.nextItem(q)?.track.id).toBe(8);
  });

  it('la piste en cours suit son élément quand on réordonne (pas sa position)', () => {
    let q = Q.replace(Q.emptyQueue(), [track(1), track(2), track(3), track(4)], 1);
    q = Q.move(q, 1, 3);
    expect(ids(q)).toEqual([1, 3, 4, 2]);
    expect(cur(q)).toBe(2);
    expect(Q.currentIndex(q)).toBe(3);
    q = Q.move(q, 0, 3);
    expect(cur(q)).toBe(2);
  });

  it('la même piste deux fois : chaque élément garde sa propre clé', () => {
    let q = Q.replace(Q.emptyQueue(), [track(1), track(2), track(1)], 2);
    expect(Q.currentIndex(q)).toBe(2);
    q = Q.remove(q, q.items[0].key);
    expect(Q.currentIndex(q)).toBe(1);
    expect(ids(q)).toEqual([2, 1]);
  });

  it('retirer la piste en cours fait passer à la suivante', () => {
    let q = Q.replace(Q.emptyQueue(), [track(1), track(2), track(3)], 1);
    q = Q.remove(q, q.items[1].key);
    expect(cur(q)).toBe(3);
    q = Q.remove(q, q.items[1].key);
    expect(cur(q)).toBe(1);
  });

  it('modes de répétition', () => {
    let q = Q.replace(Q.emptyQueue(), [track(1), track(2)], 1);
    expect(Q.nextItem(q)).toBeNull();
    q = Q.setMode(q, 'repeat_all');
    expect(Q.nextItem(q)?.track.id).toBe(1);
    q = Q.setMode(q, 'repeat_one');
    expect(Q.nextItem(q)?.track.id).toBe(2);
    expect(Q.nextItem(q, { manual: true })?.track.id).toBe(1);
    expect(Q.previousItem(Q.setMode(Q.jumpTo(q, q.items[0].key), 'repeat_all'))?.track.id).toBe(2);
  });

  it('vider la suite garde la piste en cours', () => {
    const q = Q.clearUpcoming(Q.replace(Q.emptyQueue(), [track(1), track(2), track(3)], 1));
    expect(ids(q)).toEqual([2]);
    expect(cur(q)).toBe(2);
  });

  it('aller-retour avec le serveur', () => {
    const q = Q.replace(Q.emptyQueue(), [track(1), track(2), track(1)], 2);
    const sent = Q.toServer(Q.setMode(q, 'repeat_all'), 1234.6);
    expect(sent).toEqual({ trackIds: [1, 2, 1], currentIndex: 2, positionMs: 1235, mode: 'repeat_all' });
    const back = Q.fromServer({ ...sent, version: 3, updatedAt: '2026-10-19T08:00:00.000Z', tracks: [track(1), track(2), track(1)] });
    expect(ids(back)).toEqual([1, 2, 1]);
    expect(Q.currentIndex(back)).toBe(2);
    expect(back.mode).toBe('repeat_all');
  });

  it('file vide', () => {
    const q = Q.emptyQueue();
    expect(Q.currentItem(q)).toBeNull();
    expect(Q.nextItem(q)).toBeNull();
    expect(Q.toServer(q, 0).currentIndex).toBeNull();
    expect(Q.append(q, [track(5)]).currentKey).not.toBeNull();
  });
});
