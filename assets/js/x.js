/*
 * X — assistant IA de la direction (page index.php?r=x).
 * Boucle d'agent dans le navigateur avec le SDK officiel Anthropic (window.Anthropic, assets/js/vendor) :
 * Claude pose des requêtes SQL en lecture seule à l'ERP (outil « requete_sql »), puis répond.
 */
(() => {
  'use strict';
  const cfgEl = document.querySelector('[data-x-config]');
  if (!cfgEl || !window.Anthropic) return;
  const cfg = JSON.parse(cfgEl.textContent);
  const Anthropic = window.Anthropic;
  const client = new Anthropic({ apiKey: cfg.key, dangerouslyAllowBrowser: true, maxRetries: 2 });
  const csrf = (document.querySelector('meta[name="csrf"]') || {}).content || '';
  const log = document.querySelector('[data-x-log]');
  const form = document.querySelector('[data-x-form]');
  const input = document.querySelector('[data-x-q]');
  const sendBtn = document.querySelector('[data-x-send]');
  const STORE = 'ftn-x-conversation';
  const MAX_STEPS = 15;

  const TOOLS = [{
    name: 'requete_sql',
    description: 'Exécute UNE requête SQLite en lecture seule (SELECT ou WITH … SELECT, sans point-virgule) sur la base de l’ERP FitnessTN et renvoie les colonnes et au plus 200 lignes. Les montants sont en millimes.',
    input_schema: {
      type: 'object',
      properties: {
        query: { type: 'string', description: 'La requête SQLite (SELECT uniquement).' },
        but: { type: 'string', description: 'En quelques mots, ce que cette requête cherche (affiché à l’utilisateur), ex. « CA par mois ».' },
      },
      required: ['query'],
      additionalProperties: false,
    },
    eager_input_streaming: true,
  }];

  let messages = [];
  let busy = false;

  /* ---------- Affichage ---------- */

  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

  function inline(s) {
    return esc(s)
      .replace(/`([^`]+)`/g, '<code>$1</code>')
      .replace(/\*\*([^*]+)\*\*/g, '<b>$1</b>')
      .replace(/(^|[^*\w])\*([^*\n]+)\*(?!\w)/g, '$1<i>$2</i>');
  }

  /** Markdown simple et sûr : titres, listes, tableaux, blocs de code, gras, italique. */
  function md(text) {
    const lines = String(text).replace(/\r/g, '').split('\n');
    let html = '';
    let i = 0;
    while (i < lines.length) {
      const line = lines[i];
      if (/^```/.test(line)) {
        const buf = [];
        i++;
        while (i < lines.length && !/^```/.test(lines[i])) buf.push(lines[i++]);
        i++;
        html += '<pre><code>' + esc(buf.join('\n')) + '</code></pre>';
        continue;
      }
      if (/^\s*\|.*\|\s*$/.test(line) && i + 1 < lines.length && /^\s*\|?\s*:?-{2,}/.test(lines[i + 1])) {
        const cells = (l) => l.trim().replace(/^\||\|$/g, '').split('|').map((c) => c.trim());
        const head = cells(line);
        const aligns = cells(lines[i + 1]).map((c) => (/^-*:$/.test(c) ? ' class="num"' : ''));
        i += 2;
        let body = '';
        while (i < lines.length && /^\s*\|/.test(lines[i])) {
          body += '<tr>' + cells(lines[i]).map((c, k) => '<td' + (aligns[k] || (/^[-−]?[\d\s.,  ]+(DT|%)?$/.test(c) ? ' class="num"' : '')) + '>' + inline(c) + '</td>').join('') + '</tr>';
          i++;
        }
        html += '<div class="x-table"><table class="tbl"><thead><tr>' + head.map((h, k) => '<th' + (aligns[k] || '') + '>' + inline(h) + '</th>').join('') + '</tr></thead><tbody>' + body + '</tbody></table></div>';
        continue;
      }
      const h = /^(#{1,4})\s+(.*)$/.exec(line);
      if (h) { html += '<h' + (h[1].length + 2) + '>' + inline(h[2]) + '</h' + (h[1].length + 2) + '>'; i++; continue; }
      if (/^\s*([-*•]|\d+[.)])\s+/.test(line)) {
        const ordered = /^\s*\d+[.)]/.test(line);
        let items = '';
        while (i < lines.length && /^\s*([-*•]|\d+[.)])\s+/.test(lines[i])) {
          items += '<li>' + inline(lines[i].replace(/^\s*([-*•]|\d+[.)])\s+/, '')) + '</li>';
          i++;
        }
        html += ordered ? '<ol>' + items + '</ol>' : '<ul>' + items + '</ul>';
        continue;
      }
      if (line.trim() === '') { i++; continue; }
      const para = [];
      while (i < lines.length && lines[i].trim() !== '' && !/^```|^#{1,4}\s|^\s*([-*•]|\d+[.)])\s+|^\s*\|/.test(lines[i])) para.push(inline(lines[i++]));
      if (!para.length) para.push(inline(lines[i++]));
      html += '<p>' + para.join('<br>') + '</p>';
    }
    return html;
  }

  const scrollDown = () => { log.scrollTop = log.scrollHeight; };

  function hideEmpty() {
    const empty = log.querySelector('[data-x-empty]');
    if (empty) empty.hidden = true;
  }

  function addUser(text) {
    hideEmpty();
    const el = document.createElement('div');
    el.className = 'x-msg user';
    el.innerHTML = '<div class="x-bubble">' + esc(text).replace(/\n/g, '<br>') + '</div>';
    log.appendChild(el);
    scrollDown();
  }

  /** Bulle de réponse : étapes (requêtes) et segments de texte, dans l'ordre. */
  function addBot() {
    const el = document.createElement('div');
    el.className = 'x-msg bot';
    el.innerHTML = '<span class="x-logo sm">X</span><div class="x-bubble"><div class="x-typing" data-typing><i></i><i></i><i></i></div></div>';
    log.appendChild(el);
    const bubble = el.querySelector('.x-bubble');
    const typing = bubble.querySelector('[data-typing]');
    scrollDown();
    return {
      segment() {
        const seg = document.createElement('div');
        seg.className = 'x-text';
        bubble.insertBefore(seg, typing);
        let text = '';
        let queued = false;
        return {
          append(delta) {
            text += delta;
            if (queued) return;
            queued = true;
            requestAnimationFrame(() => { queued = false; seg.innerHTML = md(text); scrollDown(); });
          },
          remove() { seg.remove(); },
        };
      },
      step(label, sql) {
        const d = document.createElement('details');
        d.className = 'x-step';
        d.innerHTML = '<summary><span class="x-spin"></span>' + esc(label) + '</summary><pre><code>' + esc(sql) + '</code></pre>';
        bubble.insertBefore(d, typing);
        scrollDown();
        return (summary, ok) => {
          d.classList.add(ok ? 'ok' : 'err');
          d.querySelector('summary').innerHTML = '<span class="x-tick">' + (ok ? '✓' : '!') + '</span>' + esc(label) + ' <small>' + esc(summary) + '</small>';
        };
      },
      note(text, tone) {
        const p = document.createElement('p');
        p.className = 'x-note ' + (tone || '');
        p.textContent = text;
        bubble.insertBefore(p, typing);
        scrollDown();
      },
      done() { typing.remove(); },
    };
  }

  function save() {
    try { sessionStorage.setItem(STORE, JSON.stringify({ messages, html: log.innerHTML })); } catch (e) { /* stockage plein ou bloqué */ }
  }

  function restore() {
    try {
      const s = JSON.parse(sessionStorage.getItem(STORE) || 'null');
      if (s && Array.isArray(s.messages) && s.messages.length) {
        messages = s.messages;
        log.innerHTML = s.html;
        scrollDown();
      }
    } catch (e) { /* rien à restaurer */ }
  }

  /* ---------- Outil : requête SQL en lecture seule ---------- */

  async function runSql(query) {
    const r = await fetch(cfg.endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify({ query }),
      credentials: 'same-origin',
    });
    if (r.status === 401 || r.status === 419) throw new Error('Session expirée : rechargez la page et reconnectez-vous.');
    return r.json();
  }

  /* ---------- Boucle de l'agent ---------- */

  function errorText(err) {
    if (err instanceof Anthropic.AuthenticationError) return 'Clé API refusée : vérifiez-la dans Paramètres › Agent X.';
    if (err instanceof Anthropic.PermissionDeniedError) return 'Accès refusé par Anthropic : vérifiez les droits de la clé API.';
    if (err instanceof Anthropic.RateLimitError) return 'Trop de demandes en même temps : réessayez dans une minute.';
    if (err instanceof Anthropic.APIConnectionError) return 'Impossible de joindre Anthropic : vérifiez la connexion Internet.';
    if (err instanceof Anthropic.BadRequestError) {
      const m = String(err.message || '');
      if (/credit|billing|balance/i.test(m)) return 'Crédit Anthropic insuffisant : rechargez le compte sur console.anthropic.com (Billing).';
      return 'Requête refusée par Anthropic : ' + m;
    }
    if (err instanceof Anthropic.APIError) return 'Erreur du service Anthropic (' + (err.status || '?') + ') : réessayez dans un instant.';
    return String((err && err.message) || err);
  }

  /** Un tour du modèle, en flux. Relance (2 fois au plus) si le flux est coupé ou illisible ; les erreurs de l'API remontent. */
  async function streamTurn(bot) {
    for (let attempt = 0; ; attempt++) {
      const seg = bot.segment();
      try {
        const stream = client.beta.messages.stream({
          model: cfg.model,
          max_tokens: 64000,
          betas: ['server-side-fallback-2026-07-01'],
          fallbacks: 'default',
          cache_control: { type: 'ephemeral' },
          output_config: { effort: cfg.effort || 'medium' },
          system: cfg.system,
          tools: TOOLS,
          messages,
        });
        stream.on('text', (delta) => seg.append(delta));
        return await stream.finalMessage();
      } catch (err) {
        seg.remove();
        if (err instanceof Anthropic.APIError || attempt >= 2) throw err;
      }
    }
  }

  async function ask(question) {
    question = String(question || '').trim();
    if (busy || !question) return;
    busy = true;
    sendBtn.disabled = true;
    input.value = '';
    addUser(question);
    const before = messages.length;
    messages.push({ role: 'user', content: question });
    const bot = addBot();
    try {
      for (let step = 1; ; step++) {
        const message = await streamTurn(bot);
        if (message.stop_reason === 'refusal') {
          messages = messages.slice(0, before);
          bot.note('X ne peut pas répondre à cette demande. Reformulez-la autrement.', 'warn');
          break;
        }
        messages.push({ role: 'assistant', content: message.content });
        const uses = message.content.filter((b) => b.type === 'tool_use');
        if (message.stop_reason === 'max_tokens' && uses.length) throw new Error('Réponse trop longue : posez une question plus précise.');
        if (message.stop_reason === 'max_tokens') bot.note('(réponse coupée car trop longue)', 'warn');
        if (message.stop_reason !== 'tool_use' || !uses.length) break;
        if (step >= MAX_STEPS) {
          messages = messages.slice(0, before);
          bot.note('X a fait trop de recherches sans conclure : précisez la question (période, produit, showroom…).', 'warn');
          break;
        }
        const results = [];
        for (const u of uses) {
          const q = u.input && typeof u.input.query === 'string' ? u.input.query.trim() : '';
          if (!q) {
            results.push({ type: 'tool_result', tool_use_id: u.id, is_error: true, content: JSON.stringify({ INVALID_JSON: JSON.stringify(u.input || null) }) });
            continue;
          }
          const finish = bot.step((u.input.but && String(u.input.but)) || 'Consultation des données', q);
          let res;
          try { res = await runSql(q); } catch (e) { res = { ok: false, erreur: String(e.message || e) }; }
          finish(res.ok ? res.nb_lignes + ' ligne(s)' + (res.tronque ? ', coupé' : '') : res.erreur, !!res.ok);
          if (!res.ok && /Session expirée/.test(res.erreur || '')) throw new Error(res.erreur);
          const payload = res.ok ? { colonnes: res.colonnes, lignes: res.lignes, nb_lignes: res.nb_lignes, tronque: res.tronque } : { erreur: res.erreur };
          results.push({ type: 'tool_result', tool_use_id: u.id, content: JSON.stringify(payload), is_error: !res.ok });
        }
        messages.push({ role: 'user', content: results });
      }
    } catch (err) {
      console.error(err);
      // L'échange incomplet est retiré : la conversation reste valide pour la question suivante
      messages = messages.slice(0, before);
      bot.note(errorText(err), 'err');
    } finally {
      bot.done();
      busy = false;
      sendBtn.disabled = false;
      save();
      input.focus();
    }
  }

  /* ---------- Événements ---------- */

  form.addEventListener('submit', (ev) => { ev.preventDefault(); ask(input.value); });
  input.addEventListener('keydown', (ev) => {
    if (ev.key === 'Enter' && !ev.shiftKey && !ev.isComposing) { ev.preventDefault(); ask(input.value); }
  });
  log.addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-x-ask]');
    if (b) ask(b.getAttribute('data-x-ask'));
  });
  const reset = document.querySelector('[data-x-new]');
  if (reset) reset.addEventListener('click', () => {
    if (busy) return;
    try { sessionStorage.removeItem(STORE); } catch (e) { /* rien */ }
    location.href = location.pathname + '?r=x';
  });

  restore();
  if (cfg.q) {
    try { history.replaceState(null, '', location.pathname + '?r=x'); } catch (e) { /* rien */ }
    ask(cfg.q);
  } else {
    input.focus();
  }
})();
