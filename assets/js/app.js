/* =========================================================================
   app.js — converter, PDF, and server-capability page logic
   Depends on utils.js (Castle global).
   ========================================================================= */
(function () {
    'use strict';

    const $  = (sel, root) => (root || document).querySelector(sel);
    const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));

    const state = {
        queue: [],          // [{id, file, status, thumbUrl, results}]
        pdfImages: [],      // [{id, file, thumbUrl}]
        pdfPages: [],       // [{page, thumbDataUrl}]
        pdfPath: null,      // uploaded-pdf path token (returned from server)
    };

    let uid = 0;
    const nextId = () => ++uid;

    /* ---------- Tab switching ------------------------------------------- */

    function switchTab(name) {
        $$('.castle-nav a[data-tab]').forEach(a =>
            a.classList.toggle('active', a.dataset.tab === name));
        $$('.tab-section').forEach(s =>
            s.classList.toggle('active', s.dataset.tab === name));
        history.replaceState(null, '', '#' + name);
    }

    $$('.castle-nav a[data-tab]').forEach(a => {
        a.addEventListener('click', e => {
            e.preventDefault();
            switchTab(a.dataset.tab);
        });
    });

    const initialTab = (location.hash || '').replace('#', '') || 'convert';
    switchTab(['convert','pdf','server'].includes(initialTab) ? initialTab : 'convert');

    /* ===================================================================
       CONVERT TAB
       =================================================================== */

    const dropzone = $('#dropzone');
    const filePicker = $('#filepicker');
    const queueEl = $('#queue');
    const convertBtn = $('#convert-btn');
    const clearBtn = $('#clear-btn');
    const resultsBlock = $('#results');
    const urlImportInput = $('#url-import');
    const urlImportBtn = $('#url-import-btn');

    if (dropzone) {
        dropzone.addEventListener('click', e => {
            if (e.target.closest('button') || e.target.closest('input')) return;
            filePicker.click();
        });
        filePicker.addEventListener('change', () => {
            addFiles(Array.from(filePicker.files || []));
            filePicker.value = '';
        });

        ['dragenter', 'dragover'].forEach(evt => {
            dropzone.addEventListener(evt, e => {
                e.preventDefault(); e.stopPropagation();
                dropzone.classList.add('dragover');
            });
        });
        ['dragleave', 'drop'].forEach(evt => {
            dropzone.addEventListener(evt, e => {
                e.preventDefault(); e.stopPropagation();
                dropzone.classList.remove('dragover');
            });
        });
        dropzone.addEventListener('drop', e => {
            const files = Array.from((e.dataTransfer && e.dataTransfer.files) || []);
            addFiles(files);
        });
    }

    // Paste from clipboard (Ctrl+V anywhere on convert page)
    document.addEventListener('paste', e => {
        if (!$('#tab-convert.active')) return;
        const items = (e.clipboardData && e.clipboardData.items) || [];
        const files = [];
        for (const it of items) {
            if (it.kind === 'file') {
                const f = it.getAsFile();
                if (f) files.push(f);
            }
        }
        if (files.length) {
            addFiles(files);
            Castle.toast('Pasted ' + files.length + ' image(s) from clipboard', 'success');
        }
    });

    if (urlImportBtn) {
        urlImportBtn.addEventListener('click', async () => {
            const url = (urlImportInput.value || '').trim();
            if (!/^https?:\/\//i.test(url)) {
                Castle.toast('Enter a valid http(s) URL', 'warn');
                return;
            }
            urlImportBtn.disabled = true;
            try {
                const res = await Castle.api('fetch_url', { url });
                if (res.file) addRemoteFile(res.file);
                urlImportInput.value = '';
            } catch (err) {
                Castle.toast(err.message, 'error');
            } finally {
                urlImportBtn.disabled = false;
            }
        });
    }

    function addFiles(files) {
        const allowedExt = (Castle.capabilities.inputFormats || []).map(x => x.toLowerCase());
        files.forEach(file => {
            const ext = (file.name.split('.').pop() || '').toLowerCase();
            if (allowedExt.length && !allowedExt.includes(ext)) {
                Castle.toast('Unsupported format: .' + ext, 'error');
                return;
            }
            if (file.size > (Castle.capabilities.maxFileSize || 25 * 1024 * 1024)) {
                Castle.toast(file.name + ' exceeds max file size', 'error');
                return;
            }
            const item = {
                id: nextId(),
                file: file,
                name: file.name,
                size: file.size,
                ext: ext,
                status: 'pending',
                thumbUrl: file.type.startsWith('image/') ? URL.createObjectURL(file) : '',
                results: null,
            };
            state.queue.push(item);
        });
        renderQueue();
    }

    function addRemoteFile(info) {
        // Server-downloaded URL import returns {name,size,tmpToken,ext}
        state.queue.push({
            id: nextId(),
            remote: info.tmpToken,
            name: info.name,
            size: info.size,
            ext: info.ext,
            status: 'pending',
            thumbUrl: info.preview || '',
            results: null,
        });
        renderQueue();
    }

    function renderQueue() {
        if (!queueEl) return;
        if (!state.queue.length) {
            queueEl.innerHTML = '<div class="muted" style="padding:20px;text-align:center">Add an image above to get started.</div>';
            convertBtn.disabled = true;
            return;
        }
        convertBtn.disabled = false;
        queueEl.innerHTML = state.queue.map(item => `
            <div class="queue-item ${item.status}" data-id="${item.id}">
                <img class="thumb" src="${Castle.escapeHtml(item.thumbUrl)}" alt="" onerror="this.style.visibility='hidden'">
                <div class="meta">
                    <div class="name">${Castle.escapeHtml(item.name)}</div>
                    <div class="sub">${Castle.formatBytes(item.size)} · .${Castle.escapeHtml(item.ext)}</div>
                </div>
                <div class="status">
                    <span class="dot"></span>${statusLabel(item.status)}
                    <button class="btn btn-sm btn-ghost" data-remove="${item.id}" title="Remove" style="margin-left:8px">×</button>
                </div>
            </div>
        `).join('');
        queueEl.querySelectorAll('[data-remove]').forEach(b => {
            b.addEventListener('click', () => {
                state.queue = state.queue.filter(q => q.id !== +b.dataset.remove);
                renderQueue();
            });
        });
    }

    function statusLabel(s) {
        return ({ pending: 'Ready', working: 'Converting…', done: 'Done', error: 'Failed' })[s] || s;
    }

    if (clearBtn) {
        clearBtn.addEventListener('click', () => {
            state.queue = [];
            renderQueue();
            resultsBlock.innerHTML = '';
        });
    }

    /* ---------- Settings collection ------------------------------------ */

    function collectOptions() {
        const formats = $$('.format-pill input:checked').map(i => i.value);
        const o = {
            formats,
            quality: +$('#quality').value,
            resize_mode: $('#resize_mode').value,
            max_width:  +$('#max_width').value  || 0,
            max_height: +$('#max_height').value || 0,
            responsive_sizes: $('#responsive_sizes').checked,
            responsive_preset: $('#responsive_preset').value,
            strip_exif: $('#strip_exif').checked,
            naming_pattern: $('#naming_pattern').value || '{name}',
            rotate: +$('#rotate').value,
            flip: $('#flip').value,
            effects: $$('.effect-cb:checked').map(c => c.value),
            brightness: +$('#brightness').value,
            contrast:   +$('#contrast').value,
            watermark_text: $('#watermark_text').value.trim(),
            watermark_position: $('#watermark_position').value,
            watermark_opacity: +$('#watermark_opacity').value,
            smart_target_kb: +$('#smart_target_kb').value || 0,
        };
        return o;
    }

    /* ---------- Convert! ----------------------------------------------- */

    if (convertBtn) {
        convertBtn.addEventListener('click', async () => {
            const options = collectOptions();
            if (!options.formats.length) {
                Castle.toast('Pick at least one output format', 'warn');
                return;
            }
            convertBtn.disabled = true;
            resultsBlock.innerHTML = '';

            for (const item of state.queue) {
                if (item.status === 'done') continue;
                item.status = 'working';
                renderQueue();

                try {
                    const fd = new FormData();
                    if (item.remote) {
                        fd.append('remote', item.remote);
                    } else {
                        fd.append('file', item.file);
                    }
                    fd.append('options', JSON.stringify(options));
                    const res = await Castle.api('convert', fd);
                    if (!res.success) throw new Error((res.errors || ['Unknown error'])[0]);
                    item.status = 'done';
                    item.results = res;
                    renderResult(res);
                } catch (err) {
                    item.status = 'error';
                    Castle.toast(item.name + ': ' + err.message, 'error', 5000);
                }
                renderQueue();
            }

            convertBtn.disabled = false;
            Castle.toast('Conversion complete', 'success');
        });
    }

    function renderResult(res) {
        const o = res.original;
        const html = `
            <div class="result-card">
                <h3>
                    <span>${Castle.escapeHtml(o.name)}</span>
                    <span class="chip">${o.width}×${o.height}</span>
                    <span class="chip gold">${Castle.escapeHtml(o.size_formatted)}</span>
                </h3>
                <div class="result-outputs">
                    ${(res.results || []).map(r => `
                        <div class="output-tile">
                            <div class="ext">${Castle.escapeHtml(r.format)} · ${r.width}×${r.height}</div>
                            <div class="metrics">
                                ${Castle.escapeHtml(r.size_formatted)}
                                <span class="reduction">
                                    ${r.reduction > 0 ? '−' + r.reduction.toFixed(1) + '%' : '+' + Math.abs(r.reduction).toFixed(1) + '%'}
                                </span>
                            </div>
                            <div class="actions">
                                <a class="btn btn-sm btn-gold" href="${Castle.escapeHtml(r.url)}" download="${Castle.escapeHtml(r.filename)}">Download</a>
                                <button class="btn btn-sm btn-ghost" data-copy="${Castle.escapeHtml(absUrl(r.url))}">Copy URL</button>
                            </div>
                        </div>
                    `).join('')}
                </div>
            </div>`;
        resultsBlock.insertAdjacentHTML('beforeend', html);
        resultsBlock.querySelectorAll('[data-copy]').forEach(b => {
            b.addEventListener('click', () => Castle.copy(b.dataset.copy));
        });
    }

    function absUrl(u) {
        if (/^https?:/i.test(u)) return u;
        return new URL(u, window.location.href).href;
    }

    /* ---------- Quality slider live-readout ---------------------------- */
    const qSlider = $('#quality');
    const qVal    = $('#quality-val');
    if (qSlider && qVal) {
        qSlider.addEventListener('input', () => qVal.textContent = qSlider.value);
    }
    ['brightness', 'contrast', 'watermark_opacity'].forEach(id => {
        const s = $('#' + id);
        const v = $('#' + id + '-val');
        if (s && v) {
            s.addEventListener('input', () => v.textContent = s.value);
        }
    });

    // Format pill UI state.
    $$('.format-pill input').forEach(cb => {
        const pill = cb.closest('.format-pill');
        const refresh = () => pill.classList.toggle('checked', cb.checked);
        cb.addEventListener('change', refresh);
        refresh();
    });

    /* ===================================================================
       PDF TAB
       =================================================================== */

    let pdfMode = 'image-to-pdf';
    const pdfModeBtns = $$('.pdf-mode-switch button');
    pdfModeBtns.forEach(b => {
        b.addEventListener('click', () => {
            pdfMode = b.dataset.mode;
            pdfModeBtns.forEach(x => x.classList.toggle('active', x === b));
            $('#pdf-image-to-pdf').classList.toggle('hidden', pdfMode !== 'image-to-pdf');
            $('#pdf-to-image').classList.toggle('hidden', pdfMode !== 'pdf-to-image');
        });
    });

    /* ---------- Image → PDF ------------------------------------------ */

    const pdfDrop = $('#pdf-images-drop');
    const pdfPicker = $('#pdf-images-picker');
    const pdfList = $('#pdf-image-list');

    if (pdfDrop) {
        pdfDrop.addEventListener('click', e => {
            if (e.target.closest('button')) return;
            pdfPicker.click();
        });
        pdfPicker.addEventListener('change', () => {
            Array.from(pdfPicker.files || []).forEach(f => addPdfImage(f));
            pdfPicker.value = '';
        });
        ['dragenter', 'dragover'].forEach(evt => {
            pdfDrop.addEventListener(evt, e => { e.preventDefault(); pdfDrop.classList.add('dragover'); });
        });
        ['dragleave', 'drop'].forEach(evt => {
            pdfDrop.addEventListener(evt, e => { e.preventDefault(); pdfDrop.classList.remove('dragover'); });
        });
        pdfDrop.addEventListener('drop', e => {
            Array.from((e.dataTransfer && e.dataTransfer.files) || []).forEach(f => addPdfImage(f));
        });
    }

    function addPdfImage(file) {
        state.pdfImages.push({
            id: nextId(),
            file: file,
            name: file.name,
            thumbUrl: file.type.startsWith('image/') ? URL.createObjectURL(file) : '',
        });
        renderPdfImages();
    }

    function renderPdfImages() {
        if (!pdfList) return;
        pdfList.innerHTML = state.pdfImages.map((it, i) => `
            <div class="queue-item" draggable="true" data-idx="${i}">
                <img class="thumb" src="${Castle.escapeHtml(it.thumbUrl)}" alt="">
                <div class="meta">
                    <div class="name">Page ${i + 1} · ${Castle.escapeHtml(it.name)}</div>
                    <div class="sub">Drag to reorder</div>
                </div>
                <div class="status">
                    <button class="btn btn-sm btn-ghost" data-remove-pdf="${it.id}">×</button>
                </div>
            </div>
        `).join('');

        pdfList.querySelectorAll('[data-remove-pdf]').forEach(b => {
            b.addEventListener('click', () => {
                state.pdfImages = state.pdfImages.filter(x => x.id !== +b.dataset.removePdf);
                renderPdfImages();
            });
        });
        wireReorder(pdfList, (from, to) => {
            const moved = state.pdfImages.splice(from, 1)[0];
            state.pdfImages.splice(to, 0, moved);
            renderPdfImages();
        });

        $('#make-pdf-btn').disabled = state.pdfImages.length === 0;
    }

    function wireReorder(container, onMove) {
        let srcIdx = null;
        container.addEventListener('dragstart', e => {
            const item = e.target.closest('[data-idx]');
            if (!item) return;
            srcIdx = +item.dataset.idx;
            item.style.opacity = '0.4';
        });
        container.addEventListener('dragend', e => {
            const item = e.target.closest('[data-idx]');
            if (item) item.style.opacity = '';
        });
        container.addEventListener('dragover', e => { e.preventDefault(); });
        container.addEventListener('drop', e => {
            e.preventDefault();
            const tgt = e.target.closest('[data-idx]');
            if (!tgt || srcIdx === null) return;
            const dstIdx = +tgt.dataset.idx;
            if (srcIdx !== dstIdx) onMove(srcIdx, dstIdx);
            srcIdx = null;
        });
    }

    const makePdfBtn = $('#make-pdf-btn');
    if (makePdfBtn) {
        makePdfBtn.addEventListener('click', async () => {
            if (!state.pdfImages.length) return;
            makePdfBtn.disabled = true;
            try {
                const fd = new FormData();
                state.pdfImages.forEach(it => fd.append('files[]', it.file));
                const opts = {
                    page_size:   $('#pdf-page-size').value,
                    orientation: $('#pdf-orientation').value,
                    margin:      $('#pdf-margin').value,
                    title:       $('#pdf-title').value.trim() || 'document',
                };
                fd.append('options', JSON.stringify(opts));
                const res = await Castle.api('image_to_pdf', fd);
                if (!res.success) throw new Error((res.errors || ['PDF generation failed'])[0]);
                Castle.toast('PDF created', 'success');
                $('#pdf-result').innerHTML = `
                    <div class="result-card">
                        <h3>${Castle.escapeHtml(res.filename)}</h3>
                        <div class="row">
                            <span class="chip gold">${Castle.escapeHtml(res.size_formatted)}</span>
                            <a class="btn btn-gold" href="${Castle.escapeHtml(res.url)}" download>Download PDF</a>
                            <button class="btn btn-ghost" data-copy-pdf="${Castle.escapeHtml(absUrl(res.url))}">Copy URL</button>
                        </div>
                    </div>`;
                $('#pdf-result').querySelector('[data-copy-pdf]')
                    .addEventListener('click', e => Castle.copy(e.target.dataset.copyPdf));
            } catch (err) {
                Castle.toast(err.message, 'error', 5000);
            } finally {
                makePdfBtn.disabled = false;
            }
        });
    }

    /* ---------- PDF → images ----------------------------------------- */

    const pdfInPicker = $('#pdf-in-picker');
    const pdfInBtn    = $('#pdf-in-btn');
    const pdfExtractBtn = $('#pdf-extract-btn');

    if (pdfInBtn) {
        pdfInBtn.addEventListener('click', () => pdfInPicker.click());
        pdfInPicker.addEventListener('change', async () => {
            const file = pdfInPicker.files[0];
            if (!file) return;
            pdfInPicker.value = '';
            const fd = new FormData();
            fd.append('file', file);
            pdfInBtn.disabled = true;
            try {
                const res = await Castle.api('pdf_upload', fd);
                state.pdfPath = res.token;
                state.pdfPages = res.thumbnails || [];
                $('#pdf-page-count').textContent = res.pages + ' page(s)';
                renderPdfPages();
                $('#pdf-extract-controls').classList.remove('hidden');
            } catch (err) {
                Castle.toast(err.message, 'error', 5000);
            } finally {
                pdfInBtn.disabled = false;
            }
        });
    }

    function renderPdfPages() {
        const container = $('#pdf-page-grid');
        if (!container) return;
        if (!state.pdfPages.length) {
            container.innerHTML = '<div class="muted">No page previews (PDF has more than 10 pages — all will be extracted).</div>';
            return;
        }
        container.innerHTML = state.pdfPages.map(p => `
            <div class="pdf-page-tile" data-page="${p.page}">
                <span class="num">${p.page}</span>
                <img src="${Castle.escapeHtml(p.dataUrl)}" alt="Page ${p.page}">
            </div>
        `).join('');
        container.querySelectorAll('.pdf-page-tile').forEach(t => {
            t.addEventListener('click', () => t.classList.toggle('checked'));
        });
    }

    if (pdfExtractBtn) {
        pdfExtractBtn.addEventListener('click', async () => {
            if (!state.pdfPath) return;
            const selected = $$('.pdf-page-tile.checked').map(t => +t.dataset.page - 1);
            pdfExtractBtn.disabled = true;
            try {
                const res = await Castle.api('pdf_to_image', {
                    token: state.pdfPath,
                    pages: selected,
                    dpi:   +$('#pdf-dpi').value,
                    format: $('#pdf-out-format').value,
                    quality: +$('#pdf-out-quality').value,
                });
                $('#pdf-extract-result').innerHTML = (res.results || []).map(r => `
                    <div class="output-tile">
                        <div class="ext">Page ${r.page} · ${r.width}×${r.height}</div>
                        <div class="metrics">${Castle.escapeHtml(r.size_formatted)}</div>
                        <div class="actions">
                            <a class="btn btn-sm btn-gold" href="${Castle.escapeHtml(r.url)}" download>Download</a>
                        </div>
                    </div>
                `).join('');
                Castle.toast('Extracted ' + (res.results || []).length + ' page(s)', 'success');
            } catch (err) {
                Castle.toast(err.message, 'error', 5000);
            } finally {
                pdfExtractBtn.disabled = false;
            }
        });
    }

    /* ===================================================================
       SERVER TAB — fetch capability report
       =================================================================== */

    const serverTab = $('#tab-server');
    if (serverTab) {
        let loaded = false;
        const loadIfNeeded = async () => {
            if (loaded) return;
            try {
                const res = await Castle.api('capabilities', null, { method: 'GET' });
                renderCapabilities(res);
                loaded = true;
            } catch (err) {
                Castle.toast(err.message, 'error');
            }
        };
        $$('.castle-nav a[data-tab="server"]').forEach(a => a.addEventListener('click', loadIfNeeded));
        if (initialTab === 'server') loadIfNeeded();
    }

    function renderCapabilities(res) {
        const body = $('#cap-table tbody');
        if (!body || !res.rows) return;
        body.innerHTML = res.rows.map(r => `
            <tr>
                <td>${Castle.escapeHtml(r.label)}</td>
                <td class="mono">${Castle.escapeHtml(r.value)}</td>
                <td class="status-cell status-${Castle.escapeHtml(r.status)}">
                    ${r.status === 'ok' ? '✓ OK' : r.status === 'warn' ? '! Optional' : '× Missing'}
                    ${r.hint ? '<span class="cap-hint">' + Castle.escapeHtml(r.hint) + '</span>' : ''}
                </td>
            </tr>
        `).join('');

        const badge = (label, yes) => `
            <span class="chip ${yes ? 'green' : 'red'}">${Castle.escapeHtml(label)}: ${yes ? 'yes' : 'no'}</span>`;
        const sum = $('#cap-summary');
        if (sum) {
            sum.innerHTML = [
                badge('PDF in',  res.can_pdf_in),
                badge('PDF out', res.can_pdf_out),
                '<span class="chip gold">Input: ' + (res.input_formats || []).join(', ') + '</span>',
                '<span class="chip gold">Output: ' + (res.output_formats || []).join(', ') + '</span>',
            ].join(' ');
        }
    }

    /* ---------- Kickoff ----------------------------------------------- */
    renderQueue();
    renderPdfImages();

}());
