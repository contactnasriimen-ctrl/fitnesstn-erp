/* Caisse FitnessTN : catalogue, panier, encaissement multi-modes, livraison. */
(() => {
  'use strict';
  const D = JSON.parse(document.getElementById('pos-data').textContent);
  const $ = (s, el = document) => el.querySelector(s);
  const csrf = document.querySelector('meta[name=csrf]').content;
  const fmt = (m) => (m / 1000).toLocaleString('fr-FR', { minimumFractionDigits: 3, maximumFractionDigits: 3 }) + ' DT';
  const toIn = (m) => (m == null ? '' : (m / 1000).toFixed(3).replace('.', ',').replace(/,?0+$/, '') || '0');
  const dec = (m) => (m / 1000).toFixed(3);
  const parse = (v) => {
    let s = String(v ?? '').replace(/[\s  ]|dt/gi, '');
    if (!s) return null;
    if (s.includes(',') && s.includes('.')) s = s.replace(/\./g, '');
    const n = Number(s.replace(',', '.'));
    return Number.isFinite(n) ? Math.round(n * 1000) : null;
  };
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
  const norm = (s) => String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
  const initials = (n) => String(n).split(/\s+/).filter((w) => /\p{L}/u.test(w)).slice(0, 2).map((w) => w[0].toUpperCase()).join('') || '?';

  const byId = new Map(D.products.map((p) => [p.id, p]));
  D.products.forEach((p) => (p._s = norm([p.n, p.b, p.sku, p.bc, p.f].join(' '))));

  /* ---------- état (sauvegardé localement en cas de rechargement) ---------- */
  const KEY = 'ftn-pos-' + D.loc.id;
  const blank = () => ({ lines: [], discount: '', client: { id: null, name: '', phone: '', gov: '', city: '', address: '' }, source: '', campaign: '' });
  let S = blank();
  try { const s = JSON.parse(localStorage.getItem(KEY) || 'null'); if (s && Array.isArray(s.lines)) S = Object.assign(blank(), s); } catch (e) { /* stockage indisponible */ }
  const save = () => { try { localStorage.setItem(KEY, JSON.stringify(S)); } catch (e) { /* ignoré */ } };

  /* ---------- catalogue ---------- */
  let fam = '';
  const grid = $('#pos-grid');
  const qInput = $('#pos-q');
  const famsEl = $('#pos-fams');

  famsEl.innerHTML = ['', ...D.families].map((f) => `<button type="button" class="chip${f === fam ? ' on' : ''}" data-f="${esc(f)}">${esc(f || 'Tous')}</button>`).join('');
  famsEl.addEventListener('click', (e) => {
    const b = e.target.closest('.chip'); if (!b) return;
    fam = b.dataset.f;
    famsEl.querySelectorAll('.chip').forEach((c) => c.classList.toggle('on', c === b));
    renderGrid();
  });

  function matches() {
    const words = norm(qInput.value).split(/\s+/).filter(Boolean);
    return D.products.filter((p) => (!fam || p.f === fam) && words.every((w) => p._s.includes(w)));
  }

  function stockBadge(p) {
    if (p.q > 0) return `<span class="pstock ${p.q <= 1 ? 'low' : 'ok'}">Stock ${p.q}</span>`;
    if (p.qt > 0) return `<span class="pstock low">Autres sites : ${p.qt}</span>`;
    return '<span class="pstock out">Stock 0</span>';
  }

  function renderGrid() {
    const list = matches();
    if (!list.length) { grid.innerHTML = '<p class="muted pad">Aucun produit trouvé.</p>'; return; }
    grid.innerHTML = list.map((p) => {
      const img = p.img ? `<img loading="lazy" src="${esc(p.img)}" alt="" onerror="this.parentNode.classList.add('ph');this.parentNode.textContent='${esc(initials(p.n))}'">` : esc(initials(p.n));
      const price = p.p > 0
        ? `<b>${fmt(p.p)}</b>${p.r > p.p ? `<s>${fmt(p.r)}</s>` : ''}`
        : '<b class="quote">Sur devis</b>';
      return `<button type="button" class="pcard" data-id="${p.id}"><span class="pimg${p.img ? '' : ' ph'}">${img}</span>`
        + `<span class="pname">${esc(p.n)}</span><span class="pprice">${price}</span>${stockBadge(p)}</button>`;
    }).join('');
  }
  grid.addEventListener('click', (e) => { const c = e.target.closest('.pcard'); if (c) addProduct(+c.dataset.id); });

  qInput.addEventListener('input', renderGrid);
  qInput.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') { qInput.value = ''; renderGrid(); }
    if (e.key !== 'Enter') return;
    e.preventDefault();
    const v = norm(qInput.value.trim());
    if (!v) return;
    const exact = D.products.find((p) => (p.bc && norm(p.bc) === v) || (p.sku && norm(p.sku) === v));
    const list = exact ? [exact] : matches();
    if (list.length === 1) { addProduct(list[0].id); qInput.value = ''; renderGrid(); }
  });

  /* ---------- panier ---------- */
  const linesEl = $('#cart-lines');

  function addProduct(id) {
    const p = byId.get(id); if (!p) return;
    const ex = S.lines.find((l) => l.pid === id);
    if (ex) ex.qty = Math.min(999, ex.qty + 1);
    else S.lines.push({ k: 'p' + id, pid: id, label: p.n, qty: 1, unit: p.p, list: p.p, vat: D.vat });
    renderCart();
    if (!ex && p.p === 0) setTimeout(() => linesEl.querySelector(`[data-k="p${id}"] .l-price`)?.focus(), 30);
  }

  function addFree(label, unit, qty, vat, fixed) {
    S.lines.push({ k: 'f' + Date.now() + Math.random().toString(36).slice(2, 6), pid: null, label, qty, unit, list: unit, vat, fixed: !!fixed });
    renderCart();
  }

  function totals() {
    let sub = 0, cat = 0;
    for (const l of S.lines) { sub += l.unit * l.qty; cat += (l.list || l.unit) * l.qty; }
    const disc = Math.max(0, Math.min(parse(S.discount) || 0, sub));
    const total = sub - disc;
    return { sub, disc, total, cat };
  }

  function renderCart() {
    if (!S.lines.length) {
      linesEl.innerHTML = '<div class="cart-empty">Touchez un produit pour l’ajouter au panier.</div>';
    } else {
      linesEl.innerHTML = S.lines.map((l) => {
        const p = l.pid ? byId.get(l.pid) : null;
        const notes = [];
        if (p && l.qty > p.q) notes.push(`<span class="warn">Stock showroom : ${p.q}${p.qt > p.q ? ` · autres sites : ${p.qt - p.q}` : ''}</span>`);
        if (l.list > 0 && l.unit < l.list) notes.push(`<span class="ok">Remise ${Math.round((1 - l.unit / l.list) * 1000) / 10} % (catalogue ${fmt(l.list)})</span>`);
        if (l.unit === 0) notes.push('<span class="warn">Saisissez le prix</span>');
        if (p && p.w) notes.push(`<span>Garantie ${p.w % 12 === 0 ? p.w / 12 + ' an' + (p.w > 12 ? 's' : '') : p.w + ' mois'}</span>`);
        return `<div class="line" data-k="${esc(l.k)}">
          <div class="l-top"><span class="l-name">${esc(l.label)}</span><button type="button" class="icon-btn xs l-del" title="Retirer">×</button></div>
          <div class="l-bot">
            <div class="qty"><button type="button" data-q="-1" aria-label="Moins">−</button><input class="l-qty" value="${l.qty}" inputmode="numeric" aria-label="Quantité"${l.fixed ? ' readonly' : ''}><button type="button" data-q="1" aria-label="Plus">+</button></div>
            <input class="l-price" value="${esc(toIn(l.unit))}" inputmode="decimal" title="Prix unitaire TTC" aria-label="Prix unitaire"${l.fixed ? ' readonly' : ''}>
            <b class="l-total">${fmt(l.unit * l.qty)}</b>
          </div>${notes.length ? `<div class="l-notes">${notes.join('')}</div>` : ''}</div>`;
      }).join('');
    }
    renderTotals();
    save();
  }

  function renderTotals() {
    const t = totals();
    $('#t-sub').textContent = fmt(t.sub);
    $('#t-total').textContent = fmt(t.total);
    const saved = t.cat - t.total;
    const pctOff = t.cat > 0 ? (saved / t.cat) * 100 : 0;
    const over = D.maxDiscount != null && pctOff > D.maxDiscount + 0.001;
    const saveEl = $('#t-save');
    saveEl.className = 'hint' + (over ? ' err' : '');
    saveEl.textContent = saved > 0
      ? (over ? `Remise de ${pctOff.toFixed(1).replace('.', ',')} % : maximum autorisé ${D.maxDiscount} %.` : `Économie client : ${fmt(saved)} (−${pctOff.toFixed(1).replace('.', ',')} %)`)
      : '';
    const missingPrice = S.lines.some((l) => l.unit === 0 && l.list === 0);
    const btn = $('#btn-pay');
    btn.disabled = !S.lines.length || over || missingPrice;
    btn.lastChild.textContent = S.lines.length ? 'Encaisser ' + fmt(t.total) : 'Encaisser';
  }

  linesEl.addEventListener('click', (e) => {
    const lineEl = e.target.closest('.line'); if (!lineEl) return;
    const l = S.lines.find((x) => x.k === lineEl.dataset.k); if (!l) return;
    if (e.target.closest('.l-del')) { S.lines = S.lines.filter((x) => x !== l); renderCart(); return; }
    const qb = e.target.closest('[data-q]');
    if (qb && !l.fixed) { l.qty = Math.max(1, Math.min(999, l.qty + +qb.dataset.q)); renderCart(); }
  });
  linesEl.addEventListener('change', (e) => {
    const lineEl = e.target.closest('.line'); if (!lineEl) return;
    const l = S.lines.find((x) => x.k === lineEl.dataset.k); if (!l) return;
    if (e.target.classList.contains('l-qty')) { const n = parseInt(e.target.value, 10); if (n >= 1 && n <= 999) l.qty = n; }
    if (e.target.classList.contains('l-price')) { const v = parse(e.target.value); if (v != null && v >= 0) { l.unit = v; if (!l.list) l.list = 0; } }
    renderCart();
  });

  const discEl = $('#t-disc');
  discEl.value = S.discount || '';
  discEl.addEventListener('input', () => { S.discount = discEl.value; renderTotals(); save(); });

  $('#cart-clear').addEventListener('click', () => {
    if (S.lines.length && !confirm('Vider le panier ?')) return;
    S = blank(); discEl.value = ''; renderClient(); renderCart(); renderMeta();
  });
  $('#add-timbre').addEventListener('click', () => {
    if (S.lines.some((l) => l.label === 'Timbre fiscal')) return;
    addFree('Timbre fiscal', D.timbre, 1, 0, true);
  });

  /* ---------- ligne libre ---------- */
  const freeDlg = $('#free-dlg');
  $('#free-form').addEventListener('submit', (e) => {
    if (e.submitter && e.submitter.value === 'cancel') return;
    const label = $('#f-label').value.trim();
    const unit = parse($('#f-price').value);
    const qty = Math.max(1, parseInt($('#f-qty').value, 10) || 1);
    if (!label || unit == null || unit < 0) { e.preventDefault(); return; }
    addFree(label, unit, qty, +$('#f-vat').value);
    $('#f-label').value = ''; $('#f-price').value = ''; $('#f-qty').value = 1;
  });
  freeDlg.addEventListener('close', () => qInput.focus());

  /* ---------- client ---------- */
  const phoneEl = $('#cl-phone'), nameEl = $('#cl-name'), sugEl = $('#cl-suggest');
  const govEl = $('#cl-gov'), cityEl = $('#cl-city'), addrEl = $('#cl-address');
  let sugTimer = null;

  function renderClient() {
    const c = S.client;
    const chip = $('#client-chip');
    $('#client-pick').hidden = !!c.id;
    chip.hidden = !c.id;
    if (c.id) chip.innerHTML = `<span class="avatar sm">${esc(initials(c.name))}</span><span><b>${esc(c.name)}</b><small>${esc(c.phone || '')}${c.city ? ' · ' + esc(c.city) : ''}</small></span><button type="button" class="icon-btn xs" title="Changer de client">×</button>`;
    phoneEl.value = c.id ? '' : c.phone; nameEl.value = c.id ? '' : c.name;
    govEl.value = c.gov || ''; cityEl.value = c.city || ''; addrEl.value = c.address || '';
  }
  $('#client-chip').addEventListener('click', (e) => { if (e.target.closest('button')) { S.client = blank().client; renderClient(); save(); phoneEl.focus(); } });

  function onClientInput() {
    S.client.phone = phoneEl.value.trim(); S.client.name = nameEl.value.trim(); save();
    clearTimeout(sugTimer);
    const term = (document.activeElement === nameEl ? nameEl.value : phoneEl.value).trim();
    if (term.length < 2) { sugEl.hidden = true; return; }
    sugTimer = setTimeout(async () => {
      try {
        const r = await fetch(D.urls.clients + '&q=' + encodeURIComponent(term), { headers: { Accept: 'application/json' } });
        const j = await r.json();
        if (!j.clients || !j.clients.length) { sugEl.hidden = true; return; }
        sugEl.innerHTML = j.clients.map((c) => `<button type="button" data-c='${esc(JSON.stringify(c))}'><b>${esc(c.name)}</b><small>${esc(c.phone || '')}${c.city ? ' · ' + esc(c.city) : ''}</small></button>`).join('');
        sugEl.hidden = false;
      } catch (e) { sugEl.hidden = true; }
    }, 220);
  }
  phoneEl.addEventListener('input', onClientInput);
  nameEl.addEventListener('input', onClientInput);
  sugEl.addEventListener('click', (e) => {
    const b = e.target.closest('button'); if (!b) return;
    const c = JSON.parse(b.dataset.c);
    S.client = { id: c.id, name: c.name, phone: c.phone || '', gov: c.gouvernorat || '', city: c.city || '', address: c.address || '' };
    sugEl.hidden = true; renderClient(); save();
  });
  document.addEventListener('click', (e) => { if (!e.target.closest('.client-pick')) sugEl.hidden = true; });
  [govEl, cityEl, addrEl].forEach((el) => el.addEventListener('change', () => {
    S.client.gov = govEl.value; S.client.city = cityEl.value.trim(); S.client.address = addrEl.value.trim(); save();
  }));

  /* ---------- source & campagne ---------- */
  const srcEl = $('#sale-source'), cmpEl = $('#sale-campaign');
  srcEl.insertAdjacentHTML('beforeend', Object.entries(D.sources).map(([k, v]) => `<option value="${esc(k)}">${esc(v)}</option>`).join(''));
  cmpEl.insertAdjacentHTML('beforeend', D.campaigns.map((c) => `<option value="${c.id}">${esc(c.name)}</option>`).join(''));
  cmpEl.hidden = !D.campaigns.length;
  function renderMeta() { srcEl.value = S.source || ''; cmpEl.value = S.campaign || ''; }
  srcEl.addEventListener('change', () => { S.source = srcEl.value; save(); });
  cmpEl.addEventListener('change', () => { S.campaign = cmpEl.value; save(); });

  /* ---------- encaissement ---------- */
  const payDlg = $('#pay-dlg');
  let rows = [];
  let codTouched = false;
  const methodsEl = $('#p-methods'), rowsEl = $('#p-rows');
  methodsEl.innerHTML = Object.entries(D.methods).map(([k, v]) => `<button type="button" class="chip" data-m="${k}">${esc(v.replace(/ \(.*\)$/, ''))}</button>`).join('');
  const carrierEl = $('#d-carrier');
  carrierEl.innerHTML = D.carriers.map((c) => `<option value="${c.id}" data-fee="${c.fee}">${esc(c.name)}</option>`).join('');

  function payState() {
    const t = totals();
    const paid = rows.reduce((s, r) => s + (parse(r.amount) || 0), 0);
    return { total: t.total, paid, rest: t.total - paid };
  }

  function renderRows() {
    rowsEl.innerHTML = rows.map((r, i) => `<div class="pay-row" data-i="${i}">
      <span class="m">${esc(D.methods[r.method])}</span>
      <input class="amt" value="${esc(r.amount)}" inputmode="decimal" aria-label="Montant">
      <input class="ref" value="${esc(r.reference || '')}" placeholder="${r.method === 'cheque' ? 'N° de chèque, banque' : r.method === 'especes' ? '' : 'Référence'}"${r.method === 'especes' ? ' hidden' : ''}>
      <button type="button" class="icon-btn xs" title="Retirer">×</button></div>`).join('');
    updatePay();
  }

  function updatePay() {
    const st = payState();
    $('#p-total').textContent = fmt(st.total);
    const cashRow = rows.find((r) => r.method === 'especes');
    $('#p-cash').hidden = !cashRow;
    if (cashRow) {
      const given = parse($('#p-given').value);
      const due = parse(cashRow.amount) || 0;
      $('#p-change').textContent = given != null && given >= due ? fmt(given - due) : '—';
    }
    const restEl = $('#p-rest');
    if (st.rest < 0) { restEl.className = 'pay-rest err'; restEl.textContent = `Les paiements dépassent le total de ${fmt(-st.rest)}.`; }
    else if (st.rest > 0) { restEl.className = 'pay-rest warn'; restEl.textContent = `Reste à payer : ${fmt(st.rest)} — facilité de paiement ou paiement à la livraison (nom et téléphone du client obligatoires).`; }
    else { restEl.className = 'pay-rest ok'; restEl.textContent = 'Montant complet.'; }
    if (!codTouched) $('#d-cod').value = toIn(Math.max(0, st.rest));
  }

  methodsEl.addEventListener('click', (e) => {
    const b = e.target.closest('[data-m]'); if (!b) return;
    const st = payState();
    const ex = rows.find((r) => r.method === b.dataset.m);
    if (ex) { if (st.rest > 0) ex.amount = toIn((parse(ex.amount) || 0) + st.rest); }
    else rows.push({ method: b.dataset.m, amount: toIn(Math.max(0, st.rest)), reference: '' });
    renderRows();
  });
  rowsEl.addEventListener('input', (e) => {
    const rowEl = e.target.closest('.pay-row'); if (!rowEl) return;
    const r = rows[+rowEl.dataset.i];
    if (e.target.classList.contains('amt')) r.amount = e.target.value;
    if (e.target.classList.contains('ref')) r.reference = e.target.value;
    updatePay();
  });
  rowsEl.addEventListener('click', (e) => {
    if (!e.target.closest('button')) return;
    rows.splice(+e.target.closest('.pay-row').dataset.i, 1); renderRows();
  });
  $('#p-given').addEventListener('input', updatePay);
  $('#d-cod').addEventListener('input', () => { codTouched = true; });
  $('#d-on').addEventListener('change', (e) => { $('#d-box').hidden = !e.target.checked; });
  carrierEl.addEventListener('change', () => {
    const fee = +carrierEl.selectedOptions[0]?.dataset.fee || 0;
    if (fee) $('#d-fee').value = toIn(fee);
  });

  $('#btn-pay').addEventListener('click', () => {
    if (!S.lines.length) return;
    rows = [{ method: 'especes', amount: toIn(totals().total), reference: '' }];
    codTouched = false;
    $('#p-given').value = '';
    $('#p-error').hidden = true;
    $('#p-note').value = '';
    $('#d-on').checked = false; $('#d-box').hidden = true;
    $('#d-gov').value = S.client.gov || ''; $('#d-city').value = S.client.city || ''; $('#d-address').value = S.client.address || '';
    $('#d-fee').value = ''; $('#d-date').value = '';
    renderRows();
    payDlg.showModal();
    setTimeout(() => rowsEl.querySelector('.amt')?.select(), 50);
  });

  let busy = false;
  $('#pay-form').addEventListener('submit', async (e) => {
    if (e.submitter && e.submitter.value === 'cancel') return;
    e.preventDefault();
    if (busy) return;
    const errEl = $('#p-error');
    const st = payState();
    if (st.rest < 0) { errEl.textContent = 'Les paiements dépassent le total.'; errEl.hidden = false; return; }
    const delivery = $('#d-on').checked;
    const payload = {
      location_id: D.loc.id,
      items: S.lines.map((l) => ({ product_id: l.pid, label: l.label, qty: l.qty, unit_price: dec(l.unit), vat_rate: l.vat })),
      discount: dec(totals().disc),
      client: { id: S.client.id, name: S.client.name, phone: S.client.phone, gouvernorat: S.client.gov, city: S.client.city, address: S.client.address },
      source: S.source, campaign_id: S.campaign || null,
      payments: rows.map((r) => ({ method: r.method, amount: dec(parse(r.amount) || 0), reference: r.reference || '' })),
      note: $('#p-note').value.trim(),
      delivery: delivery ? {
        enabled: true, carrier_id: carrierEl.value, planned_date: $('#d-date').value, gouvernorat: $('#d-gov').value,
        city: $('#d-city').value, address: $('#d-address').value, fee: $('#d-fee').value, cod_amount: $('#d-cod').value,
      } : null,
    };
    busy = true; $('#p-submit').disabled = true; errEl.hidden = true;
    try {
      const r = await fetch(D.urls.checkout, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify(payload),
      });
      const j = await r.json().catch(() => ({ ok: false, error: 'Réponse invalide du serveur.' }));
      if (!j.ok) throw new Error(j.error || 'Erreur');
      const cashRow = rows.find((x) => x.method === 'especes');
      const given = parse($('#p-given').value);
      const change = cashRow && given != null ? given - (parse(cashRow.amount) || 0) : 0;
      S.lines.forEach((l) => { const p = l.pid && byId.get(l.pid); if (p) { p.q -= l.qty; p.qt -= l.qty; } });
      payDlg.close();
      $('#done-title').textContent = 'Vente ' + j.number + ' enregistrée';
      $('#done-info').textContent = `Total ${fmt(st.total)} · encaissé ${fmt(st.paid)}${st.rest > 0 ? ' · reste ' + fmt(st.rest) : ''}${delivery ? ' · livraison créée' : ''}`;
      const ch = $('#done-change');
      ch.hidden = !(change > 0); ch.textContent = 'Monnaie à rendre : ' + fmt(change);
      $('#done-ticket').href = j.ticket + '&print=1';
      $('#done-invoice').href = j.invoice;
      S = blank(); discEl.value = ''; renderClient(); renderMeta(); renderCart(); renderGrid();
      $('#done-dlg').showModal();
    } catch (err) {
      errEl.textContent = err.message; errEl.hidden = false;
    } finally {
      busy = false; $('#p-submit').disabled = false;
    }
  });

  $('#done-new').addEventListener('click', () => { $('#done-dlg').close(); qInput.focus(); });

  renderGrid(); renderClient(); renderMeta(); renderCart();
})();
