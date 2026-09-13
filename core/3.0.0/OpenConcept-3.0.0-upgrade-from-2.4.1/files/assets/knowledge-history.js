(() => {
    'use strict';
    const boot = JSON.parse(document.getElementById('knowledge-boot').textContent);
    const t = (key, values = {}) => (boot.labels[key] || key).replace(/\{(\w+)\}/g, (match, name) => values[name] ?? match);
    const form = document.getElementById('knowledge-search');
    const status = document.getElementById('knowledge-status');
    const results = document.getElementById('knowledge-results');
    const detail = document.getElementById('knowledge-record');
    const pageSize = document.getElementById('knowledge-page-size');
    const listControls = document.getElementById('knowledge-list-controls');
    const pageSummaries = document.querySelectorAll('[data-knowledge-page-summary]');
    const pageNumbers = document.querySelectorAll('[data-knowledge-page-number]');
    const previousButtons = document.querySelectorAll('[data-knowledge-page="previous"]');
    const nextButtons = document.querySelectorAll('[data-knowledge-page="next"]');
    let nextOffset = null;
    let currentOffset = 0;
    let currentLimit = 10;
    let matchedCount = 0;
    let displayedCount = 0;
    let appliedFilters = null;
    let searchLoading = false;
    let searchGeneration = 0;
    let recordGeneration = 0;
    let scannedRows = null;
    let resultGroups = [];
    let scanCursor = null;
    let scanComplete = true;
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
    function updatePagination() {
        const pages = Math.ceil(matchedCount / currentLimit);
        const range = t('pageRange', { start: displayedCount ? currentOffset + 1 : 0, end: currentOffset + displayedCount, total: matchedCount });
        const position = t('pageNumber', { current: pages ? Math.floor(currentOffset / currentLimit) + 1 : 0, total: pages });
        pageSummaries.forEach(node => { node.textContent = range; });
        pageNumbers.forEach(node => { node.textContent = position; });
        previousButtons.forEach(node => { node.disabled = searchLoading || currentOffset === 0; });
        nextButtons.forEach(node => { node.disabled = searchLoading || nextOffset === null; });
        results.setAttribute('aria-busy', String(searchLoading));
    }
    function appearance(row) {
        const value = row.appearance || {};
        return [value.icon ? `${t('field_icon')}: ${value.icon}` : '', value.cover ? `${t('field_cover')}: ${t('cover_' + value.cover)}` : ''].filter(Boolean).join(' · ');
    }
    function cardFor(row) {
        const card = element('article', undefined, 'knowledge-card');
        card.append(element('span', t(row.type), 'knowledge-type'), recordLink(row.reference, (row.type === 'relation' ? t(row.kind) : row.title) || t(row.type)),
            element('p', row.excerpt), element('small', `${t('sourceTime')}: ${row.occurred_at || t('unknown')} · ${t('captureTime')}: ${row.recorded_at || t('unknown')}`));
        if (row.versions) {
            const versions = element('details', undefined, 'knowledge-versions');
            versions.append(element('summary', t('matchingVersions', { count: row.versions.length, bodies: new Set(row.versions.map(item => item.content_hash)).size })));
            let previousHash = null;
            for (const version of row.versions) {
                const item = element('div', undefined, 'knowledge-version');
                item.append(recordLink(version.reference, t('savedVersion', { id: version.id || version.reference.split(':')[1] })),
                    element('small', `${t('captureTime')}: ${version.recorded_at || t('unknown')}`), element('small', appearance(version)));
                if (version.content_hash !== previousHash) item.append(element('p', version.excerpt));
                else item.append(element('small', t('sameBody')));
                previousHash = version.content_hash;
                versions.append(item);
            }
            card.append(versions);
        }
        return card;
    }
    function regroup(criteria) {
        const basis = criteria.date_basis || 'recorded_at';
        const rows = [...scannedRows.values()].sort((a, b) => String(a[basis] || '9999').localeCompare(String(b[basis] || '9999'))
            || String(a.conversation_key || '').localeCompare(String(b.conversation_key || ''))
            || (a.sequence || 0) - (b.sequence || 0) || a.reference.localeCompare(b.reference, undefined, { numeric: true }));
        const groups = new Map();
        for (const row of rows) {
            const key = row.type === 'page_revision' ? `page:${row.page_id}` : row.reference;
            if (groups.has(key)) groups.get(key).versions.push(row);
            else groups.set(key, row.type === 'page_revision' ? { ...row, versions: [row] } : row);
        }
        resultGroups = [...groups.values()];
    }
    function renderScanned(offset, limit) {
        currentOffset = offset >= resultGroups.length ? 0 : offset;
        currentLimit = limit; matchedCount = resultGroups.length;
        const visible = resultGroups.slice(currentOffset, currentOffset + limit);
        displayedCount = visible.length; nextOffset = currentOffset + limit < matchedCount ? currentOffset + limit : null;
        results.replaceChildren(...visible.map(cardFor));
        if (!visible.length) results.append(element('p', t(scanComplete ? 'empty' : 'scanning')));
        pageSize.value = String(limit); updatePagination();
    }
    async function collectScanned(data, criteria, generation, limit) {
        for (;;) {
            if (generation !== searchGeneration) return;
            for (const row of data.items) scannedRows.set(row.reference, row);
            scanCursor = data.scan_cursor; scanComplete = data.complete === true;
            regroup(criteria); renderScanned(0, limit);
            setStatus(t(scanComplete ? 'searchFinished' : 'searchProgress', { count: scannedRows.size, groups: resultGroups.length }));
            if (scanComplete) return;
            data = await request('knowledge-search', undefined, { ...criteria, scan: '1', cursor: scanCursor, limit: 500 });
        }
    }
    async function search(offset = 0, criteria = filters(), scrollToList = false) {
        const generation = ++searchGeneration;
        const limit = [10, 25, 50].includes(Number(pageSize.value)) ? Number(pageSize.value) : 10;
        // A new filter starts at page one, even when submitted during navigation.
        if (JSON.stringify(criteria) !== JSON.stringify(appliedFilters)) offset = 0;
        if (scrollToList && scannedRows !== null && JSON.stringify(criteria) === JSON.stringify(appliedFilters)) {
            renderScanned(offset, limit); listControls.scrollIntoView({ block: 'start' }); pageSummaries[0].focus({ preventScroll: true }); return;
        }
        searchLoading = true; updatePagination(); setStatus(t('busy'));
        try {
            let data = await request('knowledge-search', undefined, { ...criteria, offset, limit, scan: '1' });
            if (generation !== searchGeneration) return;
            if (Object.hasOwn(data, 'scan_cursor')) {
                scannedRows = new Map(); appliedFilters = { ...criteria };
                await collectScanned(data, criteria, generation, limit);
                return;
            }
            scannedRows = null;
            // Permissions or filters can reduce the available records between requests.
            if (offset > 0 && !data.items.length) {
                offset = 0;
                data = await request('knowledge-search', undefined, { ...criteria, offset, limit });
                if (generation !== searchGeneration) return;
            }
            results.replaceChildren();
            for (const row of data.items) results.append(cardFor(row));
            if (!data.items.length) results.append(element('p', t('empty')));
            currentOffset = offset; currentLimit = limit; matchedCount = data.matched_count;
            displayedCount = data.items.length; nextOffset = data.next_offset; appliedFilters = { ...criteria };
            pageSize.value = String(limit);
            setStatus(data.truncated ? t('limited') : '');
            if (scrollToList) {
                listControls.scrollIntoView({ block: 'start' });
                pageSummaries[0].focus({ preventScroll: true });
            }
        } catch (error) {
            if (generation === searchGeneration) {
                pageSize.value = String(currentLimit);
                setStatus(scannedRows !== null && !scanComplete ? `${t('searchIncomplete')} ${error.message}` : error.message, true);
                if (scannedRows !== null && !scanComplete && scanCursor) status.append(button(t('resumeSearch'), () => resumeSearch()));
            }
        } finally {
            if (generation === searchGeneration) { searchLoading = false; updatePagination(); }
        }
    }
    async function resumeSearch() {
        if (searchLoading || !scanCursor) return;
        const generation = ++searchGeneration;
        searchLoading = true; updatePagination(); setStatus(t('busy'));
        try {
            const data = await request('knowledge-search', undefined, { ...appliedFilters, scan: '1', cursor: scanCursor, limit: 500 });
            await collectScanned(data, appliedFilters, generation, currentLimit);
        } catch (error) {
            if (generation === searchGeneration) { setStatus(`${t('searchIncomplete')} ${error.message}`, true); status.append(button(t('resumeSearch'), () => resumeSearch())); }
        } finally { if (generation === searchGeneration) { searchLoading = false; updatePagination(); } }
    }
    function showRevisionContext(row) {
        const context = row.revision_context;
        if (!context) return;
        const section = element('section', undefined, 'knowledge-changes');
        section.append(element('h3', t('changes')), element('p', t(context.compared_with === 'current_page' ? 'compareCurrent' : 'compareNext'), 'knowledge-note'));
        if (context.previous_reference) section.append(recordLink(context.previous_reference, t('previousVersion')), document.createTextNode(' · '));
        if (context.next_reference) section.append(recordLink(context.next_reference, t('nextVersion')));
        const changes = element('ul');
        for (const change of context.changes) {
            const item = element('li', t('field_' + change.field));
            const display = value => change.field === 'cover' ? t('cover_' + value) : (typeof value === 'object' ? JSON.stringify(value) : String(value ?? '—'));
            if (change.field !== 'blocks_json') item.append(element('div', `${display(change.before)} → ${display(change.after)}`, 'knowledge-change-value'));
            changes.append(item);
        }
        section.append(changes, element('p', t(context.changes.some(change => change.field === 'blocks_json') ? 'bodyChanged' : 'bodyUnchanged'), 'knowledge-note'));
        if (!context.changes.length) section.append(element('p', t('noRecordedChanges')));
        if (context.unrecorded_fields.length) section.append(element('p', t('comparisonPartial'), 'knowledge-note'));
        if (context.body_after !== null) {
            const comparison = element('details'); comparison.append(element('summary', t('compareBody')));
            const columns = element('div', undefined, 'knowledge-body-comparison');
            for (const [label, text] of [['before', row.text], ['after', context.body_after]]) {
                const column = element('section'); column.append(element('h4', t(label)), element('pre', text, 'knowledge-original')); columns.append(column);
            }
            comparison.append(columns); section.append(comparison);
        }
        detail.append(section);
    }
    async function openRecord(reference) {
        const generation = ++recordGeneration;
        try {
            const row = await request('knowledge-record', undefined, { record: reference });
            if (generation !== recordGeneration) return;
            detail.replaceChildren(element('span', t(row.type), 'knowledge-type'), element('h2', (row.type === 'relation' ? t(row.kind) : row.title) || t(row.type)),
                element('p', t('sourceWarning'), 'knowledge-note'),
                element('p', `${t('sourceTime')}: ${row.occurred_at || t('unknown')}\n${t('captureTime')}: ${row.recorded_at || t('unknown')}`, 'knowledge-dates'),
                element('p', `${t('role')}: ${t(row.role)}${row.speaker ? ` (${row.speaker})` : ''} · ${t('kind')}: ${t(row.kind)}${row.sequence !== null ? ` · ${t('sequence')}: ${row.sequence}` : ''}`));
            if (row.type === 'page_revision') detail.append(element('p', t('snapshotExplanation'), 'knowledge-note'));
            showRevisionContext(row);
            detail.append(element('pre', row.text, 'knowledge-original'));
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
    pageSize.addEventListener('change', () => search());
    previousButtons.forEach(node => node.addEventListener('click', () => {
        if (!searchLoading && currentOffset > 0) search(Math.max(0, currentOffset - currentLimit), appliedFilters, true);
    }));
    nextButtons.forEach(node => node.addEventListener('click', () => {
        if (!searchLoading && nextOffset !== null) search(nextOffset, appliedFilters, true);
    }));
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
