// Test de bout en bout de l'ERP : serveur PHP sur une base temporaire, 4 rôles, caisse, droits, CSV.
// Usage : node tests/e2e.mjs
import { spawn } from 'node:child_process';
import { mkdtempSync, readFileSync, readdirSync, rmSync } from 'node:fs';
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
  cwd: ROOT, env: { ...process.env, FTN_DB: join(tmp, 'erp.sqlite'), FTN_SEED_PASSWORD: PW, FTN_DEBUG: '1', FTN_BACKUP_DIR: join(tmp, 'backups') }, stdio: ['ignore', 'pipe', 'pipe'],
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

  console.log('Devis & factures');
  const stockOf = async (pid) => {
    const t = (await admin.follow('index.php?r=products&a=view&id=' + pid)).text;
    const m = t.match(/Showroom Tunis\s*<b>(-?\d+)<\/b>/);
    return m ? +m[1] : null;
  };
  const docId = (t) => +((t.match(/name="id" value="(\d+)"/) || [])[1] || 0);
  for (const p of ['quotes', 'invoices', 'quotes&a=edit', 'invoices&a=edit']) {
    r = await admin.follow('index.php?r=' + p);
    ok(r.status === 200 && clean(r.text), `GET ${p}`);
  }
  r = await admin.form('index.php?r=quotes&a=save', {
    'client[name]': 'AMIR', 'client[phone]': '28 596 986', 'client[address]': 'Ariana 56, rue de l’Industrie', 'client[mf]': '1234567/A/M/000',
    date: '2026-10-17', due_date: '2026-11-01', location_id: '1', timbre: '1', discount: '',
    'items[0][product_id]': '', 'items[0][label]': 'Machine musculation', 'items[0][qty]': '1', 'items[0][unit_price]': '1600', 'items[0][discount_pct]': '', 'items[0][vat_rate]': '19',
    'items[1][product_id]': String(corde.id), 'items[1][label]': 'CORDE A SAUTER SPEED', 'items[1][qty]': '2', 'items[1][unit_price]': '49', 'items[1][discount_pct]': '10', 'items[1][vat_rate]': '19',
    terms: 'Paiement comptant à la livraison', transport: 'Gratuit', warranty: 'Selon le produit',
  });
  ok(clean(r.text) && /Devis DV-000001\/\d\d/.test(r.text), 'devis créé avec le numéro DV-000001/aa');
  // 1600 + 2 × 49 × 0,9 = 1688,200 TTC, + timbre 1 = 1689,200
  ok(r.text.includes('1 689,200'), 'net à payer 1 689,200 DT (remise de ligne + timbre)');
  const qid = docId(r.text);
  r = await admin.follow('index.php?r=doc&id=' + qid);
  ok(clean(r.text) && r.text.includes('DEVIS') && r.text.includes('Mille six cent quatre-vingt-neuf dinars et deux cents millimes'), 'devis A4 avec montant en lettres');
  ok(r.text.includes('1234567/A/M/000') && r.text.includes('Timbre fiscal'), 'devis A4 : MF du client et timbre fiscal');
  r = await admin.form('index.php?r=quotes&a=status', { id: String(qid), status: 'accepte' });
  ok(r.text.includes('Statut mis à jour : Accepté'), 'devis marqué accepté');
  const before = await stockOf(corde.id);
  r = await admin.form('index.php?r=quotes&a=convert', { id: String(qid), stock: '1' });
  ok(clean(r.text) && /Facture 000001\/\d\d/.test(r.text), 'devis transformé en facture 000001/aa');
  const invId = docId(r.text);
  const after = await stockOf(corde.id);
  ok(before !== null && after === before - 2, `stock sorti à la facturation (${before} → ${after})`);
  r = await admin.follow('index.php?r=quotes&a=view&id=' + qid);
  ok(r.text.includes('Facturé'), 'devis passé au statut « Facturé »');
  r = await admin.form('index.php?r=invoices&a=pay', { id: String(invId), amount: '689,200', method: 'virement', reference: 'VIR 1', date: '2026-10-18' });
  ok(r.text.includes('Paiement partiel'), 'acompte : facture en paiement partiel');
  r = await admin.form('index.php?r=invoices&a=pay', { id: String(invId), amount: '2000', method: 'especes', reference: '' });
  ok(r.text.includes('Montant invalide'), 'paiement supérieur au reste refusé');
  r = await admin.form('index.php?r=invoices&a=pay', { id: String(invId), amount: '1000', method: 'especes', reference: '' });
  ok(r.text.includes('Payée'), 'solde : facture payée');
  r = await admin.follow('index.php?r=doc&id=' + invId);
  ok(r.text.includes('FACTURE') && r.text.includes('Déjà réglé'), 'facture A4 : déjà réglé');
  r = await admin.form('index.php?r=invoices&a=cancel', { id: String(invId), reason: 'Test' });
  ok(r.text.includes('Facture annulée') && (await stockOf(corde.id)) === before, 'facture annulée : stock remis');
  r = await admin.form('index.php?r=invoices&a=from_sale', { sale_id: String(s2.id) });
  ok(clean(r.text) && /Facture 000002\/\d\d/.test(r.text) && r.text.includes('Samia Ben Ali'), 'facture établie depuis une vente de caisse');
  // Vente soldée plus haut : acompte carte 3 000 + versement espèces 1 000 + contre-remboursement
  ok(r.text.includes('Payée') && r.text.includes('3 000,000') && r.text.includes('Contre-remboursement'), 'paiements de la vente repris sur la facture (soldée)');
  r = await admin.follow('index.php?r=sales&a=view&id=' + s2.id);
  ok(/Facture 000002\//.test(r.text), 'la vente renvoie vers sa facture');
  r = await admin.form('index.php?r=invoices&a=save', { 'client[name]': '', date: '2026-10-20', 'items[0][label]': 'Ligne X', 'items[0][qty]': '1', 'items[0][unit_price]': '10', 'items[0][vat_rate]': '19' });
  ok(r.text.includes('nom du client est obligatoire') && r.text.includes('Ligne X'), 'erreur de saisie : message affiché, saisie conservée');
  r = await admin.req('index.php?r=search&q=AMIR');
  ok(r.text.includes('DV-000001') && r.text.includes('Clients'), 'recherche globale : client et devis trouvés');
  r = await admin.follow('index.php?r=io&a=export&ds=factures&from=2026-01-01&to=2026-12-31');
  ok(r.text.includes('000002/') && r.text.includes('Matricule fiscal'), 'export CSV des factures');
  r = await tunis.follow('index.php?r=invoices');
  ok(r.status === 200 && r.text.includes('000002/'), 'showroom Tunis : factures de son showroom visibles');
  r = await mk.follow('index.php?r=invoices');
  ok(r.status === 200 && r.text.includes('000002/'), 'marketing : factures de toute l’équipe visibles (partage activé)');
  const billing = { company_legal_name: 'STE TEST', company_address: 'Ariana', company_mf: '1111111/A/M/000', next_invoice: '19', next_quote: '2', quote_prefix: 'DV-', invoice_prefix: '',
    quote_validity_days: '15', invoice_due_days: '0', doc_color: '#145c54', invoice_terms: 'x', invoice_transport: 'y', invoice_warranty: 'z', warranty_notice: 'n',
    bank_name: 'UBCI', bank_rib: '1', bank_iban: '2', bank_bic: '3', docs_shared: '1' };
  r = await admin.form('index.php?r=settings&a=billing', billing);
  ok(r.text.includes('Paramètres de facturation enregistrés') && r.text.includes('name="next_invoice" type="number" min="1" value="19"'), 'numérotation : prochaine facture n° 19');
  r = await admin.form('index.php?r=settings&a=billing', { ...billing, next_invoice: '2' });
  ok(r.text.includes('déjà utilisé'), 'numéro de facture déjà utilisé refusé');
  r = await admin.form('index.php?r=settings&a=billing', { ...billing, docs_shared: '' });
  r = await mk.follow('index.php?r=invoices');
  ok(r.status === 403, 'partage décoché : marketing sans accès aux factures');
  r = await admin.form('index.php?r=settings&a=billing', billing);
  r = await mk.follow('index.php?r=invoices');
  ok(r.status === 200, 'partage réactivé : marketing voit de nouveau les factures');
  // CRUD complet : un showroom supprime une facture du milieu (avec paiement), puis la dernière en lot
  const quick = (name) => ({ 'client[name]': name, 'client[phone]': '22 333 444', date: '2026-10-21', location_id: '1', timbre: '1',
    'items[0][label]': 'Accessoire', 'items[0][qty]': '1', 'items[0][unit_price]': '30', 'items[0][vat_rate]': '19' });
  r = await tunis.form('index.php?r=invoices&a=save', quick('Client suppr A'));
  const delA = docId(r.text);
  ok(/Facture 000019\//.test(r.text), 'showroom : facture 000019 créée');
  r = await tunis.form('index.php?r=invoices&a=save', quick('Client suppr B'));
  const delB = docId(r.text);
  r = await tunis.form('index.php?r=invoices&a=pay', { id: String(delA), amount: '10', method: 'virement', reference: 'T' });
  r = await tunis.follow('index.php?r=invoices&a=view&id=' + delA);
  ok(r.text.includes('Modifier') && r.text.includes('ne sera pas réutilisé'), 'facture encaissée : modifiable et supprimable par le showroom');
  r = await tunis.form('index.php?r=invoices&a=delete', { id: String(delA) });
  ok(r.text.includes('Facture 000019/') && r.text.includes('supprimée') && !r.text.includes('Client suppr A'), 'showroom : facture du milieu supprimée avec son paiement');
  r = await mk.form('index.php?r=invoices&a=bulk_delete', { 'ids[]': String(delB) });
  ok(r.status === 403, 'marketing (lecture seule) : suppression refusée');
  r = await tunis.form('index.php?r=invoices&a=bulk_delete', { 'ids[]': String(delB) });
  ok(r.text.includes('1 facture(s) supprimée(s)') && !r.text.includes('Client suppr B'), 'suppression en lot');
  r = await admin.follow('index.php?r=settings');
  ok(r.text.includes('name="next_invoice" type="number" min="1" value="19"'), 'numéros libérés : la prochaine facture reprend le n° 19');

  console.log('Factures, caisse & finances');
  await admin.form('index.php?r=pos&a=open&loc=1', { float: '100' });
  const expectedCash = async () => +(((await admin.follow('index.php?r=pos&a=close&loc=1')).text.match(/data-expected="(\d+)"/) || [])[1] || -1);
  const exp1 = await expectedCash();
  r = await admin.form('index.php?r=invoices&a=save', { 'client[name]': 'Salle Test', 'client[phone]': '71 222 333', date: new Date().toISOString().slice(0, 10), location_id: '1',
    'items[0][label]': 'Service installation', 'items[0][qty]': '1', 'items[0][unit_price]': '500', 'items[0][vat_rate]': '19', due_date: '2020-01-01' });
  ok(r.text.includes('échéance précède'), 'échéance antérieure à la date refusée');
  r = await admin.form('index.php?r=invoices&a=save', { 'client[name]': 'Salle Test', 'client[phone]': '71 222 333', date: new Date().toISOString().slice(0, 10), location_id: '1',
    'items[0][label]': 'Service installation', 'items[0][qty]': '1', 'items[0][unit_price]': '500', 'items[0][vat_rate]': '19' });
  const inv3 = docId(r.text);
  ok(/Facture 000019\/\d\d/.test(r.text), 'numérotation reprise à 000019');
  ok(r.text.includes('ajoutées à la caisse ouverte'), 'paiement : la caisse ouverte est signalée');
  r = await admin.form('index.php?r=invoices&a=pay', { id: String(inv3), amount: '200', method: 'especes', reference: '' });
  ok(r.text.includes('caisse n°'), 'paiement espèces rattaché à la caisse ouverte');
  const exp2 = await expectedCash();
  ok(exp1 >= 0 && exp2 - exp1 === 200000, `clôture : espèces attendues + 200 DT de paiement de facture (${exp1} → ${exp2})`);
  r = await admin.follow('index.php?r=finances&a=receivables');
  ok(r.text.includes('Factures à encaisser') && r.text.includes('Salle Test') && r.text.includes('Relancer'), 'à encaisser : facture avec relance WhatsApp');
  r = await admin.follow('index.php?r=finances&p=annee');
  ok(clean(r.text) && /facture\(s\) hors caisse/.test(r.text), 'synthèse : CA inclut les factures hors caisse');
  r = await admin.follow(`index.php?r=io&a=export&ds=paiements&from=2026-01-01&to=2030-12-31`);
  ok(r.text.includes('Facture 000019/'), 'export des paiements : paiement de facture inclus');
  const cl = await admin.json('index.php?r=pos&a=checkout&loc=1', { location_id: 1, items: [{ product_id: corde.id, qty: 1, unit_price: '49' }], discount: '0',
    client: { name: 'Client Caisse', phone: '55 666 777' }, payments: [{ method: 'carte', amount: '49' }] });
  ok(cl.ok && cl.has_client === true, 'caisse : facture officielle proposée quand le client est renseigné');
  r = await admin.follow('index.php?r=settings');
  ok(r.text.includes('Sauvegardes') && /erp-fitnesstn|backup_file/.test(r.text), 'sauvegarde automatique du jour listée');
  const bk = readdirSync(join(tmp, 'backups'));
  ok(bk.length === 1 && /^erp-\d{4}-\d\d-\d\d\.sqlite$/.test(bk[0]), 'fichier de sauvegarde créé : ' + bk.join(', '));
  r = await admin.req('index.php?r=settings&a=backup_file&f=' + encodeURIComponent('../erp.sqlite'));
  ok(r.status === 302, 'téléchargement de sauvegarde : nom de fichier contrôlé');

  console.log('Livreur / transporteur');
  r = await admin.form('index.php?r=deliveries&a=save', { recipient: 'Client Nabeul', phone: '98 111 222', location_id: '2', gouvernorat: 'Nabeul', city: 'Hammamet', address: 'Rue de la Plage', status: 'a_preparer', cod_amount: '250' });
  r = await admin.follow('index.php?r=deliveries&q=Client+Nabeul');
  const nabShip = (r.text.match(/r=deliveries&amp;a=edit&amp;id=(\d+)/) || [])[1];
  ok(!!nabShip, 'livraison du showroom de Nabeul créée');
  const liv = new Client();
  r = await liv.login('livreur');
  ok(r.text.includes('<title>Livraisons') && clean(r.text), 'livreur connecté : arrive directement sur les livraisons');
  ok(r.text.includes('Client Nabeul') && r.text.includes('Itinéraire'), 'livreur : livraisons de tous les showrooms, avec itinéraire');
  for (const p of ['sales', 'quotes', 'invoices', 'clients', 'products', 'stock', 'invoices&a=view&id=' + invId, 'sales&a=view&id=' + s2.id]) {
    r = await liv.follow('index.php?r=' + p);
    ok(r.status === 200 && clean(r.text) && !r.text.includes('Nouvelle facture') && !r.text.includes('Nouveau client') && !r.text.includes('Enregistrer le paiement'), `livreur : ${p} en consultation`);
  }
  for (const [p, data] of [['invoices&a=save', { 'client[name]': 'X' }], ['invoices&a=internal_note', { id: String(invId), internal_note: 'x' }], ['invoices&a=pay', { id: String(invId), amount: '1', method: 'especes' }],
    ['clients&a=save', { name: 'X' }], ['sales&a=note', { id: String(s2.id), note: 'x' }], ['stock&a=move', { type: 'entree' }], ['pos&a=open&loc=1', { float: '1' }]]) {
    r = await liv.form('index.php?r=' + p, data);
    ok(r.status === 403 || r.text.includes('Accès refusé'), `livreur : écriture refusée (${p})`);
  }
  for (const p of ['dashboard', 'pos', 'finances', 'deliveries&a=carriers', 'settings', 'users', 'io']) {
    r = await liv.follow('index.php?r=' + p);
    ok(r.status === 403, `livreur : ${p} interdit (${r.status})`);
  }
  r = await liv.follow('index.php?r=deliveries&a=edit&id=' + nabShip);
  ok(r.text.includes('Mettre à jour la livraison') && !r.text.includes('Coût transporteur'), 'livreur : fiche avec boutons de statut, sans le coût du transporteur');
  r = await liv.form('index.php?r=deliveries&a=status', { id: nabShip, status: 'annulee' });
  ok(r.text.includes('peut seulement indiquer'), 'livreur : annulation refusée');
  r = await liv.form('index.php?r=deliveries&a=status', { id: nabShip, status: 'livree' });
  ok(r.text.includes(': Livrée.'), 'livreur : livraison marquée livrée');
  r = await liv.form('index.php?r=deliveries&a=collect', { id: nabShip });
  ok(r.status === 403 || r.text.includes('Accès refusé'), 'livreur : ne peut pas valider l’encaissement');
  r = await liv.form('index.php?r=deliveries&a=save', { id: nabShip, recipient: 'X', status: 'a_preparer' });
  ok(r.status === 403 || r.text.includes('Accès refusé'), 'livreur : ne peut pas modifier la livraison');

  console.log('Bons de livraison, transporteurs & modalités de paiement');
  for (const p of ['delivery_notes', 'delivery_notes&a=edit', 'doc_lists']) {
    r = await admin.follow('index.php?r=' + p);
    ok(r.status === 200 && clean(r.text), `GET ${p}`);
  }
  r = await admin.follow('index.php?r=doc_lists');
  ok(['Comptant à la livraison', 'Chèque', 'Virement', 'Traite'].every((m) => r.text.includes(m)), 'modalités de départ : comptant à la livraison, chèque, virement, traite');
  r = await admin.form('index.php?r=doc_lists&a=tr_save', { id: '0', name: 'Omar Ben Salah', plate: '245 tu  7788', cin: '12345678' });
  ok(r.text.includes('Transporteur ajouté') && r.text.includes('245 TU 7788'), 'transporteur ajouté (matricule en majuscules)');
  const trId = +((r.text.match(/tr=(\d+)#transporteurs" title="Modifier Omar Ben Salah"/) || [])[1] || 0);
  r = await admin.form('index.php?r=doc_lists&a=tr_save', { id: '0', name: 'Mauvaise CIN', plate: '', cin: '1234' });
  ok(r.text.includes('8 chiffres') && !r.text.includes('Transporteur ajouté'), 'CIN de 4 chiffres refusée');
  r = await admin.form('index.php?r=doc_lists&a=tr_save', { id: String(trId), name: 'Omar Ben Salah', plate: '245 TU 7788', cin: '11223344' });
  ok(r.text.includes('Transporteur modifié') && r.text.includes('11223344'), 'transporteur modifié');
  r = await admin.form('index.php?r=doc_lists&a=pm_save', { id: '0', label: 'Carte bancaire' });
  ok(r.text.includes('Modalité de paiement ajoutée') && r.text.includes('Carte bancaire'), 'modalité ajoutée');
  r = await admin.form('index.php?r=doc_lists&a=pm_save', { id: '0', label: 'chèque' });
  ok(r.text.includes('existe déjà'), 'modalité en double refusée');
  const pmId = (lab) => +((r.text.match(new RegExp('pm=(\\d+)#modalites" title="Modifier" aria-label="Modifier ' + lab + '"')) || [])[1] || 0);
  r = await admin.follow('index.php?r=doc_lists');
  const traite = pmId('Traite'), carte = pmId('Carte bancaire');
  r = await admin.form('index.php?r=doc_lists&a=pm_default', { id: String(traite) });
  ok(r.text.includes('« Traite » est proposée par défaut'), 'modalité par défaut : traite');
  r = await admin.form('index.php?r=doc_lists&a=pm_save', { id: String(carte), label: 'Carte bancaire (TPE)' });
  ok(r.text.includes('Modalité de paiement modifiée') && r.text.includes('Carte bancaire (TPE)'), 'modalité renommée');
  r = await admin.form('index.php?r=doc_lists&a=pm_move', { id: String(carte), dir: '-1' });
  ok(r.text.indexOf('Carte bancaire (TPE)') < r.text.indexOf('<b>Traite</b>'), 'modalité remontée dans la liste');
  r = await admin.follow('index.php?r=invoices&a=edit');
  ok(r.text.includes('<option value="Traite" selected>') && r.text.includes('data-tr-pick') && r.text.includes('Omar Ben Salah · 245 TU 7788'), 'nouvelle facture : traite par défaut, transporteurs proposés');
  r = await mk.form('index.php?r=doc_lists&a=tr_save', { id: '0', name: 'Pirate', plate: '', cin: '' });
  ok(r.text.includes('ne permet pas de modifier ces listes'), 'marketing : listes en lecture seule');

  const today = new Date().toISOString().slice(0, 10);
  const blLine = (qty) => ({ 'client[name]': 'Gym Carthage', 'client[phone]': '98 111 222', date: today, location_id: '1',
    'items[0][product_id]': String(corde.id), 'items[0][label]': 'CORDE A SAUTER SPEED', 'items[0][qty]': String(qty), 'items[0][unit_price]': '49', 'items[0][vat_rate]': '19' });
  r = await admin.form('index.php?r=invoices&a=save', { ...blLine(1), timbre: '1', payment_mode: 'Chèque', 'transporter[id]': String(trId), 'transporter[name]': 'Omar Ben Salah',
    'transporter[plate]': '245 TU 7788', 'transporter[cin]': '1234567' });
  ok(r.text.includes('8 chiffres') && r.text.includes('Gym Carthage'), 'facture : CIN invalide refusée, saisie conservée');
  const s0 = await stockOf(corde.id);
  r = await admin.form('index.php?r=invoices&a=save', { ...blLine(1), timbre: '1', stock: '1', payment_mode: 'Chèque', terms: 'Chèque à 30 jours', 'transporter[id]': String(trId),
    'transporter[name]': 'Omar Ben Salah', 'transporter[plate]': '245 TU 7788', 'transporter[cin]': '11223344' });
  const fInv = docId(r.text);
  ok(clean(r.text) && r.text.includes('Facture créée') && r.text.includes('Chèque à 30 jours') && r.text.includes('CIN 11223344'), 'facture avec modalité et transporteur');
  ok((await stockOf(corde.id)) === s0 - 1, 'facture : stock sorti');
  r = await admin.follow('index.php?r=doc&id=' + fInv);
  ok(['Modalité de paiement', 'Chèque', 'Matricule du véhicule', '245 TU 7788', 'Omar Ben Salah', '11223344'].every((x) => r.text.includes(x)), 'facture A4 : modalité, transporteur, matricule et CIN');
  r = await admin.form('index.php?r=invoices&a=convert', { id: String(fInv), to: 'bl', stock: '1' });
  const bl1 = docId(r.text);
  ok(clean(r.text) && /Bon de livraison BL-000001\/\d\d/.test(r.text) && r.text.includes('Bon de livraison créé à partir de la facture'), 'bon de livraison BL-000001 créé depuis la facture');
  ok((await stockOf(corde.id)) === s0 - 1, 'BL issu d’une facture : le stock n’est pas sorti deux fois');
  ok(r.text.includes('accompagne la facture') && !r.text.includes('Établir la facture'), 'BL issu d’une facture : pas de seconde facture proposée');
  r = await admin.form('index.php?r=delivery_notes&a=convert', { id: String(bl1), to: 'facture' });
  ok(r.text.includes('déjà lié à la facture'), 'BL issu d’une facture : seconde facture refusée');
  r = await admin.follow('index.php?r=doc&id=' + bl1 + '&noprice=1');
  ok(r.text.includes('BON DE LIVRAISON') && !r.text.includes('P.U. HT') && r.text.includes('Signature du transporteur') && r.text.includes('Nombre d’articles'), 'BL sans prix : articles, quantités et signatures');
  r = await admin.follow('index.php?r=doc&id=' + bl1);
  ok(r.text.includes('P.U. HT') && r.text.includes('Livré à') && r.text.includes('Reçu par le client'), 'BL avec prix');
  r = await admin.form('index.php?r=delivery_notes&a=status', { id: String(bl1), status: 'livre' });
  ok(r.text.includes('Statut mis à jour : Livré') && r.text.includes('Livré au client'), 'BL marqué livré');

  // BL direct, nouveau transporteur saisi et enregistré dans la liste, puis facturé
  const s1b = await stockOf(corde.id);
  r = await tunis.form('index.php?r=delivery_notes&a=save', { ...blLine(2), stock: '1', timbre: '1', due_date: '2020-01-01', payment_mode: 'Comptant à la livraison',
    'transporter[id]': '', 'transporter[name]': 'Transport Sami', 'transporter[plate]': '120 tu 3344', 'transporter[cin]': '87654321', 'transporter[save]': '1' });
  const bl2 = docId(r.text);
  ok(clean(r.text) && /Bon de livraison BL-000002\//.test(r.text) && r.text.includes('Bon de livraison créé.'), 'showroom : BL créé directement');
  ok(!r.text.includes('Timbre fiscal') && (await stockOf(corde.id)) === s1b - 2, 'BL : pas de timbre, stock sorti');
  r = await admin.follow('index.php?r=doc_lists');
  ok(r.text.includes('Transport Sami') && r.text.includes('120 TU 3344'), 'nouveau transporteur ajouté à la liste depuis le BL');
  r = await admin.follow('index.php?r=delivery_notes');
  ok(r.text.includes('Transport Sami') && r.text.includes('BL-000002/') && r.text.includes('À livrer'), 'liste des BL : transporteur et statut');
  r = await admin.follow('index.php?r=delivery_notes&q=3344');
  ok(r.text.includes('BL-000002/') && !r.text.includes('BL-000001/'), 'recherche d’un BL par matricule');
  r = await admin.form('index.php?r=delivery_notes&a=convert', { id: String(bl2), to: 'facture' });
  const fBl = docId(r.text);
  ok(clean(r.text) && r.text.includes('Facture créée à partir du bon de livraison') && r.text.includes('Transport Sami'), 'facture établie depuis le BL (transporteur repris)');
  ok((await stockOf(corde.id)) === s1b - 2 && r.text.includes('Sorti par le bon de livraison'), 'facture d’un BL : le stock n’est pas sorti deux fois');
  r = await admin.follow('index.php?r=delivery_notes&a=view&id=' + bl2);
  ok(r.text.includes('Facturé') && r.text.includes('annulez ou supprimez d’abord la facture'), 'BL facturé : suppression bloquée tant que la facture existe');
  r = await admin.form('index.php?r=delivery_notes&a=delete', { id: String(bl2) });
  ok(r.text.includes('annulez ou supprimez d’abord la facture'), 'BL facturé : suppression refusée');
  r = await admin.form('index.php?r=invoices&a=cancel', { id: String(fBl), reason: 'Test BL' });
  r = await admin.follow('index.php?r=delivery_notes&a=view&id=' + bl2);
  ok(r.text.includes('Établir la facture') && r.text.includes('>Livré<'), 'facture annulée : le BL redevient « livré » et facturable');
  r = await admin.form('index.php?r=delivery_notes&a=delete', { id: String(bl2) });
  ok(r.text.includes('BL-000002/') && r.text.includes('supprimé') && r.text.includes('prochain bon de livraison') && (await stockOf(corde.id)) === s1b, 'BL supprimé : stock remis, numéro libéré');
  r = await admin.follow('index.php?r=settings');
  ok(r.text.includes('name="next_bl" type="number" min="1" value="2"'), 'paramètres : prochain BL n° 2');
  r = await admin.follow('index.php?r=io&a=export&ds=bons_livraison&from=2026-01-01&to=2030-12-31');
  ok(r.text.includes('CIN transporteur') && r.text.includes('BL-000001/') && r.text.includes('11223344'), 'export CSV des bons de livraison');
  r = await admin.req('index.php?r=search&q=BL-0000');
  ok(r.text.includes('Bons de livraison'), 'recherche globale : BL trouvé');

  // Supprimer des éléments des listes ne change pas les documents établis
  r = await admin.form('index.php?r=doc_lists&a=tr_delete', { id: String(trId) });
  ok(r.text.includes('Transporteur « Omar Ben Salah » supprimé'), 'transporteur supprimé');
  r = await admin.follow('index.php?r=doc&id=' + fInv);
  ok(r.text.includes('Omar Ben Salah') && r.text.includes('245 TU 7788'), 'la facture garde le transporteur supprimé de la liste');
  r = await admin.follow('index.php?r=doc_lists');
  r = await admin.form('index.php?r=doc_lists&a=pm_delete', { id: String(pmId('Chèque')) });
  ok(r.text.includes('Modalité « Chèque » retirée'), 'modalité supprimée');
  r = await admin.follow('index.php?r=invoices&a=edit&id=' + fInv);
  ok(r.text.includes('<option value="Chèque" selected>'), 'la facture garde sa modalité retirée de la liste');

  console.log('Pages finales sans erreur');
  for (const p of ['dashboard', 'finances', 'finances&a=receivables', 'marketing&a=stats', 'audit', 'clients&a=view&id=1', 'delivery_notes', 'invoices', 'doc_lists']) {
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
