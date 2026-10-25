// @ts-check
// Tests de bout en bout dans Chromium sans affichage, sur un vrai site (serveur, base et bibliothèque
// d'essai), avec des comptes temporaires créés puis supprimés (e2e/global-setup.js).
//   E2E_BASE_URL    adresse du site essayé (défaut : http://127.0.0.1)
//   E2E_HOST_RULES  facultatif : règles de résolution de Chromium (ex. passer par le proxy TLS)
// Les valeurs propres à une machine vont dans web/.env.e2e.local (non suivi par git), lu ici.
import { existsSync, readFileSync } from 'node:fs';
import { defineConfig, devices } from '@playwright/test';

const local = new URL('./.env.e2e.local', import.meta.url);
if (existsSync(local)) {
  for (const line of readFileSync(local, 'utf8').split('\n')) {
    const m = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*?)\s*$/);
    if (m && process.env[m[1]] === undefined) process.env[m[1]] = m[2].replace(/^["']|["']$/g, '');
  }
}
const hostRules = process.env.E2E_HOST_RULES ? [`--host-resolver-rules=${process.env.E2E_HOST_RULES}`] : [];

export default defineConfig({
  testDir: 'e2e',
  timeout: 60_000,
  workers: 1,
  globalSetup: './e2e/global-setup.js',
  globalTeardown: './e2e/global-teardown.js',
  reporter: [['list']],
  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'http://127.0.0.1',
    locale: 'fr-FR',
    launchOptions: {
      args: [...hostRules, '--autoplay-policy=no-user-gesture-required'],
    },
  },
  projects: [
    { name: 'ordinateur', use: { ...devices['Desktop Chrome'] } },
    { name: 'téléphone', use: { ...devices['Pixel 7'] }, grep: /@téléphone/ },
  ],
});
