// Site de présentation de Rengaine (rengaine.org) : pages statiques, construites depuis le dépôt.
//   - français à la racine, anglais sous /en/ ; chaque page a son équivalent dans l'autre langue ;
//   - pages de présentation : website/pages/<langue>/<clé>.html (fragments HTML) ;
//   - documentation : docs/fr/*.md et docs/*.md (Markdown → HTML) ;
//   - doc du contrat de l'API (api/site/, produite par api/doc.sh), sous /api/ ;
//   - captures : website/images/ (produites par website/captures.mjs sur la bibliothèque fictive).
// Aucun script, aucune police ni ressource externe ; sombre ou clair selon le système.
//
//   [REPO_URL=https://github.com/<compte>/rengaine] node build.mjs     → dist/
import { cpSync, existsSync, mkdirSync, readFileSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { marked } from 'marked';

const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..');

/**
 * « ?v=<empreinte> » du fichier (chemin relatif à website/) : une image ou une feuille modifiée change
 * d'adresse, et le cache des navigateurs (images gardées un jour) ne montre pas l'ancienne version.
 * @param {string} p
 */
function version(p) {
  const file = join(here, p);
  return existsSync(file) ? `?v=${createHash('sha256').update(readFileSync(file)).digest('hex').slice(0, 10)}` : '';
}
const out = join(here, 'dist');
const SITE = 'https://rengaine.org';
// Dépôt public : tant qu'il n'existe pas, les liens vers GitHub ne sont pas affichés
const REPO = process.env.REPO_URL ? process.env.REPO_URL.replace(/\/$/, '') : null;

/** @typedef {'fr' | 'en'} Lang */
const LANGS = /** @type {Lang[]} */ (['fr', 'en']);
const PREFIX = { fr: '', en: 'en/' };

// Pages de présentation : clé → chemin et titre par langue
const PAGES = /** @type {const} */ ({
  home: { fr: ['', 'Rengaine — votre musique, chez vous'], en: ['', 'Rengaine — your music library, self-hosted'] },
  features: { fr: ['fonctionnalites/', 'Fonctionnalités'], en: ['features/', 'Features'] },
  app: { fr: ['appli/', 'L’appli Android'], en: ['app/', 'The Android app'] },
  screenshots: { fr: ['captures/', 'Captures'], en: ['screenshots/', 'Screenshots'] },
  download: { fr: ['telecharger/', 'Télécharger'], en: ['download/', 'Download'] },
  legal: { fr: ['mentions-legales/', 'Mentions légales'], en: ['legal/', 'Legal notice'] },
  licenses: { fr: ['licences/', 'Licences open source'], en: ['licenses/', 'Open source licenses'] },
});
const NAV = /** @type {const} */ (['features', 'app', 'screenshots', 'docs', 'download']);

// Documentation : même nom de fichier dans les deux langues, dans l'ordre du menu
const DOCS = [
  ['installation', 'Installation', 'Installation'], ['configuration', 'Configuration', 'Configuration'],
  ['library', 'Votre bibliothèque', 'Your music library'], ['usage', 'Utiliser Rengaine', 'Using Rengaine'],
  ['administration', 'Administration', 'Administration'], ['ai', 'L’IA et la bibliothèque', 'AI and the library'],
  ['upgrading', 'Mise à jour, sauvegardes', 'Upgrading, backups'], ['architecture', 'Architecture', 'Architecture'],
  ['api', 'API', 'API'], ['development', 'Développement', 'Development'],
];

const T = {
  fr: { skip: 'Aller au contenu', nav: 'Navigation principale', docs: 'Documentation', other: 'English', otherLang: 'en',
    footer: 'Rengaine, logiciel libre sous licence MIT', legal: 'Mentions légales', licenses: 'Licences open source', menu: 'Sommaire de la documentation' },
  en: { skip: 'Skip to content', nav: 'Main navigation', docs: 'Documentation', other: 'Français', otherLang: 'fr',
    footer: 'Rengaine, free software under the MIT license', legal: 'Legal notice', licenses: 'Open source licenses', menu: 'Documentation contents' },
};

const LOGO = `<svg class="logo" width="32" height="32" viewBox="0 0 32 32" aria-hidden="true"><rect width="32" height="32" rx="9" fill="var(--accent)"/><path d="M24.08 13.06A8.6 8.6 0 1 1 14.51 7.53" fill="none" stroke="var(--on-accent)" stroke-width="2.6" stroke-linecap="round"/><path d="M19.04 6.73L13.88 3.99l1.25 7.09z" fill="var(--on-accent)"/><circle cx="16" cy="16" r="2.4" fill="var(--on-accent)"/></svg>`;

const esc = (/** @type {string} */ s) => s.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c] ?? c);

