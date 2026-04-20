/* =========================================================================
   gallery.js — file browser logic (search / filter / sort / bulk / lightbox)
   ========================================================================= */
(function () {
    'use strict';
    const $  = (s, r) => (r || document).querySelector(s);
    const $$ = (s, r) => Array.from((r || document).querySelectorAll(s));

    const state = {
        files: [],
        selected: new Set(),
        search: '',
        format: 'all',
        sort: 'newest',
    };

    const grid = $('#gallery-grid');
    const statsEl = $('#gallery-stats');
    const searchEl = $('#gallery-search');
    const formatEl = $('#gallery-format');
    const sortEl = $('#gallery-sort');
    const bulkBar = $('#bulk-bar');

    async function loadFiles() {
        try {
            const res = await Castle.api('list', null, { method: 'GET' });
            state.files = res.files || [];
            render();
        } catch (err) {
            Castle.toast(err.message, 'error');
        }
    }

    function render() {
        let files = state.files.slice();
        if (state.search) {
            const q = state.search.toLowerCase();
            files = files.filter(f => f.filename.toLowerCase().includes(q));
        }
        if (state.format !== 'all') {
            files = files.filter(f => f.ext.toLowerCase() === state.format);
        }
        files.sort((a, b) => {
            switch (state.sort) {
                case 'newest':   return b.mtime - a.mtime;
                case 'oldest':   return a.mtime - b.mtime;
                case 'largest':  return b.size - a.size;
                case 'smallest': return a.size - b.size;
                case 'name':     return a.filename.localeCompare(b.filename);
                default: return 0;
            }
        });

        if (!files.length) {
            grid.innerHTML = `
                <div class="gallery-empty" style="grid-column:1/-1">
                    <h3>The gallery is empty</h3>
                    <p>Converted images will appear here. Head back to the Convert tab to add some.</p>
                    <a href="index.php" class="btn btn-primary">Open converter</a>
                </div>`;
            statsEl.innerHTML = '';
            updateBulkBar();
            return;
        }

        grid.innerHTML = files.map(f => {
            const isSel = state.selected.has(f.filename);
            return `
                <div class="gallery-card ${isSel ? 'selected' : ''}" data-name="${Castle.escapeHtml(f.filename)}">
                    <div class="thumb-wrap">
                        <span class="select-box">${isSel ? '✓' : ''}</span>
                        <span class="ext-tag">${Castle.escapeHtml(f.ext)}</span>
                        <img src="${Castle.escapeHtml(f.url)}" alt="" loading="lazy">
                    </div>
                    <div class="meta">
                        <div class="name" title="${Castle.escapeHtml(f.filename)}">${Castle.escapeHtml(f.filename)}</div>
                        <div class="info">
                            <span>${f.width}×${f.height}</span>
                            <span>${Castle.escapeHtml(f.size_formatted)}</span>
                        </div>
                        <div class="actions">
                            <a class="btn btn-sm btn-gold" href="${Castle.escapeHtml(f.url)}" download="${Castle.escapeHtml(f.filename)}">Download</a>
                            <button class="btn btn-sm btn-ghost" data-copy="${Castle.escapeHtml(absUrl(f.url))}">Copy</button>
                            <button class="btn btn-sm btn-danger" data-delete="${Castle.escapeHtml(f.filename)}">Delete</button>
                        </div>
                    </div>
                </div>`;
        }).join('');

        // Card click handlers
        grid.querySelectorAll('.gallery-card').forEach(card => {
            const selectBox = card.querySelector('.select-box');
            const thumb = card.querySelector('.thumb-wrap img');

            selectBox.addEventListener('click', e => {
                e.stopPropagation();
                toggleSelect(card.dataset.name);
            });
            thumb.addEventListener('click', () => openLightbox(card.dataset.name, files));

            const copyBtn = card.querySelector('[data-copy]');
            if (copyBtn) copyBtn.addEventListener('click', e => {
                e.stopPropagation();
                Castle.copy(copyBtn.dataset.copy);
            });
            const delBtn = card.querySelector('[data-delete]');
            if (delBtn) delBtn.addEventListener('click', e => {
                e.stopPropagation();
                confirmDelete([delBtn.dataset.delete]);
            });
        });

        const totalSize = state.files.reduce((a, f) => a + f.size, 0);
        statsEl.innerHTML = `
            <span>Total: <strong>${files.length}</strong></span>
            <span>Size: <strong>${Castle.formatBytes(totalSize)}</strong></span>
            <span>Selected: <strong>${state.selected.size}</strong></span>`;
        updateBulkBar();
    }

    function absUrl(u) {
        if (/^https?:/i.test(u)) return u;
        return new URL(u, location.href).href;
    }

    function toggleSelect(name) {
        if (state.selected.has(name)) state.selected.delete(name);
        else state.selected.add(name);
        render();
    }

    function updateBulkBar() {
        if (!bulkBar) return;
        bulkBar.classList.toggle('active', state.selected.size > 0);
        $('#bulk-count').textContent = state.selected.size;
    }

    searchEl.addEventListener('input', () => { state.search = searchEl.value; render(); });
    formatEl.addEventListener('change', () => { state.format = formatEl.value; render(); });
    sortEl.addEventListener('change', () => { state.sort = sortEl.value; render(); });

    /* ---------- Bulk actions ----------------------------------------- */
    $('#bulk-clear').addEventListener('click', () => { state.selected.clear(); render(); });
    $('#bulk-download').addEventListener('click', async () => {
        if (!state.selected.size) return;
        const files = Array.from(state.selected);
        // Use POST form since GET URL may be too long
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'api.php?action=zip';
        const csrf = document.createElement('input');
        csrf.type = 'hidden'; csrf.name = '_csrf'; csrf.value = Castle.csrf;
        form.appendChild(csrf);
        const fin = document.createElement('input');
        fin.type = 'hidden'; fin.name = 'files'; fin.value = JSON.stringify(files);
        form.appendChild(fin);
        document.body.appendChild(form);
        form.submit();
        form.remove();
    });
    $('#bulk-delete').addEventListener('click', () => {
        if (!state.selected.size) return;
        confirmDelete(Array.from(state.selected));
    });

    /* ---------- Delete confirmation modal ---------------------------- */
    function confirmDelete(names) {
        const modal = document.createElement('div');
        modal.className = 'modal-backdrop';
        modal.innerHTML = `
            <div class="modal">
                <h3>Delete ${names.length} file${names.length > 1 ? 's' : ''}?</h3>
                <p class="muted">This cannot be undone.</p>
                <div class="buttons">
                    <button class="btn btn-ghost" data-cancel>Cancel</button>
                    <button class="btn btn-danger" data-confirm>Delete</button>
                </div>
            </div>`;
        document.body.appendChild(modal);
        modal.querySelector('[data-cancel]').addEventListener('click', () => modal.remove());
        modal.querySelector('[data-confirm]').addEventListener('click', async () => {
            modal.remove();
            try {
                await Castle.api('delete', { files: names });
                state.files = state.files.filter(f => !names.includes(f.filename));
                names.forEach(n => state.selected.delete(n));
                render();
                Castle.toast('Deleted ' + names.length + ' file(s)', 'success');
            } catch (err) {
                Castle.toast(err.message, 'error');
            }
        });
    }

    /* ---------- Lightbox -------------------------------------------- */
    function openLightbox(name, files) {
        let idx = files.findIndex(f => f.filename === name);
        if (idx < 0) return;
        const lb = document.createElement('div');
        lb.className = 'lightbox';
        lb.innerHTML = `
            <button class="close">×</button>
            <button class="nav prev">‹</button>
            <img src="${Castle.escapeHtml(files[idx].url)}" alt="">
            <button class="nav next">›</button>`;
        document.body.appendChild(lb);

        const img = lb.querySelector('img');
        const close = () => { lb.remove(); document.removeEventListener('keydown', handler); };
        const show = d => {
            idx = (idx + d + files.length) % files.length;
            img.src = files[idx].url;
        };
        const handler = e => {
            if (e.key === 'Escape') close();
            if (e.key === 'ArrowRight') show(1);
            if (e.key === 'ArrowLeft') show(-1);
        };
        lb.querySelector('.close').addEventListener('click', close);
        lb.querySelector('.prev').addEventListener('click', () => show(-1));
        lb.querySelector('.next').addEventListener('click', () => show(1));
        lb.addEventListener('click', e => { if (e.target === lb) close(); });
        document.addEventListener('keydown', handler);
    }

    loadFiles();
}());
