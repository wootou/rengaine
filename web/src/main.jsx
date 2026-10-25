// @ts-check
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { App } from './App.jsx';
import { controller } from './engine/controller.js';
import { initDiag } from './engine/diag.js';
import { get } from './api/client.js';
import { loadLanguage } from './i18n/index.js';
import './styles.css';

// Pour le débogage et les tests de bout en bout (rien que la page ne puisse déjà faire)
/** @type {any} */ (window).musique = controller;
initDiag(controller.player);

// La langue du serveur (WEB-13) est connue avant le premier affichage ; sans réponse, le site reste en français
const root = document.getElementById('root');
get('/server')
  .then((s) => loadLanguage(/** @type {import('./api/types.js').ServerInfo} */ (s).language))
  .catch(() => {})
  .finally(() => {
    if (root) createRoot(root).render(<StrictMode><App /></StrictMode>);
  });