/** Chemin (depuis la racine du site) d'une page ou d'une doc. @param {Lang} lang @param {string} key */
function pathOf(lang, key) {
  if (key === 'docs') return `${PREFIX[lang]}docs/`;
  if (key.startsWith('doc:')) return `${PREFIX[lang]}docs/${key.slice(4)}.html`;
  return PREFIX[lang] + PAGES[/** @type {keyof typeof PAGES} */ (key)][lang][0];
}

/** Lien relatif depuis le fichier `from` (chemin d'un dossier ou d'un .html) vers `to`. */
function rel(/** @type {string} */ from, /** @type {string} */ to) {
  const depth = (from.endsWith('/') ? from : from.slice(0, from.lastIndexOf('/') + 1)).split('/').filter(Boolean).length;
  return ('../'.repeat(depth) + to) || './';
}

const sitemap = /** @type {string[]} */ ([]);

/**
 * @param {{ lang: Lang, key: string, title: string, body: string, description?: string }} p
 */
function render({ lang, key, title, body, description }) {
  const t = T[lang];
  const self = pathOf(lang, key);
  const to = (/** @type {string} */ k) => rel(self, pathOf(lang, k));
  const other = /** @type {Lang} */ (t.otherLang);
  const navKey = key.startsWith('doc:') ? 'docs' : key;
  const desc = description ?? (lang === 'fr'
    ? 'Rengaine, serveur de musique à héberger soi-même, avec lecteur web et appli Android.'
    : 'Rengaine, a self-hosted music server and player for the albums you own.');
  sitemap.push(SITE + '/' + self.replace(/index\.html$/, ''));
  const label = (/** @type {string} */ k) => k === 'docs' ? t.docs : PAGES[/** @type {keyof typeof PAGES} */ (k)][lang][1];
  return `<!doctype html>
<html lang="${lang}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${esc(title)}</title>
<meta name="description" content="${esc(desc)}">
<link rel="canonical" href="${SITE}/${self}">
<link rel="alternate" hreflang="${lang}" href="${SITE}/${self}">
<link rel="alternate" hreflang="${other}" href="${SITE}/${pathOf(other, key)}">
<meta property="og:title" content="${esc(title)}">
<meta property="og:description" content="${esc(desc)}">
<meta property="og:image" content="${SITE}/images/apercu.png">
<link rel="icon" href="${rel(self, 'favicon.svg')}" type="image/svg+xml">
<link rel="stylesheet" href="${rel(self, 'site.css')}">
</head>
<body>
<a class="skip" href="#main">${t.skip}</a>
<header class="top">
  <a class="brand" href="${to('home')}">${LOGO}<span>Rengaine</span></a>
  <nav aria-label="${t.nav}">
    ${NAV.map((k) => `<a href="${to(k)}"${k === navKey ? ' aria-current="page"' : ''}>${label(k)}</a>`).join('\n    ')}
    <a href="${rel(self, pathOf(other, key))}" hreflang="${other}" lang="${other}">${t.other}</a>
  </nav>
</header>
<main id="main">
${body}
</main>
<footer>
  <p>${t.footer}${REPO ? ` · <a href="${REPO}">GitHub</a>` : ''} · <a href="${to('licenses')}">${t.licenses}</a> · <a href="${to('legal')}">${t.legal}</a></p>
</footer>
</body>
</html>
`;
}

