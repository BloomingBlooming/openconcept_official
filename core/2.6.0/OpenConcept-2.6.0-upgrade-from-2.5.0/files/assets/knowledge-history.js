(() => {
    'use strict';
    const boot = JSON.parse(document.getElementById('knowledge-boot').textContent);
    const t = key => boot.labels[key] || key;
    const form = document.getElementById('knowledge-search');
    const status = document.getElementById('knowledge-status');
    const results = document.getElementById('knowledge-results');
    const detail = document.getElementById('knowledge-record');
    const more = document.getElementById('knowledge-more');
    let nextOffset = null;
    let searchGeneration = 0;
    let recordGeneration = 0;
    const element = (tag, value, className) => {
        const node = document.createElement(tag);
        if (value !== undefined) node.textContent = String(value);
        if (className) node.className = className;
        return node;
    };
    const filters = () => Object.fromEntries(new FormData(form).entries());
    const setStatus = (message, error = false) => { status.textContent = message; status.className = error ? 'error' : ''; };
    const button = (label, handler) => { const node = element('button', label); node.type = 'button'; node.addEventListener('click', handler); return node; };
    async function request(action, body, params = {}) {
        const url = new URL('api.php', location.href);
        url.search = new URLSearchParams({ action, ...params }).toString();
        const headers = { 'Accept': 'application/json' };
        const options = { method: body === undefined ? 'GET' : 'POST', credentials: 'same-origin', headers };
        if (body !== undefined) {
            headers['Content-Type'] = 'application/json'; headers['X-CSRF-Token'] = boot.csrf;
            options.body = JSON.stringify(body);
            if (boot.waf.waf_compatibility_enabled) {
                if (!crypto.subtle) throw new Error(t('failed'));
                const raw = Uint8Array.from(atob(boot.waf.waf_compatibility_key), char => char.charCodeAt(0));
                const key = await crypto.subtle.importKey('raw', raw, 'AES-GCM', false, ['encrypt']);
                const iv = crypto.getRandomValues(new Uint8Array(12));
                const additionalData = new TextEncoder().encode(`OpenConcept:aes-256-gcm-v1:POST:${action}`);
                const sealed = new Uint8Array(await crypto.subtle.encrypt({ name: 'AES-GCM', iv, additionalData }, key, new TextEncoder().encode(options.body)));
                const packet = new Uint8Array(iv.length + sealed.length); packet.set(iv); packet.set(sealed, iv.length);
                let binary = ''; for (let offset = 0; offset < packet.length; offset += 8192) binary += String.fromCharCode(...packet.subarray(offset, offset + 8192));
                options.body = JSON.stringify({ payload: btoa(binary) }); headers['X-OpenConcept-Body-Encoding'] = 'aes-256-gcm-v1';
            }
        }
        const response = await fetch(url, options);
        let data; try { data = await response.json(); } catch { throw new Error(t('failed')); }
        if (!response.ok) throw new Error(data.error || t('failed'));
        return data;
    }
    async function busy(node, operation) {
        if (node.disabled) return;
        node.disabled = true; setStatus(t('busy'));
        try { await operation(); } catch (error) { setStatus(error.message || t('failed'), true); }
        finally { node.disabled = false; }
    }
    function recordLink(reference, label) {
        const link = element('a', label);
        link.href = 'history.php?record=' + encodeURIComponent(reference);
        link.addEventListener('click', event => { event.preventDefault(); openRecord(reference); });
        return link;
    }
    async function search(append = false) {
        const generation = ++searchGeneration;
        try {
            const data = await request('knowledge-search', undefined, { ...filters(), offset: append ? nextOffset || 0 : 0 });
            if (generation !== searchGeneration) return;
            if (!append) results.replaceChildren();
            for (const row of data.items) {
                const card = element('article', undefined, 'knowledge-card');
                card.append(element('span', t(row.type), 'knowledge-type'), recordLink(row.reference, (row.type === 'relation' ? t(row.kind) : row.title) || t(row.type)),
                    element('p', row.excerpt), element('small', `${t('sourceTime')}: ${row.occurred_at || t('unknown')} · ${t('captureTime')}: ${row.recorded_at || t('unknown')}`));
                results.append(card);
            }
            if (!data.items.length && !append) results.append(element('p', t('empty')));
            nextOffset = data.next_offset; more.hidden = nextOffset === null;
            setStatus(data.truncated ? t('limited') : '');
        } catch (error) { if (generation === searchGeneration) setStatus(error.message, true); }
    }
    async function openRecord(reference) {
        const generation = ++recordGeneration;
        try {
            const row = await request('knowledge-record', undefined, { record: reference });
            if (generation !== recordGeneration) return;
            detail.replaceChildren(element('span', t(row.type), 'knowledge-type'), element('h2', (row.type === 'relation' ? t(row.kind) : row.title) || t(row.type)),
                element('p', t('sourceWarning'), 'knowledge-note'),
                element('p', `${t('sourceTime')}: ${row.occurred_at || t('unknown')}\n${t('captureTime')}: ${row.recorded_at || t('unknown')}`, 'knowledge-dates'),
                element('p', `${t('role')}: ${t(row.role)}${row.speaker ? ` (${row.speaker})` : ''} · ${t('kind')}: ${t(row.kind)}${row.sequence !== null ? ` · ${t('sequence')}: ${row.sequence}` : ''}`),
                element('pre', row.text, 'knowledge-original'));
            if (row.type === 'file_version') {
                const link = element('a', t('original')); link.href = 'api.php?action=knowledge-file&record=' + encodeURIComponent(reference); detail.append(link);
            }
            if (row.relations.length) {
                const links = element('section'); links.append(element('h3', t('relations')));
                row.relations.forEach(relation => { const p = element('p'); p.append(recordLink(relation.reference, `${t(relation.kind)} · ${relation.reference}`)); links.append(p); });
                detail.append(links);
            }
            if (row.type !== 'relation') {
                const annotation = element('form', undefined, 'knowledge-annotation');
                const label = element('label', t('append')); const select = element('select');
                ['correction', 'retraction', 'verification', 'decision', 'observation'].forEach(kind => { const option = element('option', t(kind)); option.value = kind; select.append(option); });
                label.append(select); const textLabel = element('label', t('appendText')); const text = element('textarea'); text.required = true; text.rows = 4; textLabel.append(text);
                const submit = element('button', t('append')); submit.type = 'submit'; annotation.append(label, textLabel, submit);
                annotation.addEventListener('submit', event => { event.preventDefault(); busy(submit, async () => {
                    const added = await request('knowledge-annotate', { target_id: row.reference, kind: select.value, text: text.value });
                    setStatus(t('saved')); await search(); await openRecord('relation:' + added.id);
                }); });
                detail.append(annotation);
            }
            const metadata = element('details'); metadata.append(element('summary', t('details')), element('pre', JSON.stringify(row.data, null, 2), 'knowledge-metadata')); detail.append(metadata);
            history.replaceState(null, '', 'history.php?record=' + encodeURIComponent(reference));
        } catch (error) { if (generation === recordGeneration) { detail.replaceChildren(); setStatus(error.message, true); } }
    }
    form.addEventListener('submit', event => { event.preventDefault(); search(); });
    more.addEventListener('click', () => busy(more, () => search(true)));
    const fileInput = document.getElementById('knowledge-file');
    fileInput.addEventListener('change', () => busy(fileInput, async () => {
        const file = fileInput.files[0]; if (!file) return;
        if (file.size > 4 * 1024 * 1024) throw new Error(t('importHelp'));
        const imported = await request('knowledge-import', { archive: await file.text() });
        fileInput.value = ''; await search(); await openRecord('message:' + imported.message_ids[0]); setStatus(`${t('imported')} ${imported.added}`);
    }));
    document.getElementById('knowledge-template').addEventListener('click', () => {
        const sample = { format: 'openconcept-conversation', version: 1, source: 'my-conversation-export', conversation_id: 'conversation-1', title: 'Conversation', messages: [
            { id: 'message-1', sequence: 0, role: 'user', speaker: null, text: 'Original statement', occurred_at: null, parent_id: null, relates_to: null, kind: 'statement' },
            { id: 'message-2', sequence: 1, role: 'assistant', speaker: null, text: 'Original proposal', occurred_at: '2023-01-01T12:00:00+09:00', parent_id: 'message-1', relates_to: null, kind: 'proposal' }
        ] };
        const url = URL.createObjectURL(new Blob([JSON.stringify(sample, null, 2)], { type: 'application/json' }));
        const link = element('a'); link.href = url; link.download = 'openconcept-conversation-template.json'; link.click(); setTimeout(() => URL.revokeObjectURL(url), 1000);
    });
    const exportButton = document.getElementById('knowledge-export');
    exportButton?.addEventListener('click', () => busy(exportButton, async () => {
        const exported = await request('knowledge-export', {}); setStatus(t('exported'));
        const link = element('a', t('export')); link.href = exported.download_url; status.append(' ', link); link.click();
    }));
    const aiButton = document.getElementById('knowledge-ai');
    aiButton.addEventListener('click', () => busy(aiButton, async () => {
        const question = form.elements.q.value.trim(); if (!question) { form.elements.q.focus(); setStatus(''); return; }
        const response = await request('knowledge-ai', { question, filters: filters() });
        const panel = document.getElementById('knowledge-ai-result'); panel.hidden = false;
        panel.querySelector('pre').textContent = response.result.answer;
        const links = panel.querySelector('div'); links.replaceChildren();
        for (const source of response.result.sources || []) if (source.history_reference) links.append(recordLink(source.history_reference, source.title || source.history_reference), document.createTextNode(' '));
        setStatus('');
    }));
    const initial = new URLSearchParams(location.search);
    if (initial.get('type')) form.elements.type.value = initial.get('type');
    search(); if (initial.get('record')) openRecord(initial.get('record'));
})();
