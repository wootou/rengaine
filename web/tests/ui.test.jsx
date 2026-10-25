// @ts-check
import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { Profiler } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { TrackList } from '../src/components/TrackList.jsx';
import { controller } from '../src/engine/controller.js';
import { session } from '../src/engine/session.js';
import { Login } from '../src/pages/Login.jsx';
import { ResetPassword } from '../src/pages/ResetPassword.jsx';
import { track } from './fixtures.js';

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

describe('connexion', () => {
  it('affiche le message correspondant au code d’erreur', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ error: { status: 403, code: 'account_pending', message: 'x', requestId: 'R' } }), { status: 403 })));
    render(<Login />);
    fireEvent.change(screen.getByLabelText('Adresse e-mail'), { target: { value: 'a@b.fr' } });
    fireEvent.change(screen.getByLabelText('Mot de passe'), { target: { value: 'secret' } });
    fireEvent.click(screen.getByRole('button', { name: 'Se connecter' }));
    await waitFor(() => expect(screen.getByRole('alert').textContent).toContain('attend l’approbation'));
  });

  it('demande de compte : formulaire, puis confirmation', async () => {
    const f = vi.fn(async () => new Response(JSON.stringify({ message: 'ok' }), { status: 202 }));
    vi.stubGlobal('fetch', f);
    render(<Login />);
    fireEvent.click(screen.getByRole('button', { name: /Faire une demande/ }));
    fireEvent.change(screen.getByLabelText('Adresse e-mail'), { target: { value: 'a@b.fr' } });
    fireEvent.change(screen.getByLabelText('Votre nom'), { target: { value: 'Alice' } });
    fireEvent.change(screen.getByLabelText('Mot de passe'), { target: { value: 'un-long-mot-de-passe' } });
    fireEvent.click(screen.getByRole('button', { name: 'Demander un compte' }));
    await waitFor(() => expect(screen.getByText(/Demande envoyée/)).toBeTruthy());
    expect(JSON.parse(String(/** @type {any} */ (f.mock.calls[0])[1].body))).toMatchObject({ email: 'a@b.fr', displayName: 'Alice' });
  });
});

