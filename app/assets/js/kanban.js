/* Tableau de tâches : ouverture des fiches, glisser-déposer entre colonnes. */
(() => {
  'use strict';
  const board = document.getElementById('kanban');
  if (!board) return;
  const editable = board.dataset.editable === '1';
  const csrf = document.querySelector('meta[name=csrf]').content;
  const dlg = document.getElementById('task-dlg');
  const form = dlg.querySelector('form');

  function openTask(t, columnId) {
    form.reset();
    form.elements.id.value = t ? t.id : '';
    form.elements.title.value = t ? t.title : '';
    form.elements.description.value = t ? t.description || '' : '';
    form.elements.column_id.value = t ? t.column_id : columnId;
    form.elements.priority.value = t ? t.priority : 'normale';
    form.elements.assignee_id.value = t && t.assignee_id ? t.assignee_id : '';
    form.elements.due_date.value = t && t.due_date ? t.due_date : '';
    form.elements.labels.value = t ? t.labels || '' : '';
    dlg.querySelector('[data-title]').textContent = t ? 'Tâche' : 'Nouvelle tâche';
    const del = dlg.querySelector('[data-delete]');
    if (del) del.hidden = !t;
    if (!editable) form.querySelectorAll('input, textarea, select').forEach((el) => { if (el.type !== 'hidden') el.disabled = true; });
    dlg.showModal();
    if (editable) setTimeout(() => form.elements.title.focus(), 30);
  }

  board.addEventListener('click', (e) => {
    const colBtn = e.target.closest('[data-col-edit]');
    if (colBtn) { openColumn(JSON.parse(colBtn.dataset.colEdit)); return; }
    const card = e.target.closest('.kcard');
    if (card && !card.classList.contains('dragging')) openTask(JSON.parse(card.dataset.task));
  });
  board.addEventListener('keydown', (e) => {
    const card = e.target.closest('.kcard');
    if (card && e.key === 'Enter') openTask(JSON.parse(card.dataset.task));
  });
  document.querySelectorAll('[data-new-task]').forEach((b) => b.addEventListener('click', () => openTask(null, b.dataset.newTask)));

  const colDlg = document.getElementById('col-dlg');
  function openColumn(c) {
    if (!colDlg) return;
    const f = colDlg.querySelector('form');
    f.elements.id.value = c.id;
    f.elements.name.value = c.name;
    f.elements.color.value = c.color || '#64748b';
    f.elements.is_done.checked = !!c.is_done;
    colDlg.querySelector('[data-left]').hidden = c.first;
    colDlg.querySelector('[data-right]').hidden = c.last;
    colDlg.querySelector('[data-del]').hidden = !c.empty;
    colDlg.showModal();
  }

  if (!editable) return;

  /* ---------- glisser-déposer ---------- */
  let dragged = null;
  board.addEventListener('dragstart', (e) => {
    const card = e.target.closest('.kcard'); if (!card) return;
    dragged = card;
    card.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', card.dataset.id);
  });
  board.addEventListener('dragend', () => {
    if (dragged) dragged.classList.remove('dragging');
    board.querySelectorAll('.over').forEach((el) => el.classList.remove('over'));
    dragged = null;
  });

  function afterElement(container, y) {
    const cards = [...container.querySelectorAll('.kcard:not(.dragging)')];
    let best = null, bestOffset = -Infinity;
    for (const c of cards) {
      const box = c.getBoundingClientRect();
      const offset = y - box.top - box.height / 2;
      if (offset < 0 && offset > bestOffset) { bestOffset = offset; best = c; }
    }
    return best;
  }

  board.addEventListener('dragover', (e) => {
    const zone = e.target.closest('.kcol[data-col]'); if (!zone || !dragged) return;
    e.preventDefault();
    const list = zone.querySelector('[data-drop]');
    board.querySelectorAll('.over').forEach((el) => el !== zone && el.classList.remove('over'));
    zone.classList.add('over');
    const after = afterElement(list, e.clientY);
    if (after) list.insertBefore(dragged, after); else list.appendChild(dragged);
  });

  board.addEventListener('drop', async (e) => {
    const zone = e.target.closest('.kcol[data-col]'); if (!zone || !dragged) return;
    e.preventDefault();
    const card = dragged;
    const list = zone.querySelector('[data-drop]');
    const index = [...list.querySelectorAll('.kcard')].indexOf(card);
    const columnId = +zone.dataset.col;
    const t = JSON.parse(card.dataset.task);
    t.column_id = columnId;
    card.dataset.task = JSON.stringify(t);
    board.querySelectorAll('.kcol[data-col]').forEach((col) => {
      col.querySelector('.count').textContent = col.querySelectorAll('.kcard').length;
    });
    try {
      const r = await fetch(board.dataset.moveUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify({ id: +card.dataset.id, column_id: columnId, index }),
      });
      const j = await r.json();
      if (!j.ok) throw new Error(j.error || 'Erreur');
    } catch (err) {
      alert('Déplacement non enregistré : ' + err.message);
      location.reload();
    }
  });
})();