/** Liens du Markdown : autre page de doc → .html ; fichier du dépôt → GitHub (ou texte simple sans dépôt public). */
function rewriteLinks(/** @type {string} */ html, /** @type {Lang} */ lang) {
  return html.replace(/<a href="([^"]+)">([\s\S]*?)<\/a>/g, (m, href, text) => {
    if (/^(https?:|mailto:|#)/.test(href)) return m;
    const [path, hash = ''] = href.split('#');
    const name = path.replace(/^\.\//, '');
    if (/^[a-z-]+\.md$/.test(name)) {
      const base = name.slice(0, -3);
      return `<a href="${base === 'README' ? './' : `${base}.html`}${hash ? `#${hash}` : ''}">${text}</a>`;
    }
    if (/^\.\.\/[a-z-]+\.md$/.test(path) && lang === 'fr') {
      // lien de la doc française vers la version anglaise d'une page
      return `<a href="${rel('docs/x.html', `en/docs/${path.slice(3, -3)}.html`)}${hash ? `#${hash}` : ''}">${text}</a>`;
    }
    const repoPath = path.replace(/^(\.\.\/)+/, '');
    return REPO ? `<a href="${REPO}/blob/main/${repoPath}${hash ? `#${hash}` : ''}">${text}</a>` : text;
  });
}

// Coordonnées de l'éditeur pour les mentions légales : website/legal.local.json, non suivi par git (elles sont
// publiées sur le site, pas dans le dépôt). Tant qu'elles manquent, la page le dit et deploy.sh refuse de publier.
const LEGAL = existsSync(join(here, 'legal.local.json')) ? JSON.parse(readFileSync(join(here, 'legal.local.json'), 'utf8')) : {};
const MISSING = { fr: '[À COMPLÉTER]', en: '[TO BE COMPLETED]' };

// Licences open source : serveur et lecteur web (website/licenses/server.json, versions lues dans
// web/package-lock.json), appli Android (website/licenses/android.json : le aboutlibraries.json de l'appli,
// recopié à chaque version). Chaque licence est affichée une fois en bas de page ; les lignes y renvoient.
const LICENSE_NAMES = /** @type {Record<string, string>} */ ({
  'Apache-2.0': 'Apache License 2.0', 'MIT': 'MIT License', 'OFL-1.1': 'SIL Open Font License 1.1', 'BSD-3-Clause': 'BSD 3-Clause License',
});
const licenseSlug = (/** @type {string} */ id) => 'licence-' + id.toLowerCase().replace(/[^a-z0-9]+/g, '-');

function licensesHtml(/** @type {Lang} */ lang) {
  const fr = lang === 'fr';
  const dir = join(here, 'licenses');
  const server = JSON.parse(readFileSync(join(dir, 'server.json'), 'utf8'));
  const lock = JSON.parse(readFileSync(join(root, 'web', 'package-lock.json'), 'utf8'));
  /** @type {Map<string, { name: string, text: string, count: number }>} */
  const texts = new Map();
  const use = (/** @type {string} */ id, /** @type {string | undefined} */ name, /** @type {string | undefined} */ content) => {
    const t = texts.get(id) ?? { name: name ?? LICENSE_NAMES[id] ?? id, text: '', count: 0 };
    if (!t.text) {
      const file = join(dir, `${id}.txt`);
      t.text = existsSync(file) ? readFileSync(file, 'utf8') : (content ?? '');
    }
    if (!t.text) throw new Error(`Licence ${id} : texte introuvable (website/licenses/${id}.txt)`);
    t.count++;
    texts.set(id, t);
    return `<a href="#${licenseSlug(id)}">${esc(t.name)}</a>`;
  };
  /** @param {{ name: string, version?: string, website?: string, by?: string, license: string, note?: string }} l */
  const row = (l) => `<li><span class="lib-name">${l.website ? `<a href="${esc(l.website)}">${esc(l.name)}</a>` : esc(l.name)}`
    + `${l.version ? ` <span class="lib-version">${esc(l.version)}</span>` : ''}${l.note ? ` <small>(${esc(l.note)})</small>` : ''}</span>`
    + `<span class="lib-meta">${l.by ? `${esc(l.by)} · ` : ''}${l.license}</span></li>`;

  const serverRows = server.components.map((/** @type {any} */ c) => row({
    name: c.name, version: c.package ? lock.packages[`node_modules/${c.package}`]?.version : undefined, website: c.website,
    by: c.author, license: use(c.license), note: c.note?.[lang],
  }));
  const programs = server.programs.map((/** @type {any} */ p) => `<li><span class="lib-name">${p.website ? `<a href="${esc(p.website)}">${esc(p.name)}</a>` : esc(fr ? p.name : p.nameEn ?? p.name)}`
    + ` <small>(${esc(p.use[lang])})</small></span><span class="lib-meta">${esc(p.license)}</span></li>`).join('');

  let android = '';
  const appFile = join(dir, 'android.json');
  if (existsSync(appFile)) {
    const app = JSON.parse(readFileSync(appFile, 'utf8'));
    const vFile = join(dir, 'android-version.txt');
    const appVersion = existsSync(vFile) ? readFileSync(vFile, 'utf8').trim() : '';
    const libs = [...app.libraries].sort((a, b) => a.name.localeCompare(b.name, 'en', { sensitivity: 'base' }));
    const rows = libs.map((/** @type {any} */ l) => {
      const by = [...new Set((l.developers ?? []).map((/** @type {any} */ d) => d.name).filter(Boolean))].join(', ') || l.organization?.name || '';
      const lic = (l.licenses ?? []).map((/** @type {string} */ k) => {
        const def = app.licenses?.[k] ?? {};
        const id = Object.keys(LICENSE_NAMES).find((i) => LICENSE_NAMES[i] === def.name || i === k) ?? k;
        return use(id, def.name, def.content);
      }).join(', ');
      return row({ name: l.name, version: l.artifactVersion, website: l.website, by, license: lic });
    });
    android = `<h2>${fr ? 'Appli Android' : 'Android app'}</h2>
<p>${fr ? `${libs.length} bibliothèques${appVersion ? `, version ${esc(appVersion)} de l’appli` : ''}.` : `${libs.length} libraries${appVersion ? `, app version ${esc(appVersion)}` : ''}.`}</p>
<ul class="libs">${rows.join('')}</ul>`;
  }

  const licenseTexts = [...texts.entries()].map(([id, t]) => `<h3 id="${licenseSlug(id)}">${esc(t.name)}</h3>\n<pre>${esc(t.text.trim())}</pre>`).join('\n');
  return `<h2>${fr ? 'Serveur et lecteur web' : 'Server and web player'}</h2>
<ul class="libs">${serverRows.join('')}</ul>
<p>${fr ? 'Programmes séparés, appelés par Rengaine mais non inclus ; ils gardent leur propre licence :' : 'Separate programs, called by Rengaine but not included; they keep their own licenses:'}</p>
<ul class="libs">${programs}</ul>
${android}
<h2>${fr ? 'Textes des licences' : 'License texts'}</h2>
${licenseTexts}`;
}

