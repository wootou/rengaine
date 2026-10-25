// @ts-check
// Le lecteur est servi sous VITE_BASE (« / » par défaut ; « /nouveau/ » tant que l'ancien site
// occupe la racine du domaine). En développement, /api est relayé vers l'API locale.
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  base: process.env.VITE_BASE ?? '/',
  plugins: [react()],
  server: {
    host: '127.0.0.1',
    port: 5173,
    proxy: {
      // VITE_API_HOST : nom du site servi par Apache en local (vhost), si ce n'est pas l'hôte par défaut
      '/api': { target: 'http://127.0.0.1', changeOrigin: false, headers: process.env.VITE_API_HOST ? { Host: process.env.VITE_API_HOST } : {} },
    },
  },
  build: { outDir: 'dist', sourcemap: true },
  test: { environment: 'jsdom', include: ['tests/**/*.test.{js,jsx}'] },
});
