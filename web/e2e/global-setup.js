// @ts-check
// Comptes temporaires pour les tests (deux utilisateurs, un admin) : mots de passe aléatoires, jamais
// affichés. Supprimés par global-teardown.js, avec tout ce qu'ils ont laissé.
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { randomBytes } from 'node:crypto';

/** Commande d'administration du dépôt (lancée en www-data, comme sur le serveur). */
const MUSIQUE = fileURLToPath(new URL('../../server/bin/rengaine', import.meta.url));

/** @param {string} name @param {string} role @returns {[string, string]} */
function createAccount(name, role) {
  const email = `e2e-${randomBytes(4).toString('hex')}@dev-musique.test`;
  const password = randomBytes(18).toString('base64url');
  const r = spawnSync('sudo', ['-u', 'www-data', 'php', MUSIQUE, 'user:create', email, name, '--role', role],
    { input: password + '\n', encoding: 'utf8' });
  if (r.status !== 0) throw new Error(`Compte de test impossible à créer : ${r.stderr || r.stdout}`);
  return [email, password];
}

export default function setup() {
  // Cache des conversions vidé : l'essai de conversion passe par une conversion en direct
  spawnSync('sudo', ['-u', 'www-data', 'php', MUSIQUE, 'conversions', '--vider'], { encoding: 'utf8' });
  [process.env.E2E_EMAIL, process.env.E2E_PASSWORD] = createAccount('Essai navigateur', 'user');
  [process.env.E2E_ADMIN_EMAIL, process.env.E2E_ADMIN_PASSWORD] = createAccount('Essai admin', 'admin');
  // Compte dont l'essai « mot de passe oublié » change le mot de passe (les autres essais gardent le leur)
  [process.env.E2E_RESET_EMAIL] = createAccount('Essai oubli', 'user');
  // Compte que l'essai « supprimer mon compte » supprime vraiment (CPT-09)
  [process.env.E2E_DELETE_EMAIL, process.env.E2E_DELETE_PASSWORD] = createAccount('Essai suppression', 'user');
  // Adresse de la demande de compte faite pendant les essais (supprimée aussi à la fin)
  process.env.E2E_REQUEST_EMAIL = `e2e-${randomBytes(4).toString('hex')}@dev-musique.test`;
}