/** Fragment HTML d'une page, avec ses repères remplacés. */
function fragment(/** @type {Lang} */ lang, /** @type {string} */ key) {
  const self = pathOf(lang, key);
  return readFileSync(join(here, 'pages', lang, `${key}.html`), 'utf8')
    .replace(/\{\{L:([a-z]+)\}\}/g, (_, k) => {
      const v = k === 'maj' ? LEGAL.maj?.[lang] : LEGAL[k];
      if (!v) return MISSING[lang];
      return k === 'email' ? `<a href="mailto:${esc(v)}">${esc(v)}</a>` : k === 'telephone' ? `<a href="tel:${esc(v.replace(/\s/g, ''))}">${esc(v)}</a>` : esc(v);
    })
    .replace(/\{\{link:([a-z:]+)-(fr|en)\}\}/g, (_, k, l) => rel(self, pathOf(l, k)))
    .replace(/\{\{link:([a-z:]+)\}\}/g, (_, k) => rel(self, pathOf(lang, k)))
    .replace(/\{\{asset:([^}]+)\}\}/g, (_, p) => rel(self, p) + version(p))
    // {{shot:nom|texte alternatif}} : capture en version sombre et claire (selon le système), dans la langue
    .replace(/\{\{shot:([a-z-]+)\|([^}]*)\}\}/g, (_, name, alt) => {
      const phone = name.endsWith('-phone');
      const [w, h] = phone ? [780, 1688] : [1440, 900];
      const src = (/** @type {string} */ theme) => rel(self, `images/${lang}/${name}-${theme}.webp`) + version(`images/${lang}/${name}-${theme}.webp`);
      return `<figure class="shot${phone ? ' phone' : ''}"><a href="${src('dark')}"><picture>`
        + `<source srcset="${src('light')}" media="(prefers-color-scheme: light)">`
        + `<img src="${src('dark')}" width="${w}" height="${h}" loading="lazy" alt="${esc(alt)}"></picture></a></figure>`;
    })
    .replace(/\{\{repo\}\}([\s\S]*?)\{\{\/repo\}\}/g, (_, inner) => REPO ? inner.replaceAll('{{REPO}}', REPO) : '')
    .replace(/\{\{norepo\}\}([\s\S]*?)\{\{\/norepo\}\}/g, (_, inner) => REPO ? '' : inner)
    .replace('{{LICENSES}}', () => licensesHtml(lang));
}

