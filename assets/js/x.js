/*
 * X — assistant de la direction (page index.php?r=x). Les réponses sont calculées par l'ERP
 * (index.php?r=x&a=ask) : aucune connexion extérieure.
 */
(() => {
  'use strict';
  const root = document.querySelector('[data-x]');
  if (!root) return;
  const log = root.querySelector('[data-x-log]');
  const form = root.querySelector('[data-x-form]');
  const input = root.querySelector('[data-x-q]');
  const sendBtn = root.querySelector('[data-x-send]');
  const csrf = (document.querySelector('meta[name="csrf"]') || {}).content || '';
  const STORE = 'ftn-x-local';
  let busy = false;

  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
  const scrollDown = () => { log.scrollTop = log.scrollHeight; };
  const save = () => { try { sessionStorage.setItem(STORE, log.innerHTML); } catch (e) { /* stockage indisponible */ } };

  function add(cls, html) {
    const el = document.createElement('div');
    el.className = 'x-msg ' + cls;
    el.innerHTML = cls === 'user' ? '<div class="x-bubble">' + html + '</div>' : '<span class="x-logo sm">X</span><div class="x-bubble x-text">' + html + '</div>';
    log.appendChild(el);
    scrollDown();
    return el;
  }

  async function ask(question) {
    question = String(question || '').trim();
    if (busy || !question) return;
    busy = true;
    sendBtn.disabled = true;
    input.value = '';
    add('user', esc(question).replace(/\n/g, '<br>'));
    const bot = add('bot', '<div class="x-typing"><i></i><i></i><i></i></div>');
    try {
      const r = await fetch(root.dataset.endpoint, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify({ q: question }),
      });
      if (r.status === 401 || r.status === 419) throw new Error('Session expirée : rechargez la page et reconnectez-vous.');
      const d = await r.json();
      bot.querySelector('.x-bubble').innerHTML = d.html || '<p>Pas de réponse.</p>';
    } catch (e) {
      bot.querySelector('.x-bubble').innerHTML = '<p class="x-note err">' + esc(e.message || e) + '</p>';
    } finally {
      busy = false;
      sendBtn.disabled = false;
      scrollDown();
      save();
      input.focus();
    }
  }

  form.addEventListener('submit', (ev) => { ev.preventDefault(); ask(input.value); });
  input.addEventListener('keydown', (ev) => {
    if (ev.key === 'Enter' && !ev.shiftKey && !ev.isComposing) { ev.preventDefault(); ask(input.value); }
  });
  log.addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-x-ask]');
    if (b) ask(b.getAttribute('data-x-ask'));
  });
  const reset = root.querySelector('[data-x-new]');
  if (reset) reset.addEventListener('click', async () => {
    if (busy) return;
    try { sessionStorage.removeItem(STORE); } catch (e) { /* rien */ }
    try { await fetch(root.dataset.reset, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf } }); } catch (e) { /* rien */ }
    location.href = location.pathname + '?r=x';
  });

  try {
    const saved = sessionStorage.getItem(STORE);
    if (saved) { log.innerHTML = saved; scrollDown(); }
  } catch (e) { /* rien à restaurer */ }
  const q = root.dataset.q;
  if (q) {
    try { history.replaceState(null, '', location.pathname + '?r=x'); } catch (e) { /* rien */ }
    ask(q);
  } else {
    input.focus();
  }
})();
