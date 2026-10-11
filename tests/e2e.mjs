// Test de bout en bout de l'ERP : serveur PHP sur une base temporaire, 4 rôles, caisse, droits, CSV.
// Usage : node tests/e2e.mjs
import { spawn } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const PHP = process.env.PHP || 'C:/xampp/php/php.exe';
const PORT = 8193;
const BASE = `http://127.0.0.1:${PORT}/`;
const tmp = mkdtempSync(join(tmpdir(), 'ftn-erp-'));
const PW = 'test-1234';

let pass = 0, fail = 0;
const ok = (cond, msg) => { if (cond) { pass++; console.log('  ✓', msg); } else { fail++; console.log('  ✗', msg); } };

const server = spawn(PHP, ['-S', `127.0.0.1:${PORT}`, 'router.php'], {
  cwd: ROOT, env: { ...process.env, FTN_DB: join(tmp, 'erp.sqlite'), FTN_SEED_PASSWORD: PW, FTN_DEBUG: '1' }, stdio: ['ignore', 'pipe', 'pipe'],
});
let serverLog = '';
server.stderr.on('data', (d) => { serverLog += d; });

class Client {
  constructor() { this.cookie = ''; this.csrf = ''; }
  async req(path, opts = {}) {
    const headers = { ...(opts.headers || {}) };
    if (this.cookie) headers.Cookie = this.cookie;
    const r = await fetch(BASE + path, { ...opts, headers, redirect: 'manual' });
    const sc = r.headers.get('set-cookie');
    if (sc) this.cookie = sc.split(';')[0];
    const text = await r.text();
    const m = text.match(/name="csrf" content="([a-f0-9]+)"/);
    if (m) this.csrf = m[1];
    return { status: r.status, text, location: r.headers.get('location') || '' };
  }
  async follow(path, opts) {
    let res = await this.req(path, opts);
    for (let i = 0; i < 5 && res.status >= 300 && res.status < 400; i++) res = await this.req(res.location.replace(/^\//, ''));
    return res;
  }
  form(path, data) {
    const body = new URLSearchParams({ _csrf: this.csrf, ...data });
    return this.follow(path, { method: 'POST', body, headers: { 'Content-Type': 'application/x-www-form-urlencoded' } });
  }
  async json(path, data) {
    const r = await this.req(path, { method: 'POST', body: JSON.stringify(data), headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': this.csrf } });
    try { return { status: r.status, ...JSON.parse(r.text) }; } catch (e) { return { status: r.status, ok: false, error: r.text.slice(0, 300) }; }
  }
  async login(user) {
    await this.follow('index.php?r=login');
    const r = await this.form('index.php?r=login', { username: user, password: PW });
    return r;
  }
}

const clean = (t) => !/(Warning|Notice|Fatal error|Deprecated|Une erreur est survenue|Uncaught)/.test(t);

async function main() {
  for (let i = 0; i < 40; i++) { try { await fetch(BASE); break; } catch (e) { await new Promise((r) => setTimeout(r, 150)); } }

  console.log('Connexion');
  const admin = new Client();
  let r = await admin.login('admin');
  ok(r.text.includes('Tableau de bord') && clean(r.text), 'admin connecté, tableau de bord sans erreur');
  const bad = new Client();
  r = await bad.follow('index.php?r=login');
  r = await bad.form('index.php?r=login', { username: 'admin', password: 'faux' });
  ok(r.text.includes('incorrect'), 'mauvais mot de passe refusé');

  console.log('Pages admin');
  const pages = ['dashboard', 'pos', 'sales', 'clients', 'products', 'products&a=edit', 'stock', 'stock&a=move', 'stock&a=inventory', 'stock&a=history',
    'deliveries', 'deliveries&a=carriers', 'deliveries&a=edit', 'tasks', 'marketing', 'marketing&a=stats', 'marketing&a=edit', 'finances',
    'finances&a=sessions', 'finances&a=expenses', 'finances&a=expense_edit', 'finances&a=receivables', 'io', 'users', 'users&a=edit', 'settings', 'audit', 'account'];
  for (const p of pages) {
    r = await admin.follow('index.php?r=' + p);
    ok(r.status === 200 && clean(r.text), `GET ${p} (${r.status})`);
  }
  r = await admin.follow('index.php?r=products&q=BT+5100');
  ok(/TAPIS PRO BT 5100/.test(r.text), 'catalogue importé depuis l’export du site');

  console.log('Caisse');
  r = await admin.follow('index.php?r=pos&loc=1');
  ok(r.text.includes('Ouvrir la caisse'), 'caisse fermée au départ');
  r = await admin.form('index.php?r=pos&a=open&loc=1', { float: '300' });
  ok(r.text.includes('pos-data'), 'session ouverte, écran de vente affiché');
  const data = JSON.parse(r.text.match(/<script id="pos-data" type="application\/json">(.*?)<\/script>/s)[1]);
  const tapis = data.products.find((p) => p.n === 'TAPIS PRO BT 5100');
  const corde = data.products.find((p) => p.n === 'CORDE A SAUTER SPEED');
  const devis = data.products.find((p) => p.p === 0);
  ok(tapis && tapis.p === 6999000 && corde && corde.p === 49000, 'prix promo appliqué (6 999 DT)');

  let s1 = await admin.json('index.php?r=pos&a=checkout&loc=1', {
    location_id: 1, items: [{ product_id: corde.id, qty: 2, unit_price: '49.000' }], discount: '0',
    client: {}, source: 'facebook', payments: [{ method: 'especes', amount: '98' }],
  });
  ok(s1.ok && /^TUN-\d\d-00001$/.test(s1.number), 'vente espèces enregistrée : ' + (s1.number || s1.error));

  let s2 = await admin.json('index.php?r=pos&a=checkout&loc=1', {
    location_id: 1, items: [{ product_id: tapis.id, qty: 1, unit_price: '6999' }, { product_id: null, label: 'Timbre fiscal', qty: 1, unit_price: '1', vat_rate: 0 }],
    discount: '0', client: { name: 'Samia Ben Ali', phone: '+216 22 333 444', gouvernorat: 'Ariana', city: 'Ennasr' }, source: 'instagram',
    payments: [{ method: 'carte', amount: '3000', reference: 'TPE 123' }],
    delivery: { enabled: true, carrier_id: 1, planned_date: '2026-10-20', fee: '30' },
  });
  ok(s2.ok, 'vente avec acompte + livraison : ' + (s2.number || s2.error));

  let s3 = await admin.json('index.php?r=pos&a=checkout&loc=1', {
    location_id: 1, items: [{ product_id: tapis.id, qty: 1, unit_price: '6999' }], discount: '0', client: {}, payments: [{ method: 'carte', amount: '1000' }],
  });
  ok(!s3.ok && /client sont obligatoires/.test(s3.error), 'reste à payer sans client refusé');
  let s4 = await admin.json('index.php?r=pos&a=checkout&loc=1', {
    location_id: 1, items: [{ product_id: devis.id, qty: 1, unit_price: '0' }], discount: '0', client: {}, payments: [],
  });
  ok(!s4.ok && /sur devis/.test(s4.error), 'produit sur devis sans prix refusé');
  let s5 = await admin.json('index.php?r=pos&a=checkout&loc=1', {
    location_id: 1, items: [{ product_id: corde.id, qty: 1, unit_price: '49' }], discount: '0', client: {}, payments: [{ method: 'especes', amount: '60' }],
  });
  ok(!s5.ok && /dépassent/.test(s5.error), 'paiement supérieur au total refusé');

  r = await admin.follow('index.php?r=ticket&id=' + s2.id);
  ok(r.text.includes('RESTE À PAYER') && r.text.includes('Garantie') && clean(r.text), 'ticket : reste à payer + garantie');
  r = await admin.follow('index.php?r=ticket&id=' + s2.id + '&format=a4');
  ok(r.text.includes('FACTURE') && /Base HT/.test(r.text), 'facture A4 avec TVA');

  r = await admin.follow('index.php?r=stock');
  ok(clean(r.text), 'stock après ventes');
  r = await admin.follow('index.php?r=sales&a=view&id=' + s2.id);
  ok(r.text.includes('LIV-') && r.text.includes('Encaisser un versement'), 'vente liée à une livraison, versement possible');
  r = await admin.form('index.php?r=sales&a=pay', { id: s2.id, amount: '1000', method: 'especes' });
  ok(r.text.includes('Paiement enregistré'), 'versement espèces enregistré');

  // sortie de caisse + clôture
  r = await admin.form('index.php?r=pos&a=expense&loc=1', { amount: '20', label: 'Eau et café', category: 'divers' });
  ok(r.text.includes('Sortie de caisse enregistrée'), 'sortie de caisse');
  r = await admin.follow('index.php?r=pos&a=close&loc=1');
  const expected = +r.text.match(/data-expected="(\d+)"/)[1];
  ok(expected === 300000 + 98000 + 1000000 - 20000, 'espèces attendues = fond + ventes espèces + versement − sortie (' + expected + ')');
  r = await admin.form('index.php?r=pos&a=close&loc=1', { 'd[50000]': '27', 'd[20000]': '1', 'd[5000]': '1', 'd[1000]': '3', keep: '300', envelope: 'E-001', note: '' });
  ok(r.text.includes('Rapport de caisse') && r.text.includes('Écart'), 'clôture avec billetage → rapport Z');

  console.log('Livraison & paiement à la livraison');
  r = await admin.follow('index.php?r=deliveries');
  const shipId = (r.text.match(/r=deliveries&amp;a=edit&amp;id=(\d+)/) || [])[1];
  ok(!!shipId, 'livraison listée');
  r = await admin.form('index.php?r=deliveries&a=status', { id: shipId, status: 'livree' });
  r = await admin.form('index.php?r=deliveries&a=collect', { id: shipId });
  ok(r.text.includes('encaissé'), 'montant à la livraison encaissé');
  r = await admin.follow('index.php?r=sales&a=view&id=' + s2.id);
  ok(r.text.includes('Payée') && r.text.includes('Contre-remboursement'), 'vente soldée par le transporteur');

  console.log('Annulation');
  r = await admin.form('index.php?r=sales&a=cancel', { id: s1.id, reason: 'Erreur de saisie' });
  ok(r.text.includes('Vente annulée'), 'vente annulée (stock remis)');

  console.log('Responsable showroom Tunis');
  const tunis = new Client();
  r = await tunis.login('tunis');
  ok(r.text.includes('Tableau de bord') && clean(r.text), 'connexion responsable Tunis');
  r = await tunis.follow('index.php?r=users');
  ok(r.status === 403, 'accès Utilisateurs refusé (403)');
  r = await tunis.follow('index.php?r=settings');
  ok(r.status === 403, 'accès Paramètres refusé');
  for (const p of ['pos', 'sales', 'stock', 'stock&a=move', 'stock&a=inventory', 'deliveries', 'tasks', 'finances', 'finances&a=sessions', 'finances&a=expenses', 'clients', 'io', 'marketing']) {
    r = await tunis.follow('index.php?r=' + p);
    ok(r.status === 200 && clean(r.text), `GET ${p} (Tunis)`);
  }
  r = await tunis.follow('index.php?r=pos&loc=2');
  ok(r.text.includes('Showroom Tunis') && !r.text.includes('Caisse · Showroom Nabeul'), 'la caisse reste sur son showroom');
  const sNab = await tunis.json('index.php?r=pos&a=checkout&loc=2', { location_id: 2, items: [{ product_id: corde.id, qty: 1, unit_price: '49' }], client: {}, payments: [] });
  ok(!sNab.ok, 'vente pour Nabeul refusée au responsable Tunis');
  r = await tunis.form('index.php?r=pos&a=open', { float: '200' });
  const big = await tunis.json('index.php?r=pos&a=checkout', { location_id: 1, items: [{ product_id: tapis.id, qty: 1, unit_price: '4000' }], client: { name: 'X', phone: '20000000' }, payments: [{ method: 'carte', amount: '4000' }] });
  ok(!big.ok && /maximum autorisé/.test(big.error), 'remise > 20 % bloquée pour le responsable');
  r = await tunis.follow('index.php?r=finances&a=session&id=1');
  ok(r.status === 200 && r.text.includes('Rapport de caisse'), 'rapport Z de son showroom visible');
  r = await tunis.form('index.php?r=stock&a=move', { type: 'entree', from: '2', 'product[]': String(corde.id), 'qty[]': '5', note: 'test' });
  ok(r.text.includes('que pour votre showroom'), 'entrée de stock à Nabeul refusée');
  r = await tunis.form('index.php?r=stock&a=move', { type: 'entree', from: '1', 'product[]': String(corde.id), 'qty[]': '5', note: 'Réception test' });
  ok(r.text.includes('Mouvement REC-'), 'réception dans son showroom');

  console.log('Tâches');
  r = await tunis.follow('index.php?r=tasks');
  ok(r.text.includes('Inventaire : compter les tapis'), 'tableau du showroom Tunis');
  const boardId = r.text.match(/name="board_id" value="(\d+)"/)[1];
  const colIds = [...r.text.matchAll(/data-col="(\d+)"/g)].map((m) => m[1]);
  r = await tunis.form('index.php?r=tasks&a=save', { board_id: boardId, column_id: colIds[0], title: 'Appeler Mme Samia pour la livraison', priority: 'haute', due_date: '2026-10-12' });
  ok(r.text.includes('Appeler Mme Samia'), 'tâche créée');
  const taskId = r.text.match(/data-id="(\d+)"[^>]*data-task='[^']*Appeler Mme Samia/)?.[1] || [...r.text.matchAll(/data-id="(\d+)"/g)].pop()[1];
  const mv = await tunis.json('index.php?r=tasks&a=move', { id: +taskId, column_id: +colIds[3], index: 0 });
  ok(mv.ok, 'tâche déplacée par glisser-déposer');
  r = await tunis.form('index.php?r=tasks&a=col_save', { board_id: boardId, name: 'Attente client', color: '#7c3aed' });
  ok(r.text.includes('Attente client'), 'colonne ajoutée');
  r = await tunis.follow('index.php?r=tasks&b=1');
  ok(r.text.includes('Lecture seule'), 'tableau marketing en lecture seule pour le showroom');

  console.log('Marketing');
  const mk = new Client();
  r = await mk.login('marketing');
  ok(clean(r.text), 'connexion marketing');
  r = await mk.follow('index.php?r=pos');
  ok(r.status === 403, 'caisse interdite au marketing');
  r = await mk.follow('index.php?r=finances');
  ok(r.status === 403, 'finances interdites au marketing');
  r = await mk.form('index.php?r=marketing&a=edit', { name: 'Promo tapis BT — TikTok', channel: 'tiktok', status: 'active', budget: '500', spent: '120,5', leads: '14', start_date: '2026-10-01', end_date: '2026-10-31' });
  ok(r.text.includes('Promo tapis BT'), 'campagne créée');
  r = await mk.follow('index.php?r=marketing&a=stats');
  ok(clean(r.text) && r.text.includes('Instagram'), 'statistiques par origine');
  r = await mk.follow('index.php?r=io&a=export&ds=ventes&from=2026-01-01&to=2026-12-31');
  ok(r.text.includes('N° vente') && !r.text.includes('22333444'), 'export ventes marketing sans téléphones');

  console.log('CSV');
  r = await admin.follow('index.php?r=io&a=export&ds=produits');
  ok(r.text.startsWith('\uFEFF') || r.text.includes('Prix de vente'), 'export produits');
  const csv = r.text.replace(/^\uFEFF/, '');
  const lines = csv.split('\r\n').length > 2 ? csv.split('\r\n') : csv.split('\n');
  ok(lines[0].startsWith('ID;"ID site";Référence'), 'en-têtes français, séparateur ;');
  const fd = new FormData();
  fd.append('_csrf', admin.csrf); fd.append('ds', 'produits');
  fd.append('file', new Blob([csv]), 'produits.csv');
  r = await admin.follow('index.php?r=io&a=upload', { method: 'POST', body: fd });
  ok(r.text.includes('Simulation terminée') && /À mettre à jour<\/span><b>18\d/.test(r.text), 'aperçu de réimport : mises à jour détectées');
  const wfd = new FormData();
  wfd.append('_csrf', admin.csrf); wfd.append('ds', 'produits');
  wfd.append('file', new Blob([readFileSync(join(ROOT, 'data', 'produits-site-2026-10-11.csv'))]), 'wc-product-export.csv');
  r = await admin.follow('index.php?r=io&a=upload', { method: 'POST', body: wfd });
  ok(r.text.includes('Format WooCommerce'), 'export WooCommerce reconnu');
  const token = r.text.match(/name="t" value="([a-f0-9]+)"/)[1];
  r = await admin.form('index.php?r=io&a=commit', { t: token });
  ok(r.text.includes('terminé'), 'import WooCommerce confirmé');
  const cfd = new FormData();
  cfd.append('_csrf', admin.csrf); cfd.append('ds', 'clients');
  cfd.append('file', new Blob(['Nom;Téléphone;Gouvernorat;Origine\r\nMounira Trabelsi;98 111 222;Nabeul;Facebook\r\n;;;\r\nSamia Ben Ali;22333444;Ariana;\r\n']), 'clients.csv');
  r = await admin.follow('index.php?r=io&a=upload', { method: 'POST', body: cfd });
  ok(/À créer<\/span><b class="pos">1</.test(r.text) && /À mettre à jour<\/span><b>1</.test(r.text), 'import clients : 1 nouveau, 1 fusionné par téléphone');
  for (const ds of ['stock', 'mouvements', 'paiements', 'caisses', 'creances', 'clients', 'transporteurs', 'livraisons', 'campagnes', 'depenses']) {
    r = await admin.follow(`index.php?r=io&a=export&ds=${ds}&from=2026-01-01&to=2026-12-31`);
    ok(r.status === 200 && clean(r.text) && r.text.length > 10, 'export ' + ds);
  }
  r = await admin.follow('index.php?r=io&a=export&ds=taches&board=' + boardId);
  ok(r.text.includes('Appeler Mme Samia'), 'export tâches du tableau');

  console.log('Pages finales sans erreur');
  for (const p of ['dashboard', 'finances', 'finances&a=receivables', 'marketing&a=stats', 'audit', 'clients&a=view&id=1']) {
    r = await admin.follow('index.php?r=' + p);
    ok(r.status === 200 && clean(r.text), 'GET ' + p);
  }
}

main().catch((e) => { fail++; console.error(e); }).finally(() => {
  server.kill();
  const warn = serverLog.split('\n').filter((l) => /PHP (Warning|Notice|Fatal|Deprecated)/.test(l));
  if (warn.length) { fail++; console.log('Journal serveur :\n' + warn.slice(0, 20).join('\n')); }
  setTimeout(() => { try { rmSync(tmp, { recursive: true, force: true }); } catch (e) { /* fichier verrouillé */ } }, 300);
  console.log(`\n${pass} réussis, ${fail} échoués`);
  process.exitCode = fail ? 1 : 0;
});
