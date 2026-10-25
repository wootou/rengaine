// @ts-check
// « Mot de passe oublié » de bout en bout : le mail part vraiment (vers MailCatcher, le faux serveur
// SMTP du CT de dev, qui n'envoie jamais rien dehors), on suit le lien, on change le mot de passe.
import { expect, test } from '@playwright/test';

const MAILCATCHER = 'http://127.0.0.1:1080';

/**
 * Attend le mail adressé à `to` dont le sujet contient `subject`, et rend son texte.
 * @param {string} to @param {string} subject
 */
async function waitMail(to, subject) {
  for (let i = 0; i < 40; i++) {
    const list = /** @type {{ id: number, recipients: string[], subject: string }[]} */ (await (await fetch(`${MAILCATCHER}/messages`)).json());
    const m = list.find((x) => x.recipients.includes(`<${to}>`) && x.subject.includes(subject));
    if (m) {
      const text = await (await fetch(`${MAILCATCHER}/messages/${m.id}.plain`)).text();
      await fetch(`${MAILCATCHER}/messages/${m.id}`, { method: 'DELETE' }); // lu : on ne le laisse pas traîner
      return text;
    }
    await new Promise((r) => setTimeout(r, 250));
  }
  throw new Error(`Aucun mail « ${subject} » pour ${to}`);
}

test('mot de passe oublié : lien reçu par mail, nouveau mot de passe, connexion', async ({ page }) => {
  const email = String(process.env.E2E_RESET_EMAIL);
  await page.goto('/');
  await page.getByRole('button', { name: 'Mot de passe oublié ?' }).click();
  await page.getByLabel('Adresse e-mail').fill(email);
  await page.getByRole('button', { name: 'Recevoir le lien' }).click();
  await expect(page.getByRole('status')).toContainText('Si un compte existe');

  const text = await waitMail(email, 'Nouveau mot de passe');
  const link = text.match(/https?:\/\/[^/\s]+(\/mot-de-passe#jeton=[A-Za-z0-9_-]+)/);
  expect(link, 'lien dans le mail').not.toBeNull();
  await page.goto(String(link?.[1]));
  await expect(page).toHaveURL(/\/mot-de-passe$/); // jeton retiré de l'adresse
  const fresh = 'nouveau-' + Math.random().toString(36).slice(2) + '-mdp';
  await page.getByLabel('Nouveau mot de passe').fill(fresh);
  await page.getByLabel('Encore une fois').fill(fresh);
  await page.getByRole('button', { name: 'Changer le mot de passe' }).click();
  await expect(page.getByRole('status')).toContainText('Mot de passe changé');
  await waitMail(email, 'Mot de passe changé');

  // Le lien ne sert qu'une fois
  await page.goto(String(link?.[1]));
  await page.getByLabel('Nouveau mot de passe').fill(fresh + 'x');
  await page.getByLabel('Encore une fois').fill(fresh + 'x');
  await page.getByRole('button', { name: 'Changer le mot de passe' }).click();
  await expect(page.getByRole('alert')).toContainText('a expiré ou a déjà servi');

  await page.getByRole('link', { name: 'Page de connexion' }).click();
  await page.getByLabel('Adresse e-mail').fill(email);
  await page.getByLabel('Mot de passe').fill(fresh);
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await expect(page.getByRole('heading', { name: /Albums/ })).toBeVisible();
});
