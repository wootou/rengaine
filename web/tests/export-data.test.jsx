// @ts-check
// Export des données (CPT-10) : GET /me/export, puis un fichier rengaine-export-AAAAMMJJ.json.
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ExportData, exportFileName } from '../src/components/ExportData.jsx';

/** @type {string[]} */
let urls;
let status = 200;
beforeEach(() => {
  urls = [];
  status = 200;
  vi.stubGlobal('fetch', vi.fn(async (/** @type {string} */ url) => {
    urls.push(new URL(url, 'http://localhost').pathname);
    return status === 200
      ? new Response(JSON.stringify({ format: 'rengaine-export', formatVersion: 1 }), { status: 200, headers: { 'Content-Type': 'application/json' } })
      : new Response(JSON.stringify({ error: { status, code: 'rate_limited', message: 'Trop', requestId: 'x' } }), { status, headers: { 'Content-Type': 'application/json' } });
  }));
  URL.createObjectURL = vi.fn(() => 'blob:fichier');
  URL.revokeObjectURL = vi.fn();
});
afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('export des données', () => {
  it('nom du fichier daté', () => {
    expect(exportFileName(new Date(2026, 9, 4))).toBe('rengaine-export-20261004.json');
  });

  it('télécharge le fichier JSON du serveur', async () => {
    const clicks = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});
    render(<ExportData />);
    fireEvent.click(screen.getByRole('button', { name: 'Télécharger mes données' }));
    await waitFor(() => expect(screen.getByRole('status').textContent).toBe('Fichier téléchargé.'));
    expect(urls).toEqual(['/api/v2/me/export']);
    expect(clicks).toHaveBeenCalledTimes(1);
    const blob = /** @type {Blob} */ (/** @type {any} */ (URL.createObjectURL).mock.calls[0][0]);
    expect(JSON.parse(await blob.text())).toEqual({ format: 'rengaine-export', formatVersion: 1 });
    clicks.mockRestore();
  });

  it('trop d’exports : un message clair', async () => {
    status = 429;
    render(<ExportData />);
    fireEvent.click(screen.getByRole('button', { name: 'Télécharger mes données' }));
    await waitFor(() => expect(screen.getByRole('status').textContent).toMatch(/une heure/));
  });
});
