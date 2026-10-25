// @ts-check
// Suppression des comptes temporaires et de tout ce qu'ils ont laissé : `user:delete --test` annule
// leurs corrections encore actives (si un essai s'est arrêté en route), puis efface audit et corrections.
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

/** Commande d'administration du dépôt (lancée en www-data, comme sur le serveur). */
const MUSIQUE = fileURLToPath(new URL('../../server/bin/rengaine', import.meta.url));

export default function teardown() {
  for (const email of [process.env.E2E_EMAIL, process.env.E2E_ADMIN_EMAIL, process.env.E2E_REQUEST_EMAIL, process.env.E2E_RESET_EMAIL, process.env.E2E_DELETE_EMAIL]) {
    if (!email || !/^e2e-[0-9a-f]{8}@dev-musique\.test$/.test(email)) continue;
    const r = spawnSync('sudo', ['-u', 'www-data', 'php', MUSIQUE, 'user:delete', email, '--test'], { encoding: 'utf8' });
    if (r.status !== 0 && !/introuvable/.test(r.stderr + r.stdout)) console.error(`Compte de test ${email} non supprimé :`, r.stderr || r.stdout);
  }
}
