// Captures d'écran sur une base de démonstration temporaire.
// Usage : node tests/shots.mjs   → images dans tests/out/
import { spawn, execFileSync } from 'node:child_process';
import { mkdtempSync, mkdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

const require = createRequire('D:/Projects/fitness-tn/uml/node_modules/');
const puppeteer = require('puppeteer-core');
const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const OUT = join(ROOT, 'tests', 'out');
mkdirSync(OUT, { recursive: true });
const PHP = 'C:/xampp/php/php.exe';
const PORT = 8194;
const BASE = `http://127.0.0.1:${PORT}/`;
const tmp = mkdtempSync(join(tmpdir(), 'ftn-shots-'));
const env = { ...process.env, FTN_DB: join(tmp, 'erp.sqlite'), FTN_SEED_PASSWORD: 'demo-2026' };
console.log(execFileSync(PHP, ['tests/demo_seed.php'], { cwd: ROOT, env }).toString().trim());
const server = spawn(PHP, ['-S', `127.0.0.1:${PORT}`, 'router.php'], { cwd: ROOT, env, stdio: 'ignore' });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
await sleep(600);

const browser = await puppeteer.launch({
  executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: 'new',
  userDataDir: join(tmp, 'chrome'), args: ['--no-first-run', '--disable-extensions', '--force-device-scale-factor=1'],
});
const errors = [];
async function newPage(w = 1440, h = 900, isolated = false) {
  const ctx = isolated ? await (browser.createBrowserContext || browser.createIncognitoBrowserContext).call(browser) : browser;
  const page = await ctx.newPage();
  page.on('response', (r) => { if (r.status() >= 400) errors.push(r.status() + ' ' + r.url()); });
  await page.setViewport({ width: w, height: h });
  page.on('pageerror', (e) => errors.push(e.message));
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  return page;
}
async function login(page, user) {
  await page.goto(BASE + 'index.php?r=login', { waitUntil: 'networkidle0' });
  await page.type('input[name=username]', user);
  await page.type('input[name=password]', 'demo-2026');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('button[type=submit]')]);
}
const shot = async (page, name, full = false) => { await page.screenshot({ path: join(OUT, name + '.png'), fullPage: full }); console.log('  ' + name); };
const go = (page, r) => page.goto(BASE + 'index.php?r=' + r, { waitUntil: 'networkidle0' });

try {
  const p = await newPage();
  await p.goto(BASE, { waitUntil: 'networkidle0' });
  await shot(p, '01-connexion');
  await login(p, 'admin');
  await shot(p, '02-tableau-de-bord', true);
  // survol d'une colonne du graphique
  const col = await p.$$('.chart .col');
  if (col.length) { const b = await col[col.length - 3].boundingBox(); await p.mouse.move(b.x + b.width / 2, b.y + b.height - 30); await sleep(200); await shot(p, '03-graphique-infobulle'); }

  await go(p, 'pos&loc=1');
  await p.type('#pos-q', 'bt 5100');
  await sleep(150);
  await p.click('.pcard');
  await p.$eval('#pos-q', (el) => { el.value = ''; el.dispatchEvent(new Event('input')); });
  await p.click('.chip[data-f="Accessoires"]');
  await sleep(150);
  const cards = await p.$$('.pcard');
  console.log('  cartes :', cards.length);
  await cards[1].click(); await cards[1].click(); await cards[4].click();
  await p.type('#cl-phone', '22');
  await sleep(500);
  await shot(p, '04-caisse-panier');
  const sug = await p.$('#cl-suggest button');
  if (sug) await sug.click();
  await p.select('#sale-source', 'facebook');
  await p.click('#btn-pay');
  await sleep(200);
  await p.click('#p-methods [data-m="carte"]');
  await p.$eval('#p-rows .amt', (el) => { el.value = '500'; el.dispatchEvent(new Event('input', { bubbles: true })); });
  await p.type('#p-given', '500');
  await p.click('#p-methods [data-m="carte"]');
  await p.click('#d-on');
  await sleep(200);
  await shot(p, '05-caisse-encaissement');
  await p.click('#p-submit');
  await p.waitForSelector('#done-dlg[open]');
  await sleep(200);
  await shot(p, '06-caisse-vente-ok');
  const ticket = await p.$eval('#done-ticket', (a) => a.href.replace('&print=1', ''));
  const t = await newPage(520, 900);
  await t.goto(ticket, { waitUntil: 'networkidle0' });
  await shot(t, '07-ticket', true);
  await t.goto(ticket + '&format=a4', { waitUntil: 'networkidle0' });
  await t.setViewport({ width: 900, height: 1200 });
  await shot(t, '08-facture-a4', true);
  await t.close();

  await go(p, 'pos&a=close&loc=1');
  await p.type('input[name="d[50000]"]', '20');
  await p.type('input[name="d[20000]"]', '3');
  await shot(p, '09-cloture-caisse', true);

  for (const [r, name, full] of [['sales', '10-ventes'], ['products', '11-produits'], ['products&a=view&id=10', '12-fiche-produit', true], ['stock', '13-stock'],
    ['stock&a=move', '14-mouvement-stock'], ['deliveries', '15-livraisons'], ['deliveries&a=carriers', '16-transporteurs'], ['tasks&b=2', '17-taches-showroom'],
    ['tasks&b=1', '18-taches-marketing'], ['marketing', '19-campagnes'], ['marketing&a=stats', '20-marketing-stats', true], ['finances', '21-finances', true],
    ['finances&a=sessions', '22-caisses'], ['finances&a=receivables', '23-a-encaisser'], ['io', '24-import-export', true], ['users', '25-utilisateurs'],
    ['settings', '26-parametres', true], ['clients', '27-clients']]) {
    await go(p, r);
    await shot(p, name, !!full);
  }
  // fiche tâche
  await go(p, 'tasks&b=2');
  await p.click('.kcard');
  await sleep(200);
  await shot(p, '28-tache-fiche');

  const s = await newPage(1440, 900, true);
  await login(s, 'nabeul');
  await shot(s, '29-tableau-de-bord-nabeul', true);
  const m = await newPage(1440, 900, true);
  await login(m, 'marketing');
  await shot(m, '30-tableau-de-bord-marketing', true);

  const mob = await newPage(390, 844, true);
  await login(mob, 'tunis');
  await shot(mob, '31-mobile-tableau-de-bord', true);
  await mob.goto(BASE + 'index.php?r=pos', { waitUntil: 'networkidle0' });
  await shot(mob, '32-mobile-caisse');
  await mob.click('[data-toggle-side]');
  await sleep(300);
  await shot(mob, '33-mobile-menu');
} catch (e) {
  console.error(e);
  process.exitCode = 1;
} finally {
  await browser.close();
  server.kill();
  console.log(errors.length ? 'Erreurs navigateur :\n' + [...new Set(errors)].join('\n') : 'Aucune erreur JavaScript.');
}
