(() => {
    'use strict';

    const PLUGIN_ID = 'drawing-manager';
    const PLUGIN_SCRIPT_URL = document.currentScript?.src || '';
    const t = (key, parameters = {}) => window.OpenConceptI18n?.t(PLUGIN_ID, key, parameters) || key;
    const REVIEW_FIELDS = ['drawing_no', 'revision_code', 'title', 'customer', 'material', 'process', 'owner_department', 'notes'];
    const OCR_FIELD_KEY = { drawing_no: 'ocr.field.drawingNo', revision_code: 'ocr.field.revision', title: 'ocr.field.title' };
    const SOURCE_KEY = {
        openai_vision: 'GPT-5.6 Luna Vision',
        filename: 'source.filename',
        unavailable: 'source.unavailable',
        manual: 'source.manual',
    };
    const STATUS_KEY = {
        pending: 'status.pending',
        approved: 'status.approved',
    };
    const IMAGE_ZOOM_MIN = 100;
    const IMAGE_ZOOM_MAX = 300;
    const IMAGE_ZOOM_STEP = 25;
    const LIST_LAYOUT_STORAGE_KEY = 'openconcept.drawing-manager.list-layout.v1';
    const LIST_PAGE_SIZE_STORAGE_KEY = 'openconcept.drawing-manager.page-size.v1';
    const LIST_PAGE_SIZES = [20, 50, 100];
    const PDF_THUMBNAIL_CONCURRENCY = 2;
    const PROCESS_OPTIONS = [
        ['material_heat_treatment', 'process.materialHeatTreatment'],
        ['lathe', 'process.lathe'],
        ['machining', 'process.machining'],
        ['drilling', 'process.drilling'],
        ['milling', 'process.milling'],
        ['post_heat_treatment', 'process.postHeatTreatment'],
        ['wire_cut', 'process.wireCut'],
        ['surface_grinding', 'process.surfaceGrinding'],
        ['outer_diameter_grinding', 'process.outerDiameterGrinding'],
        ['inner_diameter_grinding', 'process.innerDiameterGrinding'],
        ['other_grinding', 'process.otherGrinding'],
        ['surface_treatment', 'process.surfaceTreatment'],
        ['cleaning', 'process.cleaning'],
        ['specified_packaging', 'process.specifiedPackaging'],
        ['other', 'process.other'],
    ];
    const PROCESS_KEYS = Object.fromEntries(PROCESS_OPTIONS);
    const state = {
        drawings: [],
        drawing: null,
        reviewDraft: null,
        processCodes: [],
        reviewConfirmed: false,
        imageZooms: {},
        pdfPages: {},
        pdfZooms: {},
        ocrTargetField: 'drawing_no',
        ocrInsertStarted: false,
        ocrAppliedKeys: [],
        uploadWarnings: [],
        counts: { total: 0, pending: 0, approved: 0 },
        query: '',
        status: 'all',
        listLayout: readListLayout(),
        pagination: { page: 1, perPage: readListPageSize(), total: 0, totalPages: 1 },
        mode: 'list',
        loading: false,
        uploading: false,
        approving: false,
        saving: false,
        archiving: false,
        comments: [],
        commentDraft: '',
        commentSubmitting: false,
        commentResolvingId: 0,
        commentsError: '',
        closeConfirmationOpen: false,
        archiveConfirmationOpen: false,
        archiveError: '',
        selectedFileName: '',
        notice: '',
        error: '',
        user: null,
        csrf: window.OPENCONCEPT_BOOT?.csrf || '',
    };
    let overlay = null;
    let searchTimer = 0;
    let listRequest = 0;
    let detailRequest = 0;
    let returnFocus = null;
    let closeConfirmation = null;
    let closeConfirmationReturnFocus = null;
    let archiveConfirmation = null;
    let archiveConfirmationReturnFocus = null;
    let pdfPreviewResizeObserver = null;
    let pdfPreviewFrameRequest = 0;
    let pdfPreviewResizeHandler = null;
    let pdfJsModulePromise = null;
    const pdfViewers = new Map();
    let imageOcrResizeObservers = [];
    let restoringOcrFocus = false;
    let thumbnailObserver = null;
    let thumbnailQueue = [];
    let activeThumbnailRenders = 0;
    const thumbnailRuntimes = new Set();

    const esc = value => String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
    const csrf = () => state.csrf || window.OPENCONCEPT_BOOT?.csrf || '';
    const canEdit = () => ['admin', 'editor'].includes(state.user?.role);
    const isAdmin = () => state.user?.role === 'admin';
    const detailBusy = () => state.approving
        || state.saving
        || state.archiving
        || state.commentSubmitting
        || Boolean(state.commentResolvingId);
    const isActiveDrawing = drawingId => state.mode === 'detail'
        && Number(state.drawing?.id) === Number(drawingId);
    const ocrFieldLabel = field => t(OCR_FIELD_KEY[field] || field);
    const sourceLabel = source => source === 'openai_vision' ? SOURCE_KEY.openai_vision : t(SOURCE_KEY[source] || 'source.auto');
    const statusLabel = status => t(STATUS_KEY[status] || status);
    const processLabel = code => t(PROCESS_KEYS[code] || code);

    function warningText(item) {
        if (!item || typeof item !== 'object' || !/^warning\.[A-Za-z0-9_.-]+$/.test(String(item.key || ''))) {
            return t('warning.generic');
        }
        const parameters = item.parameters && typeof item.parameters === 'object' ? { ...item.parameters } : {};
        if (typeof parameters.field === 'string') parameters.field = ocrFieldLabel(parameters.field);
        return t(String(item.key), parameters);
    }

    function apiErrorMessage(data, status) {
        const codeKey = {
            drawing_conflict: 'error.api.conflict',
            drawing_duplicate: 'error.api.duplicate',
            drawing_already_approved: 'error.api.alreadyApproved',
            drawing_file_integrity: 'error.api.fileIntegrity',
        }[String(data?.code || '')];
        if (codeKey) return t(codeKey);
        if (status === 401) return t('error.api.session');
        if (status === 403) return t('error.api.forbidden');
        if (status === 404) return t('error.api.notFound');
        if (status === 405) return t('error.api.unsupported');
        if (status === 422) return t('error.api.invalid');
        if (status === 429) return t('error.api.rateLimit');
        return t('error.api.server');
    }

    function readListLayout() {
        try {
            return window.localStorage.getItem(LIST_LAYOUT_STORAGE_KEY) === 'text' ? 'text' : 'card';
        } catch (_) {
            return 'card';
        }
    }

    function persistListLayout(layout) {
        try {
            window.localStorage.setItem(LIST_LAYOUT_STORAGE_KEY, layout);
        } catch (_) {
            // The view still changes for this session when storage is unavailable.
        }
    }

    function readListPageSize() {
        try {
            const stored = Number(window.localStorage.getItem(LIST_PAGE_SIZE_STORAGE_KEY));
            return LIST_PAGE_SIZES.includes(stored) ? stored : LIST_PAGE_SIZES[0];
        } catch (_) {
            return LIST_PAGE_SIZES[0];
        }
    }

    function persistListPageSize(pageSize) {
        try {
            window.localStorage.setItem(LIST_PAGE_SIZE_STORAGE_KEY, String(pageSize));
        } catch (_) {
            // The page size still changes for this session when storage is unavailable.
        }
    }

    function approvalStatus(drawing) {
        return String(drawing?.approval_status || drawing?.status || '') === 'approved' ? 'approved' : 'pending';
    }

    function formatDate(value) {
        if (!value) return '—';
        const date = new Date(String(value).replace(' ', 'T'));
        if (Number.isNaN(date.getTime())) return esc(value);
        return new Intl.DateTimeFormat(window.OpenConceptI18n?.locale() || 'en-US', { year: 'numeric', month: 'short', day: 'numeric' }).format(date);
    }

    function formatDateTime(value) {
        if (!value) return '—';
        const date = new Date(String(value).replace(' ', 'T'));
        if (Number.isNaN(date.getTime())) return esc(value);
        return new Intl.DateTimeFormat(window.OpenConceptI18n?.locale() || 'en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        }).format(date);
    }

    function formatBytes(value) {
        const bytes = Number(value) || 0;
        if (bytes < 1024) return `${bytes} B`;
        if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
        return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
    }

    function fileType(extension) {
        return String(extension || 'FILE').toUpperCase();
    }

    function aiFieldCount(drawing) {
        if (String(drawing?.extraction_source || '') !== 'openai_vision') return 0;
        const direct = Number(drawing?.ai_field_count ?? drawing?.extraction_count);
        if (Number.isFinite(direct) && direct >= 0 && direct <= 3) return Math.round(direct);
        const confidence = Number(drawing?.extraction_confidence);
        if (!Number.isFinite(confidence)) return 0;
        return Math.max(0, Math.min(3, Math.round(confidence * 3 / 100)));
    }

    function reviewDraftFrom(drawing) {
        return Object.fromEntries(REVIEW_FIELDS.map(name => {
            return [name, String(drawing?.[name] ?? '')];
        }));
    }

    function processCodesFrom(drawing) {
        const values = Array.isArray(drawing?.process_metadata) ? drawing.process_metadata : [];
        const requested = new Set(values.map(item => typeof item === 'string' ? item : item?.code).filter(code => Object.hasOwn(PROCESS_KEYS, code)));
        return PROCESS_OPTIONS.map(([code]) => code).filter(code => requested.has(code));
    }

    function processLabelsFrom(drawing) {
        return processCodesFrom(drawing).map(processLabel);
    }

    function processSummary(drawing) {
        const labels = processLabelsFrom(drawing);
        const freeText = String(drawing?.process || '').trim();
        if (labels.length && freeText) return `${labels.join('・')} / ${freeText}`;
        return labels.length ? labels.join('・') : freeText;
    }

    function setDrawing(drawing) {
        const previousId = String(state.drawing?.id || '');
        const nextId = String(drawing?.id || '');
        if (previousId !== nextId) {
            state.imageZooms = {};
            state.pdfPages = {};
            state.pdfZooms = {};
            state.ocrTargetField = 'drawing_no';
            state.ocrInsertStarted = false;
            state.ocrAppliedKeys = [];
        }
        state.drawing = drawing || null;
        const editable = drawing && (approvalStatus(drawing) === 'pending' || isAdmin());
        state.reviewDraft = editable ? reviewDraftFrom(drawing) : null;
        state.processCodes = editable ? processCodesFrom(drawing) : [];
        state.reviewConfirmed = false;
    }

    function resetComments() {
        state.comments = [];
        state.commentDraft = '';
        state.commentSubmitting = false;
        state.commentResolvingId = 0;
        state.commentsError = '';
    }

    function imageZoom(fileId) {
        const value = Number(state.imageZooms[String(fileId)]) || IMAGE_ZOOM_MIN;
        const stepped = Math.round(value / IMAGE_ZOOM_STEP) * IMAGE_ZOOM_STEP;
        return Math.max(IMAGE_ZOOM_MIN, Math.min(IMAGE_ZOOM_MAX, stepped));
    }

    function updateImageZoom(control, action) {
        const viewer = control.closest('[data-image-viewer]');
        const viewport = viewer?.querySelector('[data-image-viewport]');
        if (!(viewer instanceof HTMLElement) || !(viewport instanceof HTMLElement)) return;

        const fileId = String(viewer.dataset.fileId || '');
        const current = imageZoom(fileId);
        const next = action === 'image-fit'
            ? IMAGE_ZOOM_MIN
            : Math.max(IMAGE_ZOOM_MIN, Math.min(IMAGE_ZOOM_MAX, current + (action === 'image-zoom-in' ? IMAGE_ZOOM_STEP : -IMAGE_ZOOM_STEP)));
        const centerX = (viewport.scrollLeft + viewport.clientWidth / 2) / Math.max(1, viewport.scrollWidth);
        const centerY = (viewport.scrollTop + viewport.clientHeight / 2) / Math.max(1, viewport.scrollHeight);

        state.imageZooms[fileId] = next;
        viewer.dataset.zoom = String(next);
        viewer.style.setProperty('--drawing-image-zoom', `${next}%`);
        const output = viewer.querySelector('[data-image-zoom-value]');
        if (output) output.textContent = `${next}%`;
        const zoomOut = viewer.querySelector('[data-action="image-zoom-out"]');
        const zoomIn = viewer.querySelector('[data-action="image-zoom-in"]');
        if (zoomOut instanceof HTMLButtonElement) zoomOut.disabled = next <= IMAGE_ZOOM_MIN;
        if (zoomIn instanceof HTMLButtonElement) zoomIn.disabled = next >= IMAGE_ZOOM_MAX;

        window.requestAnimationFrame(() => {
            syncImageOcrLayer(viewer);
            if (action === 'image-fit') {
                viewport.scrollTo({ left: 0, top: 0 });
                return;
            }
            viewport.scrollLeft = Math.max(0, centerX * viewport.scrollWidth - viewport.clientWidth / 2);
            viewport.scrollTop = Math.max(0, centerY * viewport.scrollHeight - viewport.clientHeight / 2);
        });
    }

    function pluginResourceUrl(path) {
        const version = String(window.OPENCONCEPT_BOOT?.plugins?.[PLUGIN_ID] || '0.9.0');
        const url = new URL(PLUGIN_SCRIPT_URL || 'plugin-asset.php', window.location.href);
        url.searchParams.set('plugin', PLUGIN_ID);
        url.searchParams.set('v', version);
        url.searchParams.set('file', path);
        return url.href;
    }

    function pluginResourcePrefix(path) {
        return `${pluginResourceUrl(path.replace(/\/$/u, ''))}/`;
    }

    async function loadPdfJs() {
        if (!pdfJsModulePromise) {
            pdfJsModulePromise = import(pluginResourceUrl('vendor/pdfjs/build/pdf.min.mjs')).then(module => {
                module.GlobalWorkerOptions.workerSrc = pluginResourceUrl('vendor/pdfjs/build/pdf.worker.min.mjs');
                return module;
            });
        }
        return pdfJsModulePromise;
    }

    function drawingFileUrl(fileId) {
        const version = String(window.OPENCONCEPT_BOOT?.plugins?.[PLUGIN_ID] || '0.9.0');
        return `api.php?action=plugin-drawing-manager-file&id=${Number(fileId)}&v=${encodeURIComponent(version)}`;
    }

    function destroyListThumbnails() {
        thumbnailObserver?.disconnect();
        thumbnailObserver = null;
        thumbnailQueue = [];
        for (const runtime of thumbnailRuntimes) {
            try { runtime.renderTask?.cancel(); } catch (_) { /* already complete */ }
            try { runtime.loadingTask?.destroy(); } catch (_) { /* already complete */ }
        }
        thumbnailRuntimes.clear();
    }

    function showThumbnailError(surface) {
        if (!surface.isConnected) return;
        surface.classList.add('has-error');
        const status = surface.querySelector('[data-thumbnail-status]');
        if (status) status.textContent = t('preview.none');
    }

    async function renderPdfThumbnail(surface) {
        if (!(surface instanceof HTMLElement) || !surface.isConnected || surface.dataset.thumbnailState === 'loading') return;
        surface.dataset.thumbnailState = 'loading';
        const canvas = surface.querySelector('canvas[data-pdf-thumbnail-canvas]');
        const status = surface.querySelector('[data-thumbnail-status]');
        if (!(canvas instanceof HTMLCanvasElement)) return;
        const runtime = { surface, loadingTask: null, renderTask: null };
        thumbnailRuntimes.add(runtime);
        try {
            const pdfjs = await loadPdfJs();
            if (!surface.isConnected || !thumbnailRuntimes.has(runtime)) return;
            runtime.loadingTask = pdfjs.getDocument({
                url: String(surface.dataset.fileUrl || ''),
                withCredentials: true,
                enableScripting: false,
                isEvalSupported: false,
                disableAutoFetch: true,
                wasmUrl: pluginResourcePrefix('vendor/pdfjs/wasm'),
                standardFontDataUrl: pluginResourcePrefix('vendor/pdfjs/standard_fonts'),
            });
            const document = await runtime.loadingTask.promise;
            if (!surface.isConnected || !thumbnailRuntimes.has(runtime)) return;
            const page = await document.getPage(1);
            const base = page.getViewport({ scale: 1 });
            const width = Math.max(180, surface.clientWidth - 20);
            const height = Math.max(100, surface.clientHeight - 16);
            const cssScale = Math.min(width / base.width, height / base.height);
            const pixelRatio = Math.max(1, Math.min(1.5, window.devicePixelRatio || 1));
            const viewport = page.getViewport({ scale: cssScale * pixelRatio });
            canvas.width = Math.max(1, Math.ceil(viewport.width));
            canvas.height = Math.max(1, Math.ceil(viewport.height));
            canvas.style.width = `${viewport.width / pixelRatio}px`;
            canvas.style.height = `${viewport.height / pixelRatio}px`;
            const context = canvas.getContext('2d', { alpha: false });
            if (!context) throw new Error('thumbnail canvas unavailable');
            runtime.renderTask = page.render({ canvasContext: context, viewport, background: '#ffffff' });
            await runtime.renderTask.promise;
            if (!surface.isConnected || !thumbnailRuntimes.has(runtime)) return;
            surface.dataset.thumbnailState = 'ready';
            surface.classList.add('is-ready');
            if (status) status.hidden = true;
        } catch (error) {
            if (error?.name !== 'RenderingCancelledException') showThumbnailError(surface);
        }
    }

    function drainThumbnailQueue() {
        while (activeThumbnailRenders < PDF_THUMBNAIL_CONCURRENCY && thumbnailQueue.length) {
            const surface = thumbnailQueue.shift();
            if (!(surface instanceof HTMLElement) || !surface.isConnected || surface.dataset.thumbnailState) continue;
            activeThumbnailRenders += 1;
            renderPdfThumbnail(surface).finally(() => {
                activeThumbnailRenders = Math.max(0, activeThumbnailRenders - 1);
                drainThumbnailQueue();
            });
        }
    }

    function queuePdfThumbnail(surface) {
        if (!(surface instanceof HTMLElement) || surface.dataset.thumbnailState || thumbnailQueue.includes(surface)) return;
        thumbnailQueue.push(surface);
        drainThumbnailQueue();
    }

    function initializeListThumbnails() {
        if (!overlay || overlay.classList.contains('is-hidden') || state.mode !== 'list' || state.listLayout !== 'card') return;
        for (const image of overlay.querySelectorAll('img[data-image-thumbnail]')) {
            const surface = image.closest('.drawing-thumbnail-image');
            if (!(image instanceof HTMLImageElement) || !(surface instanceof HTMLElement)) continue;
            const ready = () => {
                surface.classList.add('is-ready');
                const status = surface.querySelector('[data-thumbnail-status]');
                if (status) status.hidden = true;
            };
            const failed = () => showThumbnailError(surface);
            image.addEventListener('load', ready, { once: true });
            image.addEventListener('error', failed, { once: true });
            if (image.complete) {
                if (image.naturalWidth > 0) ready();
                else failed();
            }
        }
        const surfaces = Array.from(overlay.querySelectorAll('[data-pdf-thumbnail]'));
        if (!surfaces.length) return;
        if (typeof IntersectionObserver !== 'function') {
            surfaces.forEach(queuePdfThumbnail);
            return;
        }
        thumbnailObserver = new IntersectionObserver(entries => {
            for (const entry of entries) {
                if (!entry.isIntersecting) continue;
                thumbnailObserver?.unobserve(entry.target);
                queuePdfThumbnail(entry.target);
            }
        }, { root: overlay.querySelector('.drawing-plugin-body'), rootMargin: '220px 0px' });
        surfaces.forEach(surface => thumbnailObserver.observe(surface));
    }

    function destroyPdfViewers() {
        for (const runtime of pdfViewers.values()) {
            if (runtime.resizeObserver) runtime.resizeObserver.disconnect();
            if (runtime.resizeTimer) window.clearTimeout(runtime.resizeTimer);
            try { runtime.renderTask?.cancel(); } catch (_) { /* already complete */ }
            try { runtime.loadingTask?.destroy(); } catch (_) { /* already complete */ }
        }
        pdfViewers.clear();
    }

    function disconnectImageOcrLayers() {
        for (const observer of imageOcrResizeObservers) observer.disconnect();
        imageOcrResizeObservers = [];
    }

    function normalizedOcrRegions(file) {
        if (!Array.isArray(file?.ocr_regions)) return [];
        return file.ocr_regions.filter(region => {
            if (!region || !Object.hasOwn(OCR_FIELD_KEY, String(region.field || '')) || typeof region.text !== 'string') return false;
            const page = Number(region.page);
            const x = Number(region.x);
            const y = Number(region.y);
            const width = Number(region.width);
            const height = Number(region.height);
            return Number.isInteger(page) && page >= 1 && page <= 100
                && Number.isInteger(x) && Number.isInteger(y) && Number.isInteger(width) && Number.isInteger(height)
                && x >= 0 && y >= 0 && width > 0 && height > 0 && x + width <= 1000 && y + height <= 1000;
        }).slice(0, 24);
    }

    function ocrLayerMarkup(file, currentPage = 1) {
        const interactive = (approvalStatus(state.drawing) === 'pending' && canEdit()) || isAdmin();
        const regions = normalizedOcrRegions(file);
        if (!regions.length) return '';
        return `<div class="drawing-ocr-layer" data-ocr-layer aria-label="${esc(t('ocr.regionLabel'))}">${regions.map((region, index) => {
            const field = String(region.field);
            const text = String(region.text);
            const page = Number(region.page);
            const hidden = page === currentPage ? '' : ' hidden';
            const disabled = interactive ? '' : ' disabled';
            const fieldLabel = ocrFieldLabel(field);
            const label = t('ocr.candidateLabel', { field: fieldLabel, text });
            return `<button type="button" class="drawing-ocr-region is-${esc(field)}" data-action="insert-ocr-region" data-file-id="${Number(file.id)}" data-region-index="${index}" data-ocr-field="${esc(field)}" data-ocr-text="${esc(text)}" data-ocr-page="${page}" style="--ocr-x:${Number(region.x) / 10}%;--ocr-y:${Number(region.y) / 10}%;--ocr-width:${Number(region.width) / 10}%;--ocr-height:${Number(region.height) / 10}%" aria-label="${esc(label)}" aria-pressed="false" title="${esc(label)}"${hidden}${disabled}><span>${esc(fieldLabel)}</span></button>`;
        }).join('')}</div>`;
    }

    function showPdfPageRegions(viewer, pageNumber) {
        for (const region of viewer.querySelectorAll('[data-ocr-page]')) {
            region.hidden = Number(region.dataset.ocrPage) !== pageNumber;
        }
    }

    async function renderPdfPage(runtime) {
        const { viewer, document: pdfDocument, fileId } = runtime;
        if (!viewer.isConnected || !pdfDocument) return;
        const requestedPage = Number(state.pdfPages[fileId]) || 1;
        const pageNumber = Math.max(1, Math.min(pdfDocument.numPages, requestedPage));
        state.pdfPages[fileId] = pageNumber;
        const zoom = Math.max(75, Math.min(250, Number(state.pdfZooms[fileId]) || 100));
        state.pdfZooms[fileId] = zoom;
        const scroll = viewer.querySelector('[data-pdf-scroll]');
        const surface = viewer.querySelector('[data-pdf-page]');
        const canvas = viewer.querySelector('canvas[data-pdf-canvas]');
        const status = viewer.querySelector('[data-pdf-status]');
        if (!(scroll instanceof HTMLElement) || !(surface instanceof HTMLElement) || !(canvas instanceof HTMLCanvasElement)) return;

        const token = ++runtime.renderToken;
        const page = await pdfDocument.getPage(pageNumber);
        if (token !== runtime.renderToken || !viewer.isConnected) return;
        const baseViewport = page.getViewport({ scale: 1 });
        const availableWidth = Math.max(240, scroll.clientWidth - 24);
        const cssWidth = availableWidth * zoom / 100;
        const cssScale = cssWidth / baseViewport.width;
        const cssViewport = page.getViewport({ scale: cssScale });
        const pixelRatio = Math.max(1, Math.min(2, window.devicePixelRatio || 1));
        const renderViewport = page.getViewport({ scale: cssScale * pixelRatio });

        try { runtime.renderTask?.cancel(); } catch (_) { /* already complete */ }
        canvas.width = Math.max(1, Math.ceil(renderViewport.width));
        canvas.height = Math.max(1, Math.ceil(renderViewport.height));
        canvas.style.width = `${cssViewport.width}px`;
        canvas.style.height = `${cssViewport.height}px`;
        surface.style.width = `${cssViewport.width}px`;
        surface.style.height = `${cssViewport.height}px`;
        surface.dataset.page = String(pageNumber);
        showPdfPageRegions(viewer, pageNumber);

        const context = canvas.getContext('2d', { alpha: false });
        if (!context) throw new Error(t('error.pdfContext'));
        runtime.renderTask = page.render({ canvasContext: context, viewport: renderViewport, background: '#ffffff' });
        try {
            await runtime.renderTask.promise;
        } catch (error) {
            if (error?.name !== 'RenderingCancelledException') throw error;
            return;
        }
        if (token !== runtime.renderToken || !viewer.isConnected) return;

        const pageOutput = viewer.querySelector('[data-pdf-page-value]');
        const zoomOutput = viewer.querySelector('[data-pdf-zoom-value]');
        const previous = viewer.querySelector('[data-action="pdf-prev"]');
        const next = viewer.querySelector('[data-action="pdf-next"]');
        if (pageOutput) pageOutput.textContent = `${pageNumber} / ${pdfDocument.numPages}`;
        if (zoomOutput) zoomOutput.textContent = `${zoom}%`;
        if (previous instanceof HTMLButtonElement) previous.disabled = pageNumber <= 1;
        if (next instanceof HTMLButtonElement) next.disabled = pageNumber >= pdfDocument.numPages;
        if (status instanceof HTMLElement) status.hidden = true;
    }

    async function initializePdfViewer(viewer) {
        if (!(viewer instanceof HTMLElement)) return;
        const fileId = String(viewer.dataset.fileId || '');
        const url = String(viewer.dataset.fileUrl || '');
        if (!fileId || !url) return;
        const runtime = { viewer, fileId, document: null, loadingTask: null, renderTask: null, renderToken: 0, resizeObserver: null, resizeTimer: 0 };
        pdfViewers.set(fileId, runtime);
        const status = viewer.querySelector('[data-pdf-status]');
        try {
            const pdfjs = await loadPdfJs();
            if (!viewer.isConnected || pdfViewers.get(fileId) !== runtime) return;
            runtime.loadingTask = pdfjs.getDocument({
                url,
                withCredentials: true,
                enableScripting: false,
                isEvalSupported: false,
                wasmUrl: pluginResourcePrefix('vendor/pdfjs/wasm'),
                standardFontDataUrl: pluginResourcePrefix('vendor/pdfjs/standard_fonts'),
            });
            runtime.document = await runtime.loadingTask.promise;
            if (!viewer.isConnected || pdfViewers.get(fileId) !== runtime) {
                runtime.loadingTask.destroy();
                return;
            }
            const regionPages = normalizedOcrRegions((state.drawing?.files || []).find(file => String(file.id) === fileId)).map(region => Number(region.page));
            const firstRegionPage = regionPages.find(page => page >= 1 && page <= runtime.document.numPages);
            state.pdfPages[fileId] = Math.max(1, Math.min(runtime.document.numPages, Number(state.pdfPages[fileId]) || firstRegionPage || 1));
            await renderPdfPage(runtime);
            if (typeof ResizeObserver === 'function') {
                runtime.resizeObserver = new ResizeObserver(() => {
                    window.clearTimeout(runtime.resizeTimer);
                    runtime.resizeTimer = window.setTimeout(() => renderPdfPage(runtime).catch(showPdfError.bind(null, runtime)), 80);
                });
                const scroll = viewer.querySelector('[data-pdf-scroll]');
                if (scroll) runtime.resizeObserver.observe(scroll);
            }
        } catch (error) {
            showPdfError(runtime, error);
        }
    }

    function showPdfError(runtime, error) {
        if (!runtime.viewer.isConnected) return;
        console.warn('Drawing PDF preview failed.', error);
        const status = runtime.viewer.querySelector('[data-pdf-status]');
        if (status instanceof HTMLElement) {
            status.hidden = false;
            status.textContent = t('preview.pdfUnavailable');
        }
    }

    function initializePdfViewers() {
        if (!overlay || overlay.classList.contains('is-hidden')) return;
        for (const viewer of overlay.querySelectorAll('[data-pdf-viewer]')) initializePdfViewer(viewer);
    }

    function syncImageOcrLayer(viewer) {
        const canvas = viewer.querySelector('[data-image-canvas]');
        const image = viewer.querySelector('img[data-drawing-image]');
        const layer = viewer.querySelector('[data-ocr-layer]');
        if (!(canvas instanceof HTMLElement) || !(image instanceof HTMLImageElement) || !(layer instanceof HTMLElement) || !image.naturalWidth || !image.naturalHeight) return;
        const canvasWidth = canvas.clientWidth;
        const canvasHeight = canvas.clientHeight;
        if (canvasWidth <= 0 || canvasHeight <= 0) return;
        const imageRatio = image.naturalWidth / image.naturalHeight;
        const canvasRatio = canvasWidth / canvasHeight;
        const width = imageRatio > canvasRatio ? canvasWidth : canvasHeight * imageRatio;
        const height = imageRatio > canvasRatio ? canvasWidth / imageRatio : canvasHeight;
        layer.style.left = `${(canvasWidth - width) / 2}px`;
        layer.style.top = `${(canvasHeight - height) / 2}px`;
        layer.style.width = `${width}px`;
        layer.style.height = `${height}px`;
    }

    function initializeImageOcrLayers() {
        if (!overlay || overlay.classList.contains('is-hidden')) return;
        for (const viewer of overlay.querySelectorAll('[data-image-viewer]')) {
            const image = viewer.querySelector('img[data-drawing-image]');
            const canvas = viewer.querySelector('[data-image-canvas]');
            if (!(image instanceof HTMLImageElement) || !(canvas instanceof HTMLElement) || !viewer.querySelector('[data-ocr-layer]')) continue;
            const update = () => syncImageOcrLayer(viewer);
            image.addEventListener('load', update, { once: true });
            if (image.complete) update();
            if (typeof ResizeObserver === 'function') {
                const observer = new ResizeObserver(update);
                observer.observe(canvas);
                imageOcrResizeObservers.push(observer);
            }
        }
    }

    async function api(action, options = {}) {
        const headers = new Headers(options.headers || { Accept: 'application/json' });
        const config = { credentials: 'same-origin', method: options.method || 'GET', headers };
        if (options.body instanceof FormData) {
            config.body = options.body;
        } else if (options.body !== undefined) {
            headers.set('Content-Type', 'application/json');
            config.body = JSON.stringify(options.body);
        }
        if (config.method !== 'GET') headers.set('X-CSRF-Token', csrf());
        const response = await fetch(`api.php?action=${encodeURIComponent(action)}${options.query || ''}`, config);
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            const error = new Error(apiErrorMessage(data, response.status));
            error.status = response.status;
            error.code = String(data.code || '');
            throw error;
        }
        return data;
    }

    function hasUnsavedReviewChanges() {
        const hasCommentDraft = state.commentDraft.trim() !== '';
        if (!state.drawing || !state.reviewDraft) return hasCommentDraft;
        if (approvalStatus(state.drawing) !== 'pending' && !isAdmin()) return hasCommentDraft;
        const originalProcessCodes = processCodesFrom(state.drawing);
        const processMetadataChanged = originalProcessCodes.length !== state.processCodes.length
            || originalProcessCodes.some((code, index) => code !== state.processCodes[index]);
        return hasCommentDraft
            || state.reviewConfirmed
            || processMetadataChanged
            || REVIEW_FIELDS.some(name => String(state.reviewDraft[name] ?? '') !== String(state.drawing[name] ?? ''));
    }

    function confirmReviewExit() {
        return !hasUnsavedReviewChanges() || window.confirm(t('close.navigateUnsaved'));
    }

    function ensureCloseConfirmation() {
        if (closeConfirmation) return;
        closeConfirmation = document.createElement('div');
        closeConfirmation.className = 'drawing-close-confirm-overlay is-hidden';
        closeConfirmation.setAttribute('aria-hidden', 'true');
        closeConfirmation.innerHTML = `<section class="drawing-close-confirm" role="alertdialog" aria-modal="true" aria-labelledby="drawing-close-confirm-title" aria-describedby="drawing-close-confirm-description drawing-close-confirm-unsaved drawing-close-confirm-busy">
            <div class="drawing-close-confirm-icon" aria-hidden="true">?</div>
            <div class="drawing-close-confirm-copy">
                <h2 id="drawing-close-confirm-title">${esc(t('close.title'))}</h2>
                <p id="drawing-close-confirm-description">${esc(t('close.description'))}</p>
                <p id="drawing-close-confirm-unsaved" class="drawing-close-confirm-warning is-hidden" data-close-confirm-unsaved>${esc(t('close.unsaved'))}</p>
                <p id="drawing-close-confirm-busy" class="drawing-close-confirm-busy is-hidden" data-close-confirm-busy>${esc(t('close.busy'))}</p>
            </div>
            <div class="drawing-close-confirm-actions">
                <button type="button" class="drawing-button secondary" data-close-confirm-action="cancel">${esc(t('common.no'))}</button>
                <button type="button" class="drawing-button primary" data-close-confirm-action="confirm">${esc(t('common.yes'))}</button>
            </div>
        </section>`;
        closeConfirmation.addEventListener('click', event => {
            if (!(event.target instanceof Element)) return;
            const target = event.target.closest('[data-close-confirm-action]');
            if (!(target instanceof HTMLButtonElement)) return;
            if (target.dataset.closeConfirmAction === 'cancel') cancelCloseConfirmation();
            if (target.dataset.closeConfirmAction === 'confirm') closeManager();
        });
        document.body.appendChild(closeConfirmation);
    }

    function ensureArchiveConfirmation() {
        if (archiveConfirmation) return;
        archiveConfirmation = document.createElement('div');
        archiveConfirmation.className = 'drawing-archive-confirm-overlay is-hidden';
        archiveConfirmation.setAttribute('aria-hidden', 'true');
        archiveConfirmation.innerHTML = `<section class="drawing-archive-confirm" role="alertdialog" aria-modal="true" aria-labelledby="drawing-archive-confirm-title" aria-describedby="drawing-archive-confirm-description drawing-archive-confirm-effects drawing-archive-confirm-error">
            <div class="drawing-archive-confirm-icon" aria-hidden="true">!</div>
            <div class="drawing-archive-confirm-copy">
                <span>${esc(t('archive.warning'))}</span>
                <h2 id="drawing-archive-confirm-title">${esc(t('archive.title'))}</h2>
                <p class="drawing-archive-confirm-target" data-archive-confirm-target></p>
                <p id="drawing-archive-confirm-description">${esc(t('archive.description'))}</p>
                <ul id="drawing-archive-confirm-effects">
                    <li>${esc(t('archive.effect.restore'))}</li>
                    <li>${esc(t('archive.effect.search'))}</li>
                    <li>${esc(t('archive.effect.identity'))}</li>
                </ul>
                <p id="drawing-archive-confirm-error" class="drawing-archive-confirm-error is-hidden" data-archive-confirm-error role="alert"></p>
            </div>
            <div class="drawing-archive-confirm-actions">
                <button type="button" class="drawing-button secondary" data-archive-confirm-action="cancel">${esc(t('common.cancel'))}</button>
                <button type="button" class="drawing-button danger" data-archive-confirm-action="confirm">${esc(t('archive.confirm'))}</button>
            </div>
        </section>`;
        archiveConfirmation.addEventListener('click', event => {
            if (!(event.target instanceof Element)) return;
            const target = event.target.closest('[data-archive-confirm-action]');
            if (!(target instanceof HTMLButtonElement)) return;
            if (target.dataset.archiveConfirmAction === 'cancel') cancelArchiveConfirmation();
            if (target.dataset.archiveConfirmAction === 'confirm') archiveDrawing();
        });
        document.body.appendChild(archiveConfirmation);
    }

    function setManagerDialogSuspended(suspended) {
        const managerWindow = overlay?.querySelector('.drawing-plugin-window');
        if (!(managerWindow instanceof HTMLElement)) return;
        managerWindow.inert = suspended;
        if (suspended) managerWindow.setAttribute('aria-hidden', 'true');
        else managerWindow.removeAttribute('aria-hidden');
    }

    function syncCloseConfirmation() {
        ensureCloseConfirmation();
        const open = state.closeConfirmationOpen && Boolean(overlay && !overlay.classList.contains('is-hidden'));
        closeConfirmation.classList.toggle('is-hidden', !open);
        closeConfirmation.setAttribute('aria-hidden', open ? 'false' : 'true');

        const unsaved = closeConfirmation.querySelector('[data-close-confirm-unsaved]');
        unsaved?.classList.toggle('is-hidden', !hasUnsavedReviewChanges());
        const busy = state.uploading || state.approving || state.saving || state.archiving || state.commentSubmitting || Boolean(state.commentResolvingId);
        const busyMessage = closeConfirmation.querySelector('[data-close-confirm-busy]');
        busyMessage?.classList.toggle('is-hidden', !busy);
        const confirmButton = closeConfirmation.querySelector('[data-close-confirm-action="confirm"]');
        if (confirmButton instanceof HTMLButtonElement) confirmButton.disabled = busy;
    }

    function archiveTargetLabel(drawing) {
        const drawingNo = String(drawing?.drawing_no || '').trim() || t('list.drawingFallback', { id: Number(drawing?.id) || '' }).trim();
        const revision = String(drawing?.revision_code || '').trim();
        const title = String(drawing?.title || '').trim();
        return [drawingNo, revision ? t('list.revisionValue', { revision }) : '', title].filter(Boolean).join(' / ');
    }

    function syncArchiveConfirmation() {
        ensureArchiveConfirmation();
        const open = state.archiveConfirmationOpen && Boolean(overlay && !overlay.classList.contains('is-hidden'));
        archiveConfirmation.classList.toggle('is-hidden', !open);
        archiveConfirmation.setAttribute('aria-hidden', open ? 'false' : 'true');

        const target = archiveConfirmation.querySelector('[data-archive-confirm-target]');
        if (target) target.textContent = archiveTargetLabel(state.drawing);
        const error = archiveConfirmation.querySelector('[data-archive-confirm-error]');
        if (error) {
            error.textContent = state.archiveError;
            error.classList.toggle('is-hidden', !state.archiveError);
        }
        const cancelButton = archiveConfirmation.querySelector('[data-archive-confirm-action="cancel"]');
        const confirmButton = archiveConfirmation.querySelector('[data-archive-confirm-action="confirm"]');
        if (cancelButton instanceof HTMLButtonElement) cancelButton.disabled = state.archiving;
        if (confirmButton instanceof HTMLButtonElement) {
            confirmButton.disabled = state.archiving;
            confirmButton.textContent = state.archiving ? t('common.deleting') : t('archive.confirm');
        }
    }

    function requestCloseManager() {
        if (!overlay || overlay.classList.contains('is-hidden') || state.closeConfirmationOpen || state.archiveConfirmationOpen) return false;
        closeConfirmationReturnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        state.closeConfirmationOpen = true;
        syncCloseConfirmation();
        const cancelButton = closeConfirmation?.querySelector('[data-close-confirm-action="cancel"]');
        if (cancelButton instanceof HTMLButtonElement) cancelButton.focus({ preventScroll: true });
        setManagerDialogSuspended(true);
        return true;
    }

    function requestArchiveConfirmation() {
        if (!canEdit() || !state.drawing || state.archiveConfirmationOpen || state.uploading || state.approving || state.saving || state.archiving || state.commentSubmitting || state.commentResolvingId) return false;
        archiveConfirmationReturnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        state.archiveError = '';
        state.archiveConfirmationOpen = true;
        syncArchiveConfirmation();
        const cancelButton = archiveConfirmation?.querySelector('[data-archive-confirm-action="cancel"]');
        if (cancelButton instanceof HTMLButtonElement) cancelButton.focus({ preventScroll: true });
        setManagerDialogSuspended(true);
        return true;
    }

    function cancelArchiveConfirmation() {
        if (!state.archiveConfirmationOpen || state.archiving) return false;
        const target = archiveConfirmationReturnFocus instanceof HTMLElement && document.contains(archiveConfirmationReturnFocus)
            ? archiveConfirmationReturnFocus
            : overlay?.querySelector('[data-action="archive-drawing"]');
        setManagerDialogSuspended(false);
        state.archiveConfirmationOpen = false;
        state.archiveError = '';
        syncArchiveConfirmation();
        if (target instanceof HTMLElement) target.focus({ preventScroll: true });
        archiveConfirmationReturnFocus = null;
        return true;
    }

    function cancelCloseConfirmation() {
        if (!state.closeConfirmationOpen) return false;
        const target = closeConfirmationReturnFocus instanceof HTMLElement && document.contains(closeConfirmationReturnFocus)
            ? closeConfirmationReturnFocus
            : overlay?.querySelector('.drawing-close');
        setManagerDialogSuspended(false);
        if (target instanceof HTMLElement) target.focus({ preventScroll: true });
        state.closeConfirmationOpen = false;
        syncCloseConfirmation();
        closeConfirmationReturnFocus = null;
        return true;
    }

    function focusInitialControl() {
        const target = overlay?.querySelector('[data-field="search"], .drawing-close');
        if (target instanceof HTMLElement) target.focus({ preventScroll: true });
    }

    async function refreshListQuietly() {
        try {
            await loadList();
        } catch (error) {
            console.warn('Drawing manager list refresh failed after a completed mutation.', error);
        }
    }

    function ensureOverlay() {
        if (overlay) return;
        overlay = document.createElement('div');
        overlay.className = 'drawing-plugin-overlay is-hidden';
        overlay.addEventListener('click', handleClick);
        overlay.addEventListener('submit', handleSubmit);
        overlay.addEventListener('input', handleInput);
        overlay.addEventListener('change', handleChange);
        overlay.addEventListener('focusin', handleFocusIn);
        overlay.addEventListener('pointerdown', handlePointerDown);
        document.body.appendChild(overlay);
    }

    async function openManager() {
        ensureOverlay();
        detailRequest++;
        const activeElement = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        const pluginTrigger = document.querySelector(`[data-action="open-plugin"][data-plugin-id="${PLUGIN_ID}"]`);
        returnFocus = pluginTrigger instanceof HTMLElement ? pluginTrigger : activeElement;
        overlay.classList.remove('is-hidden');
        state.loading = true;
        state.error = '';
        state.notice = '';
        state.mode = 'list';
        state.closeConfirmationOpen = false;
        state.archiveConfirmationOpen = false;
        state.archiveError = '';
        state.archiving = false;
        closeConfirmationReturnFocus = null;
        archiveConfirmationReturnFocus = null;
        setDrawing(null);
        resetComments();
        render();
        focusInitialControl();
        try {
            const session = await api('session');
            state.user = session.user || null;
            state.csrf = session.csrf || state.csrf;
            state.status = canEdit() ? 'all' : 'approved';
            state.pagination.page = 1;
            await loadList();
        } catch (error) {
            state.error = error.message;
        } finally {
            state.loading = false;
            render();
            focusInitialControl();
        }
    }

    function closeManager() {
        if (!state.closeConfirmationOpen) return false;
        detailRequest++;
        disconnectPdfPreviewHeight();
        destroyListThumbnails();
        destroyPdfViewers();
        disconnectImageOcrLayers();
        setManagerDialogSuspended(false);
        state.closeConfirmationOpen = false;
        state.archiveConfirmationOpen = false;
        state.archiveError = '';
        state.archiving = false;
        state.mode = 'list';
        closeConfirmationReturnFocus = null;
        archiveConfirmationReturnFocus = null;
        setDrawing(null);
        resetComments();
        syncCloseConfirmation();
        syncArchiveConfirmation();
        overlay?.classList.add('is-hidden');
        const restoreFocus = () => {
            const pluginTrigger = document.querySelector(`[data-action="open-plugin"][data-plugin-id="${PLUGIN_ID}"]`);
            const focusTarget = pluginTrigger instanceof HTMLElement
                ? pluginTrigger
                : returnFocus instanceof HTMLElement && document.contains(returnFocus) ? returnFocus : null;
            if (focusTarget) focusTarget.focus({ preventScroll: true });
        };
        restoreFocus();
        window.setTimeout(restoreFocus, 0);
        return true;
    }

    async function loadList() {
        const requestId = ++listRequest;
        const query = `&q=${encodeURIComponent(state.query)}&status=${encodeURIComponent(state.status)}&page=${encodeURIComponent(state.pagination.page)}&per_page=${encodeURIComponent(state.pagination.perPage)}`;
        const data = await api('plugin-drawing-manager-list', { query });
        if (requestId !== listRequest) return;
        state.drawings = Array.isArray(data.drawings) ? data.drawings : [];
        state.counts = {
            total: Number(data.counts?.total) || 0,
            pending: Number(data.counts?.pending) || 0,
            approved: Number(data.counts?.approved) || 0,
        };
        const returnedPageSize = Number(data.pagination?.per_page);
        state.pagination = {
            page: Math.max(1, Number(data.pagination?.page) || 1),
            perPage: LIST_PAGE_SIZES.includes(returnedPageSize) ? returnedPageSize : state.pagination.perPage,
            total: Math.max(0, Number(data.pagination?.total) || 0),
            totalPages: Math.max(1, Number(data.pagination?.total_pages) || 1),
        };
    }

    async function loadDetail(id) {
        const requestId = ++detailRequest;
        state.loading = true;
        state.error = '';
        state.uploadWarnings = [];
        setDrawing(null);
        resetComments();
        render();
        try {
            const [detailResult, commentsResult] = await Promise.allSettled([
                api('plugin-drawing-manager-detail', { query: `&id=${encodeURIComponent(id)}` }),
                api('plugin-drawing-manager-comments', { query: `&id=${encodeURIComponent(id)}` }),
            ]);
            if (requestId !== detailRequest) return;
            if (detailResult.status === 'rejected') throw detailResult.reason;
            setDrawing(detailResult.value.drawing || null);
            if (commentsResult.status === 'fulfilled') {
                state.comments = Array.isArray(commentsResult.value.comments) ? commentsResult.value.comments : [];
            } else {
                state.commentsError = `${t('error.loadComments')} ${commentsResult.reason?.message || ''}`.trim();
            }
            state.mode = 'detail';
        } catch (error) {
            if (requestId === detailRequest) state.error = error.message;
        } finally {
            if (requestId === detailRequest) {
                state.loading = false;
                render();
            }
        }
    }

    function emptyMarkup() {
        const filtered = state.status !== 'all';
        const title = state.query
            ? t('list.empty.search')
            : !canEdit()
                ? t('list.empty.approved')
                : filtered
                    ? t(state.status === 'pending' ? 'list.empty.pending' : 'list.empty.approved')
                    : t('list.empty.add');
        const body = state.query
            ? t('list.empty.searchHelp')
            : !canEdit()
                ? t('list.empty.viewerHelp')
                : filtered
                    ? t('list.empty.filterHelp')
                    : t('list.empty.uploadHelp');
        return `<div class="drawing-empty">
            <span class="drawing-empty-icon">⌁</span>
            <h2>${esc(title)}</h2>
            <p>${esc(body)}</p>
            ${canEdit() && !state.query ? `<button type="button" class="drawing-button primary" data-action="new-drawing">${esc(t('list.add'))}</button>` : ''}
        </div>`;
    }

    function statusBadge(status, compact = false) {
        return `<span class="drawing-status-badge is-${esc(status)}${compact ? ' is-compact' : ''}"><i></i>${esc(statusLabel(status))}</span>`;
    }

    function cardThumbnailMarkup(item) {
        const fileId = Number(item.primary_file_id) || 0;
        const extension = String(item.primary_file_extension || '').toLowerCase();
        const url = drawingFileUrl(fileId);
        if (!fileId) {
            return `<div class="drawing-thumbnail-placeholder"><span>NO FILE</span><small>${esc(t('common.noFile'))}</small></div>`;
        }
        if (['png', 'jpg', 'jpeg', 'webp'].includes(extension)) {
            return `<div class="drawing-thumbnail-image"><img data-image-thumbnail src="${url}" alt="" loading="lazy" decoding="async"><span data-thumbnail-status>${esc(t('preview.imageLoading'))}</span></div>`;
        }
        if (extension === 'pdf') {
            return `<div class="drawing-thumbnail-pdf" data-pdf-thumbnail data-file-url="${url}"><canvas data-pdf-thumbnail-canvas></canvas><span data-thumbnail-status>${esc(t('preview.pdfLoading'))}</span></div>`;
        }
        return `<div class="drawing-thumbnail-placeholder is-cad"><span>${esc(fileType(extension))}</span><small>${esc(t('preview.cadFile'))}</small></div>`;
    }

    function cardMarkup(item) {
        const extension = fileType(item.primary_file_extension);
        const status = approvalStatus(item);
        const drawingNo = item.drawing_no || t('common.notDetected');
        const title = item.title || t('list.titleNotDetected');
        const accessibleName = item.drawing_no || item.primary_file_name || t('list.drawingFallback', { id: Number(item.id) });
        return `<article class="drawing-card is-${esc(status)}" data-drawing-card="${Number(item.id)}">
            <button type="button" class="drawing-card-open" data-action="detail" data-id="${Number(item.id)}" aria-label="${esc(t('list.itemAria', { name: accessibleName, status: statusLabel(status), action: t(status === 'pending' ? 'list.reviewAndApprove' : 'list.showDetail') }))}"></button>
            <div class="drawing-card-preview" aria-hidden="true">
                ${cardThumbnailMarkup(item)}
                <div class="drawing-card-preview-top"><span>${esc(extension)}</span>${statusBadge(status, true)}</div>
                <small class="drawing-card-revision">${esc(item.revision_code ? `REV ${item.revision_code}` : 'REV —')}</small>
            </div>
            <div class="drawing-card-copy">
                <div class="drawing-card-number"><span>${esc(t('field.drawingNo'))}</span><strong class="${item.drawing_no ? '' : 'is-missing'}">${esc(drawingNo)}</strong></div>
                <h3 class="${item.title ? '' : 'is-missing'}">${esc(title)}</h3>
                <dl>
                    <div><dt>${esc(t('field.customer'))}</dt><dd>${esc(item.customer || '—')}</dd></div>
                    <div><dt>${esc(t('field.material'))}</dt><dd>${esc(item.material || '—')}</dd></div>
                    <div><dt>${esc(t('list.column.process'))}</dt><dd>${esc(processSummary(item) || '—')}</dd></div>
                </dl>
                <footer><span>${esc(item.primary_file_name || t('common.noFile'))}</span><time>${formatDate(item.updated_at)}</time></footer>
            </div>
        </article>`;
    }

    function textItemMarkup(item) {
        const status = approvalStatus(item);
        const drawingNo = item.drawing_no || t('common.notDetected');
        const title = item.title || t('list.titleNotDetected');
        const accessibleName = item.drawing_no || item.primary_file_name || t('list.drawingFallback', { id: Number(item.id) });
        return `<button type="button" class="drawing-text-item is-${esc(status)}" data-action="detail" data-id="${Number(item.id)}" aria-label="${esc(t('list.detailAria', { name: accessibleName }))}">
            <span class="drawing-text-status">${statusBadge(status)}</span>
            <span data-label="${esc(t('list.column.drawingNo'))}" class="${item.drawing_no ? '' : 'is-missing'}"><strong>${esc(drawingNo)}</strong></span>
            <span data-label="${esc(t('list.column.revision'))}">${esc(item.revision_code || '—')}</span>
            <span data-label="${esc(t('list.column.title'))}" class="${item.title ? '' : 'is-missing'}">${esc(title)}</span>
            <span data-label="${esc(t('list.column.customer'))}">${esc(item.customer || '—')}</span>
            <span data-label="${esc(t('list.column.material'))}">${esc(item.material || '—')}</span>
            <span data-label="${esc(t('list.column.updated'))}"><time>${formatDate(item.updated_at)}</time></span>
            <i aria-hidden="true">›</i>
        </button>`;
    }

    function textListMarkup() {
        return `<div class="drawing-text-list">
            <div class="drawing-text-header" aria-hidden="true"><span>${esc(t('list.column.status'))}</span><span>${esc(t('list.column.drawingNo'))}</span><span>${esc(t('list.column.revision'))}</span><span>${esc(t('list.column.title'))}</span><span>${esc(t('list.column.customer'))}</span><span>${esc(t('list.column.material'))}</span><span>${esc(t('list.column.updated'))}</span><i></i></div>
            ${state.drawings.map(textItemMarkup).join('')}
        </div>`;
    }

    function listLayoutSwitchMarkup() {
        const layouts = [['card', '▦', 'list.layout.card'], ['text', '☷', 'list.layout.text']];
        return `<div class="drawing-view-switch" role="group" aria-label="${esc(t('list.layout.label'))}">${layouts.map(([value, icon, key]) => `<button type="button" data-action="list-layout" data-layout="${value}" aria-pressed="${state.listLayout === value ? 'true' : 'false'}" class="${state.listLayout === value ? 'is-active' : ''}"><span aria-hidden="true">${icon}</span>${esc(t(key))}</button>`).join('')}</div>`;
    }

    function setListLayout(layout) {
        if (!['card', 'text'].includes(layout) || state.listLayout === layout) return;
        state.listLayout = layout;
        persistListLayout(layout);
        render();
        const selected = overlay?.querySelector(`[data-action="list-layout"][data-layout="${layout}"]`);
        if (selected instanceof HTMLButtonElement) selected.focus({ preventScroll: true });
    }

    function paginationPageItems() {
        const current = state.pagination.page;
        const totalPages = state.pagination.totalPages;
        const pages = [...new Set([1, totalPages, current - 1, current, current + 1])]
            .filter(page => page >= 1 && page <= totalPages)
            .sort((left, right) => left - right);
        let previous = 0;
        return pages.map(page => {
            const gap = previous && page - previous > 1 ? '<span class="drawing-pagination-gap" aria-hidden="true">…</span>' : '';
            previous = page;
            const currentAttributes = page === current ? ' class="is-current" aria-current="page" disabled' : '';
            return `${gap}<button type="button" data-action="list-page" data-page="${page}" aria-label="${esc(t('list.pagination.goToPage', { page }))}"${currentAttributes}>${page}</button>`;
        }).join('');
    }

    function paginationMarkup() {
        const { page, perPage, total, totalPages } = state.pagination;
        const start = total ? ((page - 1) * perPage) + 1 : 0;
        const end = total ? start + state.drawings.length - 1 : 0;
        const navigation = totalPages > 1 ? `<nav class="drawing-pagination-nav" aria-label="${esc(t('list.pagination.navigation'))}">
            <button type="button" data-action="list-page" data-page="${page - 1}" aria-label="${esc(t('list.pagination.previous'))}" ${page <= 1 ? 'disabled' : ''}>← <span>${esc(t('list.pagination.previous'))}</span></button>
            <div class="drawing-pagination-pages">${paginationPageItems()}</div>
            <button type="button" data-action="list-page" data-page="${page + 1}" aria-label="${esc(t('list.pagination.next'))}" ${page >= totalPages ? 'disabled' : ''}><span>${esc(t('list.pagination.next'))}</span> →</button>
        </nav>` : `<span class="drawing-pagination-page" aria-live="polite">${esc(t('list.pagination.page', { page, pages: totalPages }))}</span>`;
        return `<div class="drawing-pagination" data-pagination-region tabindex="-1">
            <div class="drawing-page-size">
                <label><span>${esc(t('list.pagination.perPage'))}</span><select data-field="page-size">${LIST_PAGE_SIZES.map(size => `<option value="${size}" ${size === perPage ? 'selected' : ''}>${size}</option>`).join('')}</select></label>
                <span>${esc(t('list.pagination.summary', { start, end, total }))}</span>
            </div>
            ${navigation}
        </div>`;
    }

    function listViewMarkup() {
        const visibleCount = Number(state.drawings.length);
        return `<main class="drawing-list-view">
            <div class="drawing-list-intro">
                <div><span class="drawing-eyebrow">DRAWING LIBRARY</span><h2>${esc(t('list.title'))}</h2><p>${esc(t(canEdit() ? 'list.description.editor' : 'list.description.viewer'))}</p></div>
                <div class="drawing-list-summary"><strong>${esc(t('list.showing', { count: visibleCount }))}</strong>${listLayoutSwitchMarkup()}</div>
            </div>
            ${state.loading ? `<div class="drawing-loading">${esc(t('list.loading'))}</div>` : state.drawings.length ? state.listLayout === 'text' ? textListMarkup() : `<div class="drawing-card-grid">${state.drawings.map(cardMarkup).join('')}</div>` : emptyMarkup()}
            ${state.loading ? '' : paginationMarkup()}
        </main>`;
    }

    function field(name, label, options = {}) {
        const value = state.reviewDraft?.[name] ?? options.value ?? '';
        const required = options.required ? 'required' : '';
        const wide = options.wide ? ' drawing-field-wide' : '';
        const ocrTarget = state.ocrTargetField === name ? ' is-ocr-target' : '';
        const ai = options.ai ? `<em>${esc(t('field.verificationTarget'))}</em>` : '';
        const note = options.note ? `<small>${esc(options.note)}</small>` : '';
        if (options.textarea) {
            return `<label class="drawing-field${wide}${ocrTarget}"><span>${esc(label)}${options.required ? `<b>${esc(t('common.required'))}</b>` : ''}${ai}<i data-ocr-target-indicator>${esc(t('ocr.target'))}</i></span><textarea name="${esc(name)}" data-review-field="${esc(name)}" maxlength="${options.max || 1000}" rows="${options.rows || 4}" ${required} placeholder="${esc(options.placeholder || '')}">${esc(value)}</textarea>${note}</label>`;
        }
        return `<label class="drawing-field${wide}${ocrTarget}"><span>${esc(label)}${options.required ? `<b>${esc(t('common.required'))}</b>` : ''}${ai}<i data-ocr-target-indicator>${esc(t('ocr.target'))}</i></span><input name="${esc(name)}" data-review-field="${esc(name)}" maxlength="${options.max || 160}" value="${esc(value)}" ${required} placeholder="${esc(options.placeholder || '')}">${note}</label>`;
    }

    function processChecklistMarkup() {
        const selected = new Set(state.processCodes);
        return `<fieldset class="drawing-process-selector drawing-field-wide">
            <legend>${esc(t('field.processes'))} <small>${esc(t('field.processesHelp'))}</small></legend>
            <div>${PROCESS_OPTIONS.map(([code]) => `<label><input type="checkbox" name="process_codes[]" value="${code}" data-process-code="${code}" ${selected.has(code) ? 'checked' : ''}><span>${esc(processLabel(code))}</span></label>`).join('')}</div>
        </fieldset>`;
    }

    function processMetadataMarkup(drawing) {
        const labels = processLabelsFrom(drawing);
        return `<div><dt>${esc(t('field.processes'))}</dt><dd class="drawing-process-tags">${labels.length ? labels.map(label => `<span>${esc(label)}</span>`).join('') : '—'}</dd></div>`;
    }

    function uploadStepMarkup() {
        return `<section class="drawing-upload-step">
            <div class="drawing-step-heading"><span>1</span><div><h3>${esc(t('upload.stepTitle'))}</h3><p>${esc(t('upload.stepHelp'))}</p></div></div>
            <form data-form="upload" class="drawing-drop-form">
                <label class="drawing-drop-zone">
                    <input type="file" name="file" data-field="drawing-file" required accept=".pdf,.dwg,.dxf,.step,.stp,.iges,.igs,.png,.jpg,.jpeg,.webp" ${state.uploading ? 'disabled' : ''}>
                    <span class="drawing-drop-icon">⇧</span>
                    <strong>${esc(state.selectedFileName || t('upload.choose'))}</strong>
                    <small>${esc(t('upload.aiScope'))}</small>
                    <small>${esc(t('upload.externalNotice'))}</small>
                </label>
                <button type="button" class="drawing-button primary" data-action="choose-file" ${state.uploading ? 'disabled' : ''}>${esc(t(state.uploading ? 'upload.analyzing' : 'upload.choose'))}</button>
            </form>
            <p class="drawing-upload-footnote">${esc(t('upload.supported'))}</p>
        </section>`;
    }

    function createViewMarkup() {
        return `<main class="drawing-create-view">
            <button type="button" class="drawing-back" data-action="back-list">← ${esc(t('list.back'))}</button>
            <div class="drawing-page-heading"><span class="drawing-eyebrow">ADD DRAWING</span><h2>${esc(t('upload.title'))}</h2><p>${esc(t('upload.description'))}</p></div>
            ${uploadStepMarkup()}
        </main>`;
    }

    function detailFileMarkup(file) {
        const url = drawingFileUrl(file.id);
        const extension = String(file.extension || '').toLowerCase();
        const zoom = imageZoom(file.id);
        const pdfZoom = Math.max(75, Math.min(250, Number(state.pdfZooms[String(file.id)]) || 100));
        const pdfPage = Math.max(1, Number(state.pdfPages[String(file.id)]) || 1);
        let preview = `<div class="drawing-cad-preview"><span>${esc(fileType(extension))}</span><strong>${esc(t('preview.cadTitle'))}</strong><p>${esc(t('preview.cadHelp'))}</p></div>`;
        if (extension === 'pdf') preview = `<div class="drawing-preview-frame drawing-pdf-viewer" data-pdf-viewer data-file-id="${Number(file.id)}" data-file-url="${url}">
            <div class="drawing-pdf-toolbar" role="toolbar" aria-label="${esc(t('preview.pdfToolbar'))}">
                <div><button type="button" data-action="pdf-prev" aria-label="${esc(t('preview.previousPage'))}" disabled>←</button><output data-pdf-page-value aria-live="polite">${pdfPage} / …</output><button type="button" data-action="pdf-next" aria-label="${esc(t('preview.nextPage'))}" disabled>→</button></div>
                <div><button type="button" data-action="pdf-zoom-out" aria-label="${esc(t('preview.pdfZoomOut'))}" ${pdfZoom <= 75 ? 'disabled' : ''}>−</button><output data-pdf-zoom-value aria-live="polite">${pdfZoom}%</output><button type="button" data-action="pdf-zoom-in" aria-label="${esc(t('preview.pdfZoomIn'))}" ${pdfZoom >= 250 ? 'disabled' : ''}>＋</button><button type="button" class="drawing-pdf-fit" data-action="pdf-fit">${esc(t('preview.fitWidth'))}</button></div>
            </div>
            <div class="drawing-pdf-scroll" data-pdf-scroll tabindex="0" role="region" aria-label="${esc(t('preview.pdfRegion'))}">
                <div class="drawing-pdf-page" data-pdf-page><canvas data-pdf-canvas aria-label="${esc(t('preview.pdfCanvas', { name: file.original_name }))}"></canvas>${ocrLayerMarkup(file, pdfPage)}</div>
                <p class="drawing-pdf-status" data-pdf-status role="status">${esc(t('preview.pdfLoading'))}</p>
            </div>
        </div>`;
        if (['png', 'jpg', 'jpeg', 'webp'].includes(extension)) preview = `<div class="drawing-image-viewer" data-image-viewer data-file-id="${Number(file.id)}" data-zoom="${zoom}" style="--drawing-image-zoom:${zoom}%">
            <div class="drawing-image-toolbar" role="toolbar" aria-label="${esc(t('preview.imageToolbar'))}">
                <span>${esc(t('preview.imageDisplay'))}</span>
                <div>
                    <button type="button" data-action="image-zoom-out" aria-label="${esc(t('preview.imageZoomOut'))}" ${zoom <= IMAGE_ZOOM_MIN ? 'disabled' : ''}>−</button>
                    <output data-image-zoom-value role="status" aria-live="polite" aria-atomic="true" aria-label="${esc(t('preview.currentZoom'))}">${zoom}%</output>
                    <button type="button" data-action="image-zoom-in" aria-label="${esc(t('preview.imageZoomIn'))}" ${zoom >= IMAGE_ZOOM_MAX ? 'disabled' : ''}>＋</button>
                    <button type="button" class="drawing-image-fit" data-action="image-fit" aria-label="${esc(t('preview.imageFitAria'))}">${esc(t('preview.showAll'))}</button>
                </div>
            </div>
            <div class="drawing-image-viewport" data-image-viewport tabindex="0" role="region" aria-label="${esc(t('preview.imageViewport'))}">
                <div class="drawing-image-canvas" data-image-canvas><img class="drawing-preview-image" data-drawing-image src="${url}" alt="${esc(t('preview.uploadedDrawing', { name: file.original_name }))}" loading="eager" decoding="async" draggable="false">${ocrLayerMarkup(file, 1)}</div>
            </div>
        </div>`;
        return `<div class="drawing-file-detail">${preview}<div class="drawing-file-bar"><div><span class="drawing-file-type">${esc(fileType(extension))}</span><p><strong>${esc(file.original_name)}</strong><small>${formatBytes(file.size_bytes)} · ${esc(file.uploader_name || '')}</small></p></div><a class="drawing-button secondary" href="${url}" target="_blank" rel="noopener">${esc(t('common.openFile'))}</a></div></div>`;
    }

    function extractionSummaryMarkup(drawing) {
        const source = sourceLabel(drawing.extraction_source);
        const count = aiFieldCount(drawing);
        return `<div class="drawing-extraction-summary"><span>${esc(t('metadata.initialCandidate', { source }))}</span><strong>${esc(t('metadata.aiCount', { count }))}</strong><small>${esc(t('metadata.aiTarget'))}</small></div>`;
    }

    function editableInformationFieldsMarkup() {
        return `<div class="drawing-review-required">
            ${field('drawing_no', t('field.drawingNo'), { required: true, ai: true, max: 80, placeholder: t('field.placeholderFromDrawing') })}
            ${field('revision_code', t('field.revision'), { ai: true, max: 40, placeholder: t('field.placeholderRevision'), note: t('field.revisionNoneHelp') })}
            ${field('title', t('field.title'), { required: true, ai: true, max: 160, placeholder: t('field.placeholderFromDrawing') })}
        </div>
        <div class="drawing-review-optional">
            <div><span>${esc(t('field.optional'))}</span><small>${esc(t('field.optionalHelp'))}</small></div>
            <div class="drawing-form-grid">
                ${field('customer', t('field.customer'), { max: 160, placeholder: t('field.placeholderCustomer') })}
                ${field('material', t('field.material'), { max: 120, placeholder: t('field.placeholderMaterial') })}
                ${processChecklistMarkup()}
                ${field('process', t('field.process'), { max: 160, wide: true, placeholder: t('field.placeholderProcess') })}
                ${field('owner_department', t('field.department'), { max: 120 })}
                ${field('notes', t('field.notes'), { max: 2000, wide: true, textarea: true, rows: 3, placeholder: t('field.placeholderNotes') })}
            </div>
        </div>`;
    }

    function reviewFormMarkup(drawing) {
        return `<aside class="drawing-review-panel" aria-labelledby="drawing-review-heading">
            <div class="drawing-review-banner"><div>${statusBadge('pending')}</div><h3 id="drawing-review-heading">${esc(t('review.title'))}</h3><p>${esc(t('review.help'))}</p></div>
            ${extractionSummaryMarkup(drawing)}
            <div class="drawing-ocr-instructions" role="status" aria-live="polite"><span>${esc(t('ocr.target'))}：<strong data-ocr-target-name>${esc(ocrFieldLabel(state.ocrTargetField || 'drawing_no'))}</strong></span><small>${esc(t('ocr.replaceThenAppend'))}</small><b data-ocr-feedback></b></div>
            ${state.uploadWarnings.length ? `<div class="drawing-warning-list">${state.uploadWarnings.map(warning => `<p>⚠ ${esc(warning)}</p>`).join('')}</div>` : ''}
            <form data-form="approve" class="drawing-review-form">
                <input type="hidden" name="id" value="${Number(drawing.id)}">
                ${editableInformationFieldsMarkup()}
                <label class="drawing-review-confirm">
                    <input type="checkbox" name="confirmed" value="1" data-field="review-confirmed" required ${state.reviewConfirmed ? 'checked' : ''}>
                    <span><strong>${esc(t('review.confirmed'))}</strong><small>${esc(t('review.confirmedHelp'))}</small></span>
                </label>
                <button type="submit" class="drawing-button primary large drawing-approve-button" ${state.approving ? 'disabled' : ''}>${esc(t(state.approving ? 'review.approving' : 'review.approve'))}</button>
            </form>
        </aside>`;
    }

    function adminEditFormMarkup(drawing) {
        const pending = approvalStatus(drawing) === 'pending';
        const busy = state.saving || state.approving;
        const actions = pending
            ? `<label class="drawing-review-confirm">
                    <input type="checkbox" name="confirmed" value="1" data-field="review-confirmed" ${state.reviewConfirmed ? 'checked' : ''}>
                    <span><strong>${esc(t('review.confirmed'))}</strong><small>${esc(t('review.adminConfirmHelp'))}</small></span>
                </label>
                <div class="drawing-admin-edit-actions">
                    <button type="submit" class="drawing-button secondary large" data-submit="save" ${busy ? 'disabled' : ''}>${esc(t(state.saving ? 'common.saving' : 'review.savePending'))}</button>
                    <button type="submit" class="drawing-button primary large drawing-approve-button" data-submit="approve" ${busy ? 'disabled' : ''}>${esc(t(state.approving ? 'review.approving' : 'review.approve'))}</button>
                </div>`
            : `<button type="submit" class="drawing-button primary large drawing-approve-button" data-submit="save" ${state.saving ? 'disabled' : ''}>${esc(t(state.saving ? 'common.saving' : 'review.saveChanges'))}</button>`;
        return `<aside class="drawing-review-panel is-admin-edit" aria-labelledby="drawing-admin-edit-heading">
            <div class="drawing-review-banner"><div>${statusBadge(approvalStatus(drawing))}</div><h3 id="drawing-admin-edit-heading">${esc(t('review.adminTitle'))}</h3><p>${esc(t(pending ? 'review.pendingAdminHelp' : 'review.approvedAdminHelp'))}</p></div>
            ${extractionSummaryMarkup(drawing)}
            <div class="drawing-ocr-instructions" role="status" aria-live="polite"><span>${esc(t('ocr.target'))}：<strong data-ocr-target-name>${esc(ocrFieldLabel(state.ocrTargetField || 'drawing_no'))}</strong></span><small>${esc(t('ocr.replaceThenAppend'))}</small><b data-ocr-feedback></b></div>
            <form data-form="admin-update" class="drawing-review-form">
                <input type="hidden" name="id" value="${Number(drawing.id)}">
                ${editableInformationFieldsMarkup()}
                ${actions}
            </form>
        </aside>`;
    }

    function approvedMetadataMarkup(drawing) {
        const approver = drawing.approver_name || drawing.approved_by_name || '';
        return `<aside class="drawing-metadata">
            <div class="drawing-metadata-heading"><h3>${esc(t('metadata.title'))}</h3>${statusBadge('approved')}</div>
            <dl>
                <div><dt>${esc(t('field.drawingNo'))}</dt><dd>${esc(drawing.drawing_no || '—')}</dd></div>
                <div><dt>${esc(t('field.title'))}</dt><dd>${esc(drawing.title || '—')}</dd></div>
                <div><dt>${esc(t('field.revision'))}</dt><dd>${esc(drawing.revision_code || '—')}</dd></div>
                <div><dt>${esc(t('field.customer'))}</dt><dd>${esc(drawing.customer || '—')}</dd></div>
                <div><dt>${esc(t('field.material'))}</dt><dd>${esc(drawing.material || '—')}</dd></div>
                ${processMetadataMarkup(drawing)}
                <div><dt>${esc(t('field.process'))}</dt><dd>${esc(drawing.process || '—')}</dd></div>
                <div><dt>${esc(t('field.department'))}</dt><dd>${esc(drawing.owner_department || '—')}</dd></div>
            </dl>
            ${drawing.notes ? `<div class="drawing-notes"><span>${esc(t('field.notes'))}</span><p>${esc(drawing.notes)}</p></div>` : ''}
            <div class="drawing-approval-note"><strong>${esc(t('status.approved'))}</strong><span>${formatDate(drawing.approved_at)}${approver ? ` · ${esc(approver)}` : ''}</span></div>
            ${extractionSummaryMarkup(drawing)}
        </aside>`;
    }

    function pendingReadOnlyMarkup(drawing) {
        return `<aside class="drawing-metadata"><div class="drawing-metadata-heading"><h3>${esc(t('metadata.title'))}</h3>${statusBadge('pending')}</div><p class="drawing-pending-readonly">${esc(t('metadata.pendingHelp'))}</p>${extractionSummaryMarkup(drawing)}</aside>`;
    }

    function commentMarkup(comment) {
        const id = Number(comment?.id) || 0;
        const body = String(comment?.body ?? comment?.comment ?? '');
        const author = String(comment?.author_name || comment?.creator_name || comment?.created_by_name || comment?.user_name || t('comments.userFallback'));
        const resolved = Boolean(comment?.resolved) || Boolean(comment?.resolved_at) || String(comment?.status || '') === 'resolved';
        const resolver = String(comment?.resolved_by_name || comment?.resolver_name || '');
        const resolving = state.commentResolvingId === id;
        return `<article class="drawing-comment${resolved ? ' is-resolved' : ''}">
            <header><div><strong>${esc(author)}</strong><time>${formatDateTime(comment?.created_at)}</time></div><span>${esc(t(resolved ? 'comments.resolved' : 'comments.unresolved'))}</span></header>
            <p>${esc(body)}</p>
            <footer>
                ${resolved ? `<small>${formatDateTime(comment?.resolved_at)}${resolver ? ` · ${esc(resolver)}` : ''}</small>` : `<small>${esc(t('comments.awaitingAdmin'))}</small>`}
                ${isAdmin() && !resolved ? `<button type="button" class="drawing-button secondary" data-action="resolve-comment" data-comment-id="${id}" ${resolving ? 'disabled' : ''}>${esc(t(resolving ? 'comments.updating' : 'comments.markResolved'))}</button>` : ''}
            </footer>
        </article>`;
    }

    function commentsMarkup(drawing) {
        const comments = Array.isArray(state.comments) ? state.comments : [];
        const openCount = comments.filter(comment => !comment?.resolved && !comment?.resolved_at && String(comment?.status || '') !== 'resolved').length;
        return `<section class="drawing-comments" aria-labelledby="drawing-comments-heading">
            <header class="drawing-comments-heading">
                <div><span>${esc(t('comments.section'))}</span><h3 id="drawing-comments-heading">${esc(t('comments.title'))}</h3><p>${esc(t('comments.help'))}</p></div>
                <strong>${esc(openCount ? t('comments.openCount', { count: openCount }) : t('comments.noneOpen'))}</strong>
            </header>
            <form data-form="comment-create" class="drawing-comment-form">
                <input type="hidden" name="drawing_id" value="${Number(drawing.id)}">
                <label><span>${esc(t('comments.label'))}</span><textarea name="body" data-field="comment-body" maxlength="2000" rows="3" required placeholder="${esc(t('comments.placeholder'))}">${esc(state.commentDraft)}</textarea></label>
                <button type="submit" class="drawing-button primary" ${state.commentSubmitting ? 'disabled' : ''}>${esc(t(state.commentSubmitting ? 'comments.sending' : 'comments.send'))}</button>
            </form>
            ${state.commentsError ? `<p class="drawing-comments-error" role="alert">${esc(state.commentsError)}</p>` : ''}
            <div class="drawing-comment-list">
                ${comments.length ? comments.map(commentMarkup).join('') : `<p class="drawing-comments-empty">${esc(t('comments.empty'))}</p>`}
            </div>
        </section>`;
    }

    function detailViewMarkup() {
        const drawing = state.drawing;
        if (state.loading) return `<main class="drawing-detail-view"><div class="drawing-loading">${esc(t('detail.loading'))}</div></main>`;
        if (!drawing) return `<main class="drawing-detail-view">${emptyMarkup()}</main>`;
        const status = approvalStatus(drawing);
        const isAdminEdit = isAdmin();
        const isReview = status === 'pending' && canEdit() && !isAdminEdit;
        const isEditable = isReview || isAdminEdit;
        const drawingNo = drawing.drawing_no || t('detail.drawingNoNotDetected');
        const title = drawing.title || t('list.titleNotDetected');
        const side = isAdminEdit ? adminEditFormMarkup(drawing) : isReview ? reviewFormMarkup(drawing) : status === 'approved' ? approvedMetadataMarkup(drawing) : pendingReadOnlyMarkup(drawing);
        const revisionBadge = drawing.revision_code ? `<span class="drawing-revision-badge"><small>REV</small><b>${esc(drawing.revision_code)}</b></span>` : '';
        const deleteButton = canEdit() ? `<button type="button" class="drawing-button danger drawing-delete-button" data-action="archive-drawing">${esc(t('archive.confirm'))}</button>` : '';
        const detailActions = revisionBadge || deleteButton ? `<div class="drawing-detail-actions">${revisionBadge}${deleteButton}</div>` : '';
        const previewHeading = isEditable ? `<header class="drawing-preview-heading">
            <div><span aria-hidden="true">▧</span><div><h3 id="drawing-preview-heading">${esc(t('preview.heading'))}</h3><p>${esc(t('preview.help'))}</p></div></div>
            <small>${esc(t('preview.clickRegion'))}</small>
        </header>` : '';
        return `<main class="drawing-detail-view is-${esc(status)}">
            <div class="drawing-detail-hero">
                <div class="drawing-detail-identity"><div class="drawing-detail-status">${statusBadge(status)}<span>${esc(t(status === 'pending' ? 'detail.pendingHelp' : 'detail.approvedHelp'))}</span></div><h2 class="drawing-detail-title"><span>${esc(drawingNo)}</span><span aria-hidden="true">/</span><span>${esc(title)}</span></h2><p>${esc(t('detail.added', { date: formatDate(drawing.created_at), user: drawing.creator_name || '' }))}</p></div>
                ${detailActions}
            </div>
            <div class="drawing-detail-layout${isEditable ? ' has-review' : ''}">
                <section class="drawing-detail-main drawing-preview-column"${isEditable ? ' aria-labelledby="drawing-preview-heading"' : ` aria-label="${esc(t('detail.previewLabel'))}"`}>
                    ${previewHeading}<div class="drawing-preview-stack">${(drawing.files || []).map(detailFileMarkup).join('')}</div>
                </section>
                ${side}
            </div>
            ${commentsMarkup(drawing)}
        </main>`;
    }

    function disconnectPdfPreviewHeight() {
        if (pdfPreviewResizeObserver) pdfPreviewResizeObserver.disconnect();
        if (pdfPreviewFrameRequest) window.cancelAnimationFrame(pdfPreviewFrameRequest);
        if (pdfPreviewResizeHandler) window.removeEventListener('resize', pdfPreviewResizeHandler);
        pdfPreviewResizeObserver = null;
        pdfPreviewFrameRequest = 0;
        pdfPreviewResizeHandler = null;
    }

    function syncPdfPreviewHeight() {
        disconnectPdfPreviewHeight();
        if (!overlay || overlay.classList.contains('is-hidden') || state.mode !== 'detail') return;

        const layout = overlay.querySelector('.drawing-detail-layout:not(.has-review)');
        const metadata = layout?.querySelector(':scope > .drawing-metadata');
        const fileDetails = layout?.querySelectorAll('.drawing-file-detail');
        const pdfFrames = layout?.querySelectorAll('.drawing-preview-frame');
        if (!(layout instanceof HTMLElement)
            || !(metadata instanceof HTMLElement)
            || fileDetails?.length !== 1
            || pdfFrames?.length !== 1) return;

        const applyHeight = () => {
            if (!layout.isConnected || !metadata.isConnected) return;
            const height = Math.round(metadata.getBoundingClientRect().height);
            if (height <= 0) return;
            layout.style.setProperty('--drawing-pdf-preview-height', `${height}px`);
            layout.classList.add('has-synced-pdf-height');
        };
        const settleHeight = () => {
            if (pdfPreviewFrameRequest) window.cancelAnimationFrame(pdfPreviewFrameRequest);
            let remainingPasses = 2;
            const update = () => {
                pdfPreviewFrameRequest = 0;
                applyHeight();
                remainingPasses -= 1;
                if (remainingPasses > 0) pdfPreviewFrameRequest = window.requestAnimationFrame(update);
            };
            pdfPreviewFrameRequest = window.requestAnimationFrame(update);
        };
        applyHeight();
        settleHeight();
        pdfPreviewResizeHandler = settleHeight;
        window.addEventListener('resize', pdfPreviewResizeHandler);
        if (typeof ResizeObserver === 'function') {
            pdfPreviewResizeObserver = new ResizeObserver(settleHeight);
            pdfPreviewResizeObserver.observe(metadata);
        }
    }

    function statusFiltersMarkup() {
        if (!canEdit()) return '';
        const filters = [
            ['all', t('status.all'), state.counts.total],
            ['pending', t('status.pending'), state.counts.pending],
            ['approved', t('status.approved'), state.counts.approved],
        ];
        return `<div class="drawing-status-filters" role="group" aria-label="${esc(t('status.filterLabel'))}">${filters.map(([value, label, count]) => `<button type="button" class="drawing-status-filter${state.status === value ? ' is-active' : ''}" data-action="status-filter" data-status="${value}" aria-pressed="${state.status === value ? 'true' : 'false'}"><span>${esc(label)}</span><b>${Number(count) || 0}</b></button>`).join('')}</div>`;
    }

    function toolbarMarkup() {
        if (state.mode !== 'list') return '';
        return `<div class="drawing-toolbar">
            <label class="drawing-search"><span aria-hidden="true">⌕</span><input type="search" data-field="search" value="${esc(state.query)}" aria-label="${esc(t('list.searchLabel'))}" placeholder="${esc(t('list.searchPlaceholder'))}"></label>
            ${statusFiltersMarkup()}
            ${canEdit() ? `<button type="button" class="drawing-button primary" data-action="new-drawing">＋ ${esc(t('list.add'))}</button>` : ''}
        </div>`;
    }

    function render() {
        if (!overlay) return;
        disconnectPdfPreviewHeight();
        destroyListThumbnails();
        destroyPdfViewers();
        disconnectImageOcrLayers();
        const activeElement = document.activeElement;
        const restoreSearchFocus = activeElement instanceof HTMLInputElement && activeElement.dataset.field === 'search';
        const selectionStart = restoreSearchFocus ? activeElement.selectionStart : null;
        const selectionEnd = restoreSearchFocus ? activeElement.selectionEnd : null;
        const content = state.mode === 'create' ? createViewMarkup() : state.mode === 'detail' ? detailViewMarkup() : listViewMarkup();
        const backDisabled = state.mode === 'detail' && detailBusy() ? ' disabled aria-disabled="true"' : '';
        overlay.innerHTML = `<section class="drawing-plugin-window" role="dialog" aria-modal="true" aria-label="${esc(t('plugin.name'))}">
            <header class="drawing-plugin-header">
                <div class="drawing-plugin-brand${state.mode === 'detail' ? ' has-back' : ''}">${state.mode === 'detail' ? `<button type="button" class="drawing-header-back" data-action="back-list"${backDisabled}>← <span>${esc(t('list.back'))}</span></button>` : '<span>📐</span>'}<div><h1>${esc(t('plugin.name'))}</h1><p>DRAWING DOCUMENT LIBRARY</p></div></div>
                <div class="drawing-plugin-count"><b>${Number(state.counts.total) || 0}</b><span>${esc(t('list.total'))}</span>${canEdit() && state.counts.pending ? `<em>${Number(state.counts.pending)} ${esc(t('status.pending'))}</em>` : ''}</div>
                <button type="button" class="drawing-close" data-action="close" aria-label="${esc(t('common.close'))}">×</button>
            </header>
            ${toolbarMarkup()}
            ${state.error ? `<div class="drawing-alert error" role="alert">${esc(state.error)}<button type="button" data-action="dismiss-alert" aria-label="${esc(t('common.closeError'))}">×</button></div>` : ''}
            ${state.notice ? `<div class="drawing-alert success" role="status" aria-live="polite">${esc(state.notice)}<button type="button" data-action="dismiss-alert" aria-label="${esc(t('common.closeNotice'))}">×</button></div>` : ''}
            <div class="drawing-plugin-body">${content}</div>
        </section>`;
        if (!state.closeConfirmationOpen && restoreSearchFocus) {
            const search = overlay.querySelector('[data-field="search"]');
            if (search instanceof HTMLInputElement) {
                search.focus({ preventScroll: true });
                if (selectionStart !== null && selectionEnd !== null) search.setSelectionRange(selectionStart, selectionEnd);
            }
        }
        syncCloseConfirmation();
        syncArchiveConfirmation();
        syncPdfPreviewHeight();
        initializeImageOcrLayers();
        initializePdfViewers();
        initializeListThumbnails();
        if (state.closeConfirmationOpen) {
            const cancelButton = closeConfirmation?.querySelector('[data-close-confirm-action="cancel"]');
            if (cancelButton instanceof HTMLButtonElement) cancelButton.focus({ preventScroll: true });
            setManagerDialogSuspended(true);
        }
        if (state.archiveConfirmationOpen) {
            const cancelButton = archiveConfirmation?.querySelector('[data-archive-confirm-action="cancel"]');
            if (!state.archiving && cancelButton instanceof HTMLButtonElement) cancelButton.focus({ preventScroll: true });
            setManagerDialogSuspended(true);
        }
    }

    async function uploadFile(form) {
        if (state.uploading) return;
        const fileInput = form.querySelector('input[type="file"]');
        if (!fileInput?.files?.length) return;
        const file = fileInput.files[0];
        state.uploading = true;
        state.error = '';
        state.notice = '';
        render();
        try {
            const body = new FormData();
            body.append('file', file);
            const data = await api('plugin-drawing-manager-upload', { method: 'POST', body });
            if (!data.drawing) throw new Error(t('error.loadSaved'));
            setDrawing(data.drawing);
            const warningItems = Array.isArray(data.extraction?.warning_items) ? data.extraction.warning_items : [];
            const legacyWarnings = Array.isArray(data.extraction?.warnings) ? data.extraction.warnings : [];
            state.uploadWarnings = warningItems.length
                ? warningItems.map(warningText)
                : (legacyWarnings.length ? [t('warning.generic')] : []);
            state.mode = 'detail';
            state.selectedFileName = '';
            state.notice = t('notice.uploaded');
            await refreshListQuietly();
        } catch (error) {
            state.error = error.message;
            state.selectedFileName = '';
        } finally {
            state.uploading = false;
            render();
        }
    }

    async function selectStatus(status) {
        if (!['all', 'pending', 'approved'].includes(status) || state.status === status) return;
        state.status = status;
        state.pagination.page = 1;
        state.loading = true;
        state.error = '';
        render();
        try {
            await loadList();
        } catch (error) {
            state.error = error.message;
        } finally {
            state.loading = false;
            render();
        }
    }

    async function selectListPage(page) {
        if (!Number.isInteger(page) || page < 1 || page > state.pagination.totalPages || page === state.pagination.page || state.loading) return;
        const previousPage = state.pagination.page;
        state.pagination.page = page;
        state.loading = true;
        state.error = '';
        render();
        try {
            await loadList();
        } catch (error) {
            state.pagination.page = previousPage;
            state.error = error.message;
        } finally {
            state.loading = false;
            render();
            const region = overlay?.querySelector('[data-pagination-region]');
            if (region instanceof HTMLElement) region.focus({ preventScroll: true });
        }
    }

    async function selectListPageSize(pageSize) {
        if (!LIST_PAGE_SIZES.includes(pageSize) || pageSize === state.pagination.perPage || state.loading) return;
        const previousPagination = { ...state.pagination };
        state.pagination.perPage = pageSize;
        state.pagination.page = 1;
        persistListPageSize(pageSize);
        state.loading = true;
        state.error = '';
        render();
        try {
            await loadList();
        } catch (error) {
            state.pagination = previousPagination;
            persistListPageSize(previousPagination.perPage);
            state.error = error.message;
        } finally {
            state.loading = false;
            render();
            const selector = overlay?.querySelector('[data-field="page-size"]');
            if (selector instanceof HTMLSelectElement) selector.focus({ preventScroll: true });
        }
    }

    async function archiveDrawing() {
        if (state.archiving || !state.archiveConfirmationOpen || !canEdit() || !state.drawing) return;
        const drawing = state.drawing;
        const drawingId = Number(drawing.id);
        if (!drawingId) return;
        const drawingLabel = archiveTargetLabel(drawing);
        const status = approvalStatus(drawing);
        state.archiving = true;
        state.archiveError = '';
        syncArchiveConfirmation();

        try {
            await api('plugin-drawing-manager-archive', {
                method: 'POST',
                body: {
                    id: drawingId,
                    expected_row_version: Number(drawing.row_version) || 0,
                    confirmed: true,
                },
            });

            state.drawings = state.drawings.filter(item => Number(item.id) !== drawingId);
            state.counts.total = Math.max(0, Number(state.counts.total) - 1);
            state.counts[status] = Math.max(0, Number(state.counts[status]) - 1);
            state.archiveConfirmationOpen = false;
            state.archiveError = '';
            state.archiving = false;
            archiveConfirmationReturnFocus = null;
            setManagerDialogSuspended(false);
            setDrawing(null);
            resetComments();
            state.uploadWarnings = [];
            state.mode = 'list';
            state.error = '';
            state.notice = t('notice.deleted', { drawing: drawingLabel });
            state.loading = true;
            render();
            try {
                await loadList();
            } catch (error) {
                state.error = t('error.deletedRefresh', { message: error.message });
            } finally {
                state.loading = false;
                render();
                focusInitialControl();
            }
        } catch (error) {
            state.archiving = false;
            state.archiveError = error.code === 'drawing_conflict'
                ? t('error.deleteConflict')
                : t('error.delete', { message: error.message });
            syncArchiveConfirmation();
            const confirmButton = archiveConfirmation?.querySelector('[data-archive-confirm-action="confirm"]');
            if (confirmButton instanceof HTMLButtonElement) confirmButton.focus({ preventScroll: true });
        }
    }

    function syncOcrTargetUi() {
        if (!overlay) return;
        for (const label of overlay.querySelectorAll('.drawing-field.is-ocr-target')) label.classList.remove('is-ocr-target');
        const input = overlay.querySelector(`[data-review-field="${state.ocrTargetField}"]`);
        input?.closest('.drawing-field')?.classList.add('is-ocr-target');
        const targetName = overlay.querySelector('[data-ocr-target-name]');
        if (targetName) targetName.textContent = state.ocrTargetField ? ocrFieldLabel(state.ocrTargetField) : t('ocr.noneSelected');
    }

    function handleFocusIn(event) {
        if (restoringOcrFocus || !(event.target instanceof HTMLInputElement || event.target instanceof HTMLTextAreaElement)) return;
        const field = String(event.target.dataset.reviewField || '');
        if (!REVIEW_FIELDS.includes(field)) return;
        state.ocrTargetField = field;
        state.ocrInsertStarted = false;
        state.ocrAppliedKeys = [];
        syncOcrTargetUi();
        const feedback = overlay?.querySelector('[data-ocr-feedback]');
        if (feedback) feedback.textContent = '';
    }

    function handlePointerDown(event) {
        if (!(event.target instanceof Element)) return;
        if (event.target.closest('[data-action="insert-ocr-region"]')) event.preventDefault();
    }

    function insertOcrRegion(target) {
        if (!state.drawing || !state.reviewDraft || (!isAdmin() && (approvalStatus(state.drawing) !== 'pending' || !canEdit()))) return;
        const fileId = String(target.dataset.fileId || '');
        const regionIndex = Number(target.dataset.regionIndex);
        const file = (state.drawing.files || []).find(candidate => String(candidate.id) === fileId);
        const region = normalizedOcrRegions(file)[regionIndex];
        if (!region) return;

        const field = REVIEW_FIELDS.includes(state.ocrTargetField) ? state.ocrTargetField : String(region.field);
        const input = overlay?.querySelector(`[data-review-field="${field}"]`);
        if (!(input instanceof HTMLInputElement || input instanceof HTMLTextAreaElement)) return;
        const regionKey = `${fileId}:${regionIndex}`;
        const feedback = overlay?.querySelector('[data-ocr-feedback]');
        if (state.ocrAppliedKeys.includes(regionKey)) {
            if (feedback) feedback.textContent = t('ocr.alreadyEntered');
            return;
        }

        const current = String(state.reviewDraft[field] ?? '');
        let next = String(region.text);
        if (state.ocrInsertStarted && current !== '') {
            const separator = /\s$/u.test(current) || /^\s/u.test(next) ? '' : ' ';
            next = `${current}${separator}${next}`;
        }
        const maximum = input.maxLength > 0 ? input.maxLength : 2000;
        next = next.slice(0, maximum);
        state.reviewDraft[field] = next;
        state.ocrInsertStarted = true;
        state.ocrAppliedKeys.push(regionKey);
        input.value = next;
        target.classList.add('is-used');
        target.setAttribute('aria-pressed', 'true');
        if (feedback) {
            const feedbackKey = current !== '' && state.ocrAppliedKeys.length > 1 ? 'ocr.feedbackAdded' : 'ocr.feedbackInserted';
            feedback.textContent = t(feedbackKey, { sourceField: ocrFieldLabel(region.field), text: region.text, targetField: ocrFieldLabel(field) });
        }
        restoringOcrFocus = true;
        input.focus({ preventScroll: true });
        input.setSelectionRange(next.length, next.length);
        restoringOcrFocus = false;
        syncOcrTargetUi();
    }

    async function updatePdfView(target, action) {
        const viewer = target.closest('[data-pdf-viewer]');
        if (!(viewer instanceof HTMLElement)) return;
        const fileId = String(viewer.dataset.fileId || '');
        const runtime = pdfViewers.get(fileId);
        if (!runtime?.document) return;
        if (action === 'pdf-prev') state.pdfPages[fileId] = Math.max(1, (Number(state.pdfPages[fileId]) || 1) - 1);
        if (action === 'pdf-next') state.pdfPages[fileId] = Math.min(runtime.document.numPages, (Number(state.pdfPages[fileId]) || 1) + 1);
        if (action === 'pdf-fit') state.pdfZooms[fileId] = 100;
        if (action === 'pdf-zoom-out') state.pdfZooms[fileId] = Math.max(75, (Number(state.pdfZooms[fileId]) || 100) - 25);
        if (action === 'pdf-zoom-in') state.pdfZooms[fileId] = Math.min(250, (Number(state.pdfZooms[fileId]) || 100) + 25);
        const scroll = viewer.querySelector('[data-pdf-scroll]');
        if (scroll instanceof HTMLElement && ['pdf-prev', 'pdf-next', 'pdf-fit'].includes(action)) scroll.scrollTo({ left: 0, top: 0 });
        await renderPdfPage(runtime).catch(error => showPdfError(runtime, error));
        const zoomOut = viewer.querySelector('[data-action="pdf-zoom-out"]');
        const zoomIn = viewer.querySelector('[data-action="pdf-zoom-in"]');
        if (zoomOut instanceof HTMLButtonElement) zoomOut.disabled = state.pdfZooms[fileId] <= 75;
        if (zoomIn instanceof HTMLButtonElement) zoomIn.disabled = state.pdfZooms[fileId] >= 250;
    }

    async function handleClick(event) {
        const target = event.target.closest('[data-action]');
        if (!target) return;
        const action = target.dataset.action;
        if (action === 'insert-ocr-region') return insertOcrRegion(target);
        if (['pdf-prev', 'pdf-next', 'pdf-zoom-out', 'pdf-zoom-in', 'pdf-fit'].includes(action)) {
            await updatePdfView(target, action);
            return;
        }
        if (['image-zoom-out', 'image-zoom-in', 'image-fit'].includes(action)) {
            updateImageZoom(target, action);
            return;
        }
        if (action === 'close') return requestCloseManager();
        if (action === 'archive-drawing') return requestArchiveConfirmation();
        if (action === 'resolve-comment') return resolveComment(Number(target.dataset.commentId));
        if (action === 'dismiss-alert') {
            state.error = '';
            state.notice = '';
            return render();
        }
        if (action === 'choose-file') {
            const input = overlay?.querySelector('[data-field="drawing-file"]');
            if (input instanceof HTMLInputElement && !input.disabled) input.click();
            return;
        }
        if (action === 'new-drawing') {
            detailRequest++;
            state.mode = 'create';
            setDrawing(null);
            resetComments();
            state.uploadWarnings = [];
            state.selectedFileName = '';
            state.error = '';
            state.notice = '';
            return render();
        }
        if (action === 'back-list') {
            if (detailBusy()) return;
            if (!confirmReviewExit()) return;
            detailRequest++;
            state.mode = 'list';
            setDrawing(null);
            resetComments();
            state.uploadWarnings = [];
            state.selectedFileName = '';
            return render();
        }
        if (action === 'status-filter') return selectStatus(String(target.dataset.status || 'all'));
        if (action === 'list-page') return selectListPage(Number(target.dataset.page));
        if (action === 'list-layout') return setListLayout(String(target.dataset.layout || 'card'));
        if (action === 'detail') await loadDetail(Number(target.dataset.id));
    }

    function handleInput(event) {
        const reviewField = event.target.dataset.reviewField;
        if (reviewField && REVIEW_FIELDS.includes(reviewField) && state.reviewDraft) {
            state.reviewDraft[reviewField] = event.target.value;
            return;
        }
        if (event.target.dataset.field === 'comment-body') {
            state.commentDraft = event.target.value;
            return;
        }
        if (event.target.dataset.field !== 'search') return;
        state.query = event.target.value;
        state.pagination.page = 1;
        clearTimeout(searchTimer);
        searchTimer = setTimeout(async () => {
            try {
                await loadList();
            } catch (error) {
                state.error = error.message;
            } finally {
                render();
            }
        }, 260);
    }

    async function handleChange(event) {
        if (event.target instanceof HTMLSelectElement && event.target.dataset.field === 'page-size') {
            await selectListPageSize(Number(event.target.value));
            return;
        }
        if (event.target instanceof HTMLInputElement && event.target.dataset.processCode) {
            const code = String(event.target.dataset.processCode);
            if (!Object.hasOwn(PROCESS_KEYS, code)) return;
            const selected = new Set(state.processCodes);
            if (event.target.checked) selected.add(code);
            else selected.delete(code);
            state.processCodes = PROCESS_OPTIONS.map(([optionCode]) => optionCode).filter(optionCode => selected.has(optionCode));
            return;
        }
        if (event.target.dataset.field === 'review-confirmed') {
            state.reviewConfirmed = Boolean(event.target.checked);
            return;
        }
        if (event.target.dataset.field !== 'drawing-file') return;
        state.selectedFileName = event.target.files?.[0]?.name || '';
        const form = event.target.closest('form[data-form="upload"]');
        if (form) await uploadFile(form);
    }

    async function approveDrawing(form) {
        if (state.approving || !state.drawing || !state.reviewDraft) return;
        const drawingId = Number(state.drawing.id);
        const formData = new FormData(form);
        const formValues = Object.fromEntries(formData.entries());
        for (const name of REVIEW_FIELDS) state.reviewDraft[name] = String(formValues[name] ?? '');
        const selectedProcessCodes = new Set(formData.getAll('process_codes[]').map(String));
        state.processCodes = PROCESS_OPTIONS.map(([code]) => code).filter(code => selectedProcessCodes.has(code));
        state.reviewConfirmed = formValues.confirmed === '1';
        if (!state.reviewConfirmed) {
            state.error = t('review.confirmRequired');
            return render();
        }
        const body = {
            id: drawingId,
            ...state.reviewDraft,
            process_metadata: [...state.processCodes],
            confirmed: true,
            expected_row_version: Number(state.drawing.row_version) || 0,
        };
        state.approving = true;
        state.error = '';
        state.notice = '';
        render();
        try {
            const data = await api('plugin-drawing-manager-approve', { method: 'POST', body });
            if (!isActiveDrawing(drawingId)) return;
            if (!data.drawing) throw new Error(t('error.loadApproved'));
            setDrawing(data.drawing);
            state.uploadWarnings = [];
            state.notice = t('notice.approved', { drawing: data.drawing.drawing_no || '' });
            await refreshListQuietly();
        } catch (error) {
            if (!isActiveDrawing(drawingId)) return;
            if (['drawing_conflict', 'drawing_already_approved'].includes(error.code)) {
                const draft = { ...state.reviewDraft };
                const processCodes = [...state.processCodes];
                try {
                    const latest = await api('plugin-drawing-manager-detail', { query: `&id=${encodeURIComponent(drawingId)}` });
                    if (!isActiveDrawing(drawingId)) return;
                    setDrawing(latest.drawing || null);
                    if (state.drawing && approvalStatus(state.drawing) === 'pending') {
                        state.reviewDraft = { ...state.reviewDraft, ...draft };
                        state.processCodes = processCodes;
                        state.reviewConfirmed = true;
                        state.error = error.message;
                        state.notice = t('notice.reviewConflictReloaded');
                    } else {
                        state.error = '';
                        state.notice = t('notice.alreadyApprovedReloaded');
                        await refreshListQuietly();
                    }
                } catch (refreshError) {
                    state.error = `${error.message} ${refreshError.message}`;
                }
            } else {
                state.error = error.message;
            }
        } finally {
            state.approving = false;
            render();
        }
    }

    async function saveAdminDrawing(form) {
        if (!isAdmin() || state.saving || !state.drawing || !state.reviewDraft) return;
        const formData = new FormData(form);
        const formValues = Object.fromEntries(formData.entries());
        for (const name of REVIEW_FIELDS) state.reviewDraft[name] = String(formValues[name] ?? '');
        const selectedProcessCodes = new Set(formData.getAll('process_codes[]').map(String));
        state.processCodes = PROCESS_OPTIONS.map(([code]) => code).filter(code => selectedProcessCodes.has(code));
        const fields = {
            ...state.reviewDraft,
            process_metadata: [...state.processCodes],
        };
        const drawingId = Number(state.drawing.id);
        const expectedRowVersion = Number(state.drawing.row_version) || 0;
        state.saving = true;
        state.error = '';
        state.notice = '';
        render();
        try {
            const data = await api('plugin-drawing-manager-update', {
                method: 'POST',
                body: {
                    id: drawingId,
                    expected_row_version: expectedRowVersion,
                    fields,
                },
            });
            if (!isActiveDrawing(drawingId)) return;
            if (!data.drawing) throw new Error(t('error.loadUpdated'));
            setDrawing(data.drawing);
            state.notice = t('notice.updated', { drawing: data.drawing.drawing_no || '' });
            await refreshListQuietly();
        } catch (error) {
            if (!isActiveDrawing(drawingId)) return;
            if (error.code === 'drawing_conflict') {
                const draft = { ...state.reviewDraft };
                const processCodes = [...state.processCodes];
                try {
                    const latest = await api('plugin-drawing-manager-detail', { query: `&id=${encodeURIComponent(drawingId)}` });
                    if (!isActiveDrawing(drawingId)) return;
                    setDrawing(latest.drawing || null);
                    if (state.drawing) {
                        state.reviewDraft = { ...state.reviewDraft, ...draft };
                        state.processCodes = processCodes;
                        state.error = error.message;
                        state.notice = t('notice.editConflictReloaded');
                    }
                } catch (refreshError) {
                    state.error = `${error.message} ${refreshError.message}`;
                }
            } else {
                state.error = error.message;
            }
        } finally {
            state.saving = false;
            render();
        }
    }

    function revealComments() {
        window.requestAnimationFrame(() => {
            overlay?.querySelector('.drawing-comments')?.scrollIntoView({ block: 'start' });
        });
    }

    async function createComment(form) {
        if (state.commentSubmitting || !state.drawing) return;
        const drawingId = Number(state.drawing.id);
        const formData = new FormData(form);
        const body = String(formData.get('body') ?? state.commentDraft).trim();
        if (!body) return;
        state.commentDraft = body;
        state.commentSubmitting = true;
        state.commentsError = '';
        state.notice = '';
        render();
        revealComments();
        try {
            const data = await api('plugin-drawing-manager-comment-create', {
                method: 'POST',
                body: {
                    drawing_id: drawingId,
                    body,
                },
            });
            if (!isActiveDrawing(drawingId)) return;
            if (!data.comment) throw new Error(t('error.loadComment'));
            state.comments = [...state.comments, data.comment];
            state.commentDraft = '';
            state.notice = t('notice.commentSent');
        } catch (error) {
            if (isActiveDrawing(drawingId)) state.commentsError = error.message;
        } finally {
            state.commentSubmitting = false;
            render();
            if (isActiveDrawing(drawingId)) revealComments();
        }
    }

    async function resolveComment(commentId) {
        if (!isAdmin() || state.commentResolvingId || !state.drawing || !commentId) return;
        const drawingId = Number(state.drawing.id);
        state.commentResolvingId = commentId;
        state.commentsError = '';
        state.notice = '';
        render();
        revealComments();
        try {
            const data = await api('plugin-drawing-manager-comment-resolve', {
                method: 'POST',
                body: { comment_id: commentId },
            });
            if (!isActiveDrawing(drawingId)) return;
            if (!data.comment) throw new Error(t('error.updateComment'));
            state.comments = state.comments.map(comment => Number(comment?.id) === commentId ? data.comment : comment);
            state.notice = t('notice.commentResolved');
        } catch (error) {
            if (isActiveDrawing(drawingId)) state.commentsError = error.message;
        } finally {
            state.commentResolvingId = 0;
            render();
            if (isActiveDrawing(drawingId)) revealComments();
        }
    }

    async function handleSubmit(event) {
        const form = event.target.closest('form[data-form]');
        if (!form) return;
        event.preventDefault();
        if (form.dataset.form === 'upload') {
            await uploadFile(form);
            return;
        }
        if (form.dataset.form === 'approve') {
            await approveDrawing(form);
            return;
        }
        if (form.dataset.form === 'admin-update') {
            if (event.submitter?.dataset.submit === 'approve') await approveDrawing(form);
            else await saveAdminDrawing(form);
            return;
        }
        if (form.dataset.form === 'comment-create') await createComment(form);
    }

    window.addEventListener('keydown', event => {
        if (!overlay || overlay.classList.contains('is-hidden')) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            if (state.archiveConfirmationOpen) cancelArchiveConfirmation();
            else if (state.closeConfirmationOpen) cancelCloseConfirmation();
            else requestCloseManager();
            return;
        }
        if (event.key !== 'Tab') return;
        const focusRoot = state.closeConfirmationOpen ? closeConfirmation : overlay;
        const activeFocusRoot = state.archiveConfirmationOpen ? archiveConfirmation : focusRoot;
        if (!activeFocusRoot) return;
        const focusable = Array.from(activeFocusRoot.querySelectorAll('button:not([disabled]), a[href], input:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'))
            .filter(element => element instanceof HTMLElement && element.offsetParent !== null);
        if (!focusable.length) {
            event.preventDefault();
            return;
        }
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (!activeFocusRoot.contains(document.activeElement)) {
            event.preventDefault();
            (event.shiftKey ? last : first).focus();
        } else if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }, true);
    window.addEventListener('openconcept:locale-change', () => {
        closeConfirmation?.remove();
        closeConfirmation = null;
        archiveConfirmation?.remove();
        archiveConfirmation = null;
        if (overlay && !overlay.classList.contains('is-hidden')) render();
    });
    if (window.OpenConceptPlugins?.register) window.OpenConceptPlugins.register(PLUGIN_ID, openManager);
})();