describe('mot de passe oublié', () => {
  /** @param {boolean} passwordReset */
  const withServer = (passwordReset) => session.set({
    server: /** @type {any} */ ({ name: 'Rengaine', registrationOpen: true, features: { passwordReset } }),
  });

  it('le lien n’apparaît que si le serveur envoie des mails', () => {
    withServer(false);
    render(<Login />);
    expect(screen.queryByRole('button', { name: 'Mot de passe oublié ?' })).toBeNull();
    cleanup();
    withServer(true);
    render(<Login />);
    expect(screen.getByRole('button', { name: 'Mot de passe oublié ?' })).toBeTruthy();
  });

  it('demande du lien : seule l’adresse part, la réponse ne dit pas si le compte existe', async () => {
    withServer(true);
    const f = vi.fn(async () => new Response(JSON.stringify({ message: 'ok' }), { status: 202 }));
    vi.stubGlobal('fetch', f);
    render(<Login />);
    fireEvent.click(screen.getByRole('button', { name: 'Mot de passe oublié ?' }));
    fireEvent.change(screen.getByLabelText('Adresse e-mail'), { target: { value: ' a@b.fr ' } });
    fireEvent.click(screen.getByRole('button', { name: 'Recevoir le lien' }));
    await waitFor(() => expect(screen.getByRole('status').textContent).toContain('Si un compte existe'));
    const [url, init] = /** @type {any} */ (f.mock.calls[0]);
    expect([String(url).endsWith('/auth/password/forgot'), JSON.parse(init.body)]).toEqual([true, { email: 'a@b.fr' }]);
  });

  it('page du lien : jeton retiré de l’adresse, mots de passe comparés, puis envoyé', async () => {
    window.history.replaceState(null, '', '/mot-de-passe#jeton=abc123');
    const f = vi.fn(async () => new Response(null, { status: 204 }));
    vi.stubGlobal('fetch', f);
    render(<ResetPassword />);
    expect(window.location.search).toBe('');
    expect(window.location.hash).toBe('');
    fireEvent.change(screen.getByLabelText('Nouveau mot de passe'), { target: { value: 'un-mot-de-passe-neuf' } });
    fireEvent.change(screen.getByLabelText('Encore une fois'), { target: { value: 'un-mot-de-passe-nef' } });
    fireEvent.click(screen.getByRole('button', { name: 'Changer le mot de passe' }));
    expect(screen.getByRole('alert').textContent).toContain('pas identiques');
    expect(f).not.toHaveBeenCalled();
    fireEvent.change(screen.getByLabelText('Encore une fois'), { target: { value: 'un-mot-de-passe-neuf' } });
    fireEvent.click(screen.getByRole('button', { name: 'Changer le mot de passe' }));
    await waitFor(() => expect(screen.getByRole('status').textContent).toContain('Mot de passe changé'));
    expect(JSON.parse(String(/** @type {any} */ (f.mock.calls[0])[1].body))).toEqual({ token: 'abc123', newPassword: 'un-mot-de-passe-neuf' });
  });

  it('lien expiré : message clair', async () => {
    window.history.replaceState(null, '', '/mot-de-passe#jeton=vieux');
    vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ error: { status: 400, code: 'invalid_token', message: 'x', requestId: 'R' } }), { status: 400 })));
    render(<ResetPassword />);
    fireEvent.change(screen.getByLabelText('Nouveau mot de passe'), { target: { value: 'un-mot-de-passe-neuf' } });
    fireEvent.change(screen.getByLabelText('Encore une fois'), { target: { value: 'un-mot-de-passe-neuf' } });
    fireEvent.click(screen.getByRole('button', { name: 'Changer le mot de passe' }));
    await waitFor(() => expect(screen.getByRole('alert').textContent).toContain('a expiré ou a déjà servi'));
  });
});

describe('liste de pistes', () => {
  it('un clic écoute la liste à partir de la piste ; « ajouter » ne coupe pas la lecture', () => {
    const play = vi.spyOn(controller, 'playTracks').mockImplementation(() => {});
    const enqueue = vi.spyOn(controller, 'enqueue').mockImplementation(() => {});
    const tracks = [track(1), track(2, { title: 'Deuxième' }), track(3, { available: false })];
    render(<TrackList tracks={tracks} />);
    fireEvent.click(screen.getByText('Deuxième'));
    expect(play).toHaveBeenCalledWith(tracks, 1);
    fireEvent.click(screen.getAllByTitle('Ajouter à la file')[0]);
    expect(enqueue).toHaveBeenCalledWith([tracks[0]]);
    expect(play).toHaveBeenCalledTimes(1);
    fireEvent.click(screen.getByText('Piste 3'));
    expect(play).toHaveBeenCalledTimes(1); // indisponible : rien
  });

  it('plusieurs disques : un titre par disque', () => {
    render(<TrackList tracks={[track(1, { discNumber: 1 }), track(2, { discNumber: 2, trackNumber: 1 })]} />);
    expect(screen.getByText('Disque 1')).toBeTruthy();
    expect(screen.getByText('Disque 2')).toBeTruthy();
  });
});

describe('performance de la liste des pistes', () => {
  it('ne se redessine pas quand seule la position de lecture avance (4 fois par seconde)', () => {
    let renders = 0;
    render(<Profiler id="liste" onRender={() => { renders += 1; }}><TrackList tracks={[track(1), track(2), track(3)]} /></Profiler>);
    const before = renders;
    act(() => {
      for (let i = 1; i <= 10; i++) controller.store.set({ positionMs: i * 250 });
    });
    expect(renders).toBe(before);
    // La piste en cours change : la liste suit
    act(() => controller.store.set({ playing: !controller.store.getState().playing }));
    expect(renders).toBe(before + 1);
  });
});