function write(/** @type {string} */ path, /** @type {string} */ html) {
  const file = join(out, path.endsWith('/') || path === '' ? `${path}index.html` : path);
  mkdirSync(dirname(file), { recursive: true });
  writeFileSync(file, html);
}

rmSync(out, { recursive: true, force: true });
mkdirSync(out, { recursive: true });
cpSync(join(here, 'site.css'), join(out, 'site.css'));
writeFileSync(join(out, 'favicon.svg'), LOGO.replace('class="logo" ', 'xmlns="http://www.w3.org/2000/svg" ').replace(/var\(--accent\)/g, '#ff7a2e').replace(/var\(--on-accent\)/g, '#1a0d04'));
if (existsSync(join(here, 'images'))) cpSync(join(here, 'images'), join(out, 'images'), { recursive: true });

for (const lang of LANGS) {
  // Pages de présentation
  for (const key of /** @type {(keyof typeof PAGES)[]} */ (Object.keys(PAGES))) {
    write(pathOf(lang, key), render({ lang, key, title: PAGES[key][lang][1] + (key === 'home' ? '' : ' — Rengaine'), body: fragment(lang, key) }));
  }
  // Documentation
  const dir = lang === 'fr' ? join(root, 'docs', 'fr') : join(root, 'docs');
  const menu = (/** @type {string} */ cur) => `<nav class="doc-menu" aria-label="${T[lang].menu}"><ul>${DOCS.map(([n, fr, en]) =>
    `<li><a href="${n}.html"${n === cur ? ' aria-current="page"' : ''}>${esc(lang === 'fr' ? fr : en)}</a></li>`).join('')}</ul></nav>`;
  for (const [name, fr, en] of DOCS) {
    const html = rewriteLinks(/** @type {string} */ (marked.parse(readFileSync(join(dir, `${name}.md`), 'utf8'))), lang);
    write(pathOf(lang, `doc:${name}`), render({ lang, key: `doc:${name}`, title: `${lang === 'fr' ? fr : en} — Rengaine`,
      body: `<div class="doc">${menu(name)}<article class="prose">${html}</article></div>` }));
  }
  const index = rewriteLinks(/** @type {string} */ (marked.parse(readFileSync(join(dir, 'README.md'), 'utf8'))), lang);
  write(pathOf(lang, 'docs'), render({ lang, key: 'docs', title: `${T[lang].docs} — Rengaine`,
    body: `<div class="doc">${menu('')}<article class="prose">${index}</article></div>` }));
}

// Page d'erreur (servie par Apache sous /404.html, depuis n'importe quel chemin : liens absolus)
write('404.html', readFileSync(join(here, 'pages', '404.html'), 'utf8').replace('{{LOGO}}', LOGO));

// Doc du contrat de l'API (page Redoc autonome produite par api/doc.sh)
if (existsSync(join(root, 'api', 'site', 'index.html'))) {
  cpSync(join(root, 'api', 'site'), join(out, 'api'), { recursive: true, filter: (p) => !p.endsWith('.redoc-version') });
  sitemap.push(`${SITE}/api/`);
}

writeFileSync(join(out, 'sitemap.xml'), `<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n${sitemap.map((u) => `  <url><loc>${u}</loc></url>`).join('\n')}\n</urlset>\n`);
writeFileSync(join(out, 'robots.txt'), `User-agent: *\nAllow: /\nSitemap: ${SITE}/sitemap.xml\n`);
// Contrôle : aucune ressource externe dans les pages (hors liens de navigation)
for (const f of readdirSync(out, { recursive: true })) {
  if (String(f).endsWith('.html')) {
    const s = readFileSync(join(out, String(f)), 'utf8');
    if (/<(script|link|img)[^>]+(src|href)="https?:\/\/(?!rengaine\.org)/.test(s.replace(/<link rel="(canonical|alternate)"[^>]*>/g, ''))) {
      throw new Error(`Ressource externe dans ${f}`);
    }
  }
}
console.log(`Site prêt dans ${out}`);
