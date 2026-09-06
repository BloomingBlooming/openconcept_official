(() => {
    'use strict';

    const root = document.getElementById('app');
    const toastRegion = document.getElementById('toastRegion');
    let aiPanelCloseTimer = null;
    let aiPanelClosing = false;
    let notificationPollTimer = null;
    let databaseMigrationPollingPaused = false;

    window.addEventListener('openconcept:database-migration-start', () => {
        databaseMigrationPollingPaused = true;
        clearInterval(notificationPollTimer);
        notificationPollTimer = null;
    });
    window.addEventListener('openconcept:database-migration-end', () => {
        databaseMigrationPollingPaused = false;
        if (state.user) startNotificationPolling();
    });

    const state = {
        csrf: window.OPENCONCEPT_BOOT?.csrf || '',
        user: null,
        initialized: false,
        pages: [],
        trash: [],
        files: [],
        fileSearchQuery: '',
        fileTypeFilter: '',
        fileListPage: 1,
        uploadLimitMb: 15,
        profilePhotoLimitMb: 5,
        profileIconChoices: {},
        members: [],
        departments: [],
        suspendedMembers: [],
        notifications: [],
        inboxFilter: 'all',
        commentPicker: null,
        capabilities: {},
        workspaceName: 'OpenConcept',
        organizationName: '',
        i18n: window.OPENCONCEPT_BOOT?.i18n || { locale: 'ja-JP', fallback_locale: 'en-US', available_locales: [], messages: {}, plugins: {} },
        translationBusy: false,
        pluginUi: Array.isArray(window.OPENCONCEPT_BOOT?.plugin_ui) ? window.OPENCONCEPT_BOOT.plugin_ui : [],
        installedPlugins: [],
        availablePlugins: [],
        pluginsLoading: false,
        pluginsLoaded: false,
        pluginCatalogConfigured: false,
        pluginCatalogError: '',
        pluginOperation: '',
        settingsLoading: false,
        aiProviderSettings: null,
        aiProviderDraft: null,
        aiProviderNotice: '',
        aiProviderExpanded: false,
        aiProviderLoading: false,
        aiProviderSaving: false,
        aiProviderTesting: false,
        aiProviderError: '',
        translationProviderSettings: null,
        translationProviderDraft: null,
        translationProviderNotice: '',
        translationProviderLoading: false,
        translationProviderSaving: false,
        translationProviderTesting: false,
        translationProviderError: '',
        runtimeEnvironment: null,
        localSearchQuery: '',
        localSearchTag: '',
        dialogData: null,
        settingsTab: 'general',
        publicShare: null,
        publicShareDraftPageIds: [],
        publicShareDraftSlug: '',
        publicShareLoading: false,
        publicShareSaving: false,
        publicShareError: '',
        affectedPublications: new Map(),
        affectedPublicationsRefreshing: false,
        page: null,
        view: 'home',
        panel: null,
        dialog: null,
        expanded: new Set([1, 5, 7]),
        sidebarCollapsed: false,
        mobileSidebar: false,
        sidebarScrollTop: 0,
        replyTo: null,
        slash: null,
        context: null,
        bubble: null,
        aiQuestion: '',
        aiSearching: false,
        aiSearchError: '',
        aiSearchErrorDetails: null,
        aiMessages: [],
        aiConversations: [],
        aiConversationId: 0,
        aiConversationsLoaded: false,
        aiConversationsLoading: false,
        aiConversationRequest: 0,
        aiEditorContext: null,
        aiPendingFocus: null,
        saveTimer: null,
        saving: false,
        saveRetryBlocked: false,
        saveFailure: null,
        lastSaved: null,
        dirty: false,
        undoHistory: [],
        undoPresent: null,
        undoPageId: null,
        undoGroup: null,
        undoApplying: false,
        tableSelection: null,
        tableClipboard: null,
        editorFind: {
            open: false,
            query: '',
            replacement: '',
            matches: [],
            index: -1,
        },
        preservedTextSelection: null,
        dragPageId: null,
        suppressTreeClickUntil: 0,
    };

    function synchronizeCsrf(token) {
        if (typeof token !== 'string' || token === '') return;
        state.csrf = token;
        if (window.OPENCONCEPT_BOOT && typeof window.OPENCONCEPT_BOOT === 'object') {
            window.OPENCONCEPT_BOOT.csrf = token;
        }
    }

    function applyI18n(bundle) {
        if (!bundle || typeof bundle !== 'object') return;
        const previousLocale = state.i18n?.locale || '';
        state.i18n = bundle;
        document.documentElement.lang = bundle.locale || 'en-US';
        const nextLocale = bundle.locale || 'en-US';
        if (previousLocale && previousLocale !== nextLocale) {
            window.dispatchEvent(new CustomEvent('openconcept:locale-change', {
                detail: { locale: nextLocale, previousLocale },
            }));
        }
    }

    function t(key, parameters = {}, domain = 'core') {
        const catalog = domain === 'core' ? state.i18n?.messages : state.i18n?.plugins?.[domain];
        let message = typeof catalog?.[key] === 'string' ? catalog[key] : key;
        Object.entries(parameters).forEach(([name, value]) => {
            message = message.replaceAll(`{${name}}`, String(value));
        });
        return message;
    }

    function pluginT(pluginId, key, parameters = {}, fallback = '') {
        const translated = t(key, parameters, String(pluginId || ''));
        return translated === key ? (fallback || key) : translated;
    }

    window.OpenConceptI18n = Object.freeze({
        t: (pluginId, key, parameters = {}, fallback = '') => pluginT(pluginId, key, parameters, fallback),
        locale: () => state.i18n?.locale || 'en-US',
    });

    function availableContentLanguages(current = '') {
        const locales = Array.isArray(state.i18n?.available_locales) ? [...state.i18n.available_locales] : [];
        if (current && current !== 'und' && !locales.some(locale => locale.code === current)) {
            locales.push({ code: current, name: current });
        }
        return locales;
    }

    function languageName(code) {
        if (!code || code === 'und') return t('common.unspecified');
        return availableContentLanguages(code).find(locale => locale.code === code)?.name || code;
    }

    const pluginOpenHandlers = new Map();
    const aiSearchExtensions = new Map();
    const mountedAiSearchExtensions = new Set();
    window.OpenConceptPlugins = Object.freeze({
        register(pluginId, onOpen) {
            const id = String(pluginId || '');
            if (!/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/.test(id) || typeof onOpen !== 'function') {
                throw new TypeError('A valid plugin ID and open handler are required.');
            }
            pluginOpenHandlers.set(id, onOpen);
        },
        unregister(pluginId) {
            const id = String(pluginId || '');
            pluginOpenHandlers.delete(id);
            aiSearchExtensions.delete(id);
            mountedAiSearchExtensions.delete(id);
        },
        registerAiSearch(pluginId, extension) {
            const id = String(pluginId || '');
            if (!/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/.test(id) || !extension || typeof extension.mount !== 'function') {
                throw new TypeError('A valid plugin ID and AI search extension are required.');
            }
            aiSearchExtensions.set(id, extension);
            queueMicrotask(syncAiSearchExtensions);
        },
        unregisterAiSearch(pluginId) {
            const id = String(pluginId || '');
            const extension = aiSearchExtensions.get(id);
            if (mountedAiSearchExtensions.has(id)) extension?.unmount?.();
            aiSearchExtensions.delete(id);
            mountedAiSearchExtensions.delete(id);
        },
    });
    window.OpenConceptAI = Object.freeze({
        open: () => openAiSearch(),
        submit: (question, options = {}) => submitAiQuestion(String(question || ''), options),
        context: () => ({
            open: state.dialog === 'ai-search' && !aiPanelClosing,
            searching: state.aiSearching,
            conversationId: Number(state.aiConversationId || 0),
            pageId: state.view === 'page' && state.page?.id ? Number(state.page.id) : 0,
        }),
    });

    const icons = {
        search: '<circle cx="11" cy="11" r="7"></circle><path d="m20 20-4-4"></path>',
        home: '<path d="m3 11 9-8 9 8"></path><path d="M5 10v10h14V10"></path><path d="M9 20v-6h6v6"></path>',
        inbox: '<path d="M4 5h16v14H4z"></path><path d="m4 13 4-4 4 4 4-4 4 4"></path>',
        plus: '<path d="M12 5v14M5 12h14"></path>',
        settings: '<circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.83 2.83-.06-.06a1.7 1.7 0 0 0-1.88-.34 1.7 1.7 0 0 0-1.03 1.56V21h-4v-.09A1.7 1.7 0 0 0 9 19.36a1.7 1.7 0 0 0-1.88.34l-.06.06-2.83-2.83.06-.06A1.7 1.7 0 0 0 4.63 15 1.7 1.7 0 0 0 3.09 14H3v-4h.09A1.7 1.7 0 0 0 4.64 9a1.7 1.7 0 0 0-.34-1.88l-.06-.06 2.83-2.83.06.06A1.7 1.7 0 0 0 9 4.63h.01A1.7 1.7 0 0 0 10 3.09V3h4v.09A1.7 1.7 0 0 0 15 4.64a1.7 1.7 0 0 0 1.88-.34l.06-.06 2.83 2.83-.06.06A1.7 1.7 0 0 0 19.37 9v.01A1.7 1.7 0 0 0 20.91 10H21v4h-.09A1.7 1.7 0 0 0 19.4 15Z"></path>',
        chevron: '<path d="m9 18 6-6-6-6"></path>',
        more: '<circle cx="5" cy="12" r="1"></circle><circle cx="12" cy="12" r="1"></circle><circle cx="19" cy="12" r="1"></circle>',
        info: '<circle cx="12" cy="12" r="9"></circle><path d="M12 11v6M12 7h.01"></path>',
        menu: '<path d="M4 7h16M4 12h16M4 17h16"></path>',
        comment: '<path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4Z"></path>',
        share: '<circle cx="18" cy="5" r="3"></circle><circle cx="6" cy="12" r="3"></circle><circle cx="18" cy="19" r="3"></circle><path d="m8.6 10.5 6.8-4M8.6 13.5l6.8 4"></path>',
        star: '<path d="m12 2 3.1 6.3L22 9.3l-5 4.9 1.2 6.8-6.2-3.2L5.8 21 7 14.2l-5-4.9 6.9-1Z"></path>',
        clock: '<circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 2"></path>',
        close: '<path d="m6 6 12 12M18 6 6 18"></path>',
        send: '<path d="m22 2-7 20-4-9-9-4Z"></path><path d="M22 2 11 13"></path>',
        at: '<circle cx="12" cy="12" r="4"></circle><path d="M16 8v5a3 3 0 0 0 6 0v-1a10 10 0 1 0-4 8"></path>',
        smile: '<circle cx="12" cy="12" r="9"></circle><path d="M8 14s1.5 2 4 2 4-2 4-2M9 9h.01M15 9h.01"></path>',
        grip: '<circle cx="8" cy="6" r="1"></circle><circle cx="16" cy="6" r="1"></circle><circle cx="8" cy="12" r="1"></circle><circle cx="16" cy="12" r="1"></circle><circle cx="8" cy="18" r="1"></circle><circle cx="16" cy="18" r="1"></circle>',
        trash: '<path d="M4 7h16M9 7V4h6v3M7 7l1 14h8l1-14M10 11v6M14 11v6"></path>',
        copy: '<rect x="8" y="8" width="12" height="12" rx="2"></rect><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"></path>',
        archive: '<path d="M3 5h18v4H3zM5 9v11h14V9M10 13h4"></path>',
        history: '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"></path><path d="M3 3v5h5M12 7v5l3 2"></path>',
        lock: '<rect x="5" y="10" width="14" height="11" rx="2"></rect><path d="M8 10V7a4 4 0 0 1 8 0v3"></path>',
        globe: '<circle cx="12" cy="12" r="9"></circle><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"></path>',
        users: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M22 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"></path>',
        check: '<path d="m5 12 4 4L19 6"></path>',
        edit: '<path d="M12 20h9"></path><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4Z"></path>',
        bell: '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"></path>',
        logout: '<path d="M10 17l5-5-5-5M15 12H3M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path>',
        external: '<path d="M14 3h7v7M10 14 21 3M21 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5"></path>',
        filter: '<path d="M4 5h16M7 12h10M10 19h4"></path>',
        image: '<rect x="3" y="4" width="18" height="16" rx="2"></rect><circle cx="9" cy="10" r="2"></circle><path d="m21 15-5-5L5 20"></path>',
        folder: '<path d="M3 6h7l2 2h9v11H3z"></path>',
        paperclip: '<path d="m21.4 11.6-8.9 8.9a6 6 0 0 1-8.5-8.5l9.6-9.6a4 4 0 0 1 5.7 5.7l-9.6 9.6a2 2 0 1 1-2.8-2.8l8.9-8.9"></path>',
        print: '<path d="M7 8V3h10v5M7 17H5a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><path d="M7 14h10v7H7z"></path><path d="M17 11h.01"></path>',
        download: '<path d="M12 3v12M7 10l5 5 5-5"></path><path d="M5 21h14"></path>',
        fileText: '<path d="M6 2h9l5 5v15H6z"></path><path d="M14 2v6h6M9 13h8M9 17h8M9 9h2"></path>',
    };

    const svg = (name, cls = '') => `<svg class="icon ${cls}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${icons[name] || icons.more}</svg>`;
    const brandLogo = (className = 'brand-mark', alt = '') => `<img class="${className}" src="app-icon.php" alt="${esc(alt)}" draggable="false">`;
    const appVersion = '2.3.0';
    const esc = (value = '') => String(value).replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[char]));

    function normalizePublicUrl(value) {
        const raw = String(value || '').trim();
        if (!raw) return '';
        try {
            const url = new URL(raw, window.location.href);
            return ['http:', 'https:'].includes(url.protocol) ? url.href : '';
        } catch (_) {
            return '';
        }
    }
    const initials = name => String(name || '?').replace(/\s+/g, '').slice(0, 2);
    function avatarMarkup(profile = {}, size = '') {
        const id = Math.max(0, Number(profile.id) || 0);
        const name = String(profile.name || '?');
        const color = String(profile.color || '#5E6AD2');
        const kind = String(profile.kind || 'initials');
        const value = String(profile.value || '');
        const classes = ['avatar', size].filter(Boolean).join(' ');
        if (kind === 'photo' && id && value) {
            const src = `api.php?action=profile-avatar&id=${encodeURIComponent(id)}&v=${encodeURIComponent(value)}`;
            return `<span class="${classes} avatar-photo" style="background:${esc(color)}"><img src="${esc(src)}" alt="${esc(t('profile.photoAlt', { name }))}" loading="lazy"></span>`;
        }
        if (kind === 'emoji' && value) {
            return `<span class="${classes} avatar-emoji" style="background:${esc(color)}" role="img" aria-label="${esc(name)}">${esc(value)}</span>`;
        }
        return `<span class="${classes}" style="background:${esc(color)}">${esc(initials(name))}</span>`;
    }
    const memberAvatar = (member, size = '') => avatarMarkup({ id: member.id, name: member.name, color: member.avatar_color, kind: member.avatar_kind, value: member.avatar_value }, size);
    const authorAvatar = (page, size = '') => avatarMarkup({ id: page.author_id, name: page.author_name, color: page.author_color, kind: page.author_avatar_kind, value: page.author_avatar_value }, size);
    const commentAvatar = (item, size = '') => avatarMarkup({ id: item.user_id || item.created_by, name: item.user_name, color: item.user_color, kind: item.user_avatar_kind, value: item.user_avatar_value }, size);
    const uid = () => `b-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 7)}`;
    const coverValues = ['none', 'mint', 'blue', 'sand', 'coral', 'lavender', 'night'];
    const statusValues = ['draft', 'review', 'published', 'private', 'archived'];
    const visibilityValues = ['company', 'department', 'group', 'private'];
    const roleValues = ['admin', 'content_admin', 'editor', 'author', 'commenter', 'viewer', 'suspended'];
    const translatedMap = (prefix, values) => Object.fromEntries(values.map(value => [value, t(`${prefix}.${value}`)]));
    const coverNames = () => translatedMap('page.cover', coverValues);
    const statusNames = () => translatedMap('page.status', statusValues);
    const visibilityNames = () => translatedMap('share.visibility', visibilityValues);
    const visibilityHelpText = () => translatedMap('share.visibilityHelp', visibilityValues);
    const roleNames = () => translatedMap('settings.role', roleValues);
    const roleHelpText = () => translatedMap('settings.roleHelp', roleValues);
    const fileCategory = {
        pdf: { label: 'PDF', short: 'PDF' },
        excel: { label: 'Excel', short: 'XLS' },
        word: { label: 'Word', short: 'DOC' },
        markdown: { label: 'Markdown', short: 'MD' },
        text: { labelKey: 'files.category.text', short: 'TXT' },
        image: { labelKey: 'files.category.image', short: 'IMG' },
    };

    function fileCategoryLabel(category) {
        const meta = fileCategory[category];
        if (!meta) return t('files.category.file');
        return meta.labelKey ? t(meta.labelKey) : meta.label;
    }

    function fileKindIcon(category) {
        const meta = fileCategory[category] || { short: 'FILE' };
        return `<span class="file-kind-icon ${esc(category)}" aria-label="${esc(fileCategoryLabel(category))}">${category === 'image' ? svg('image', 'small') : `<span>${esc(meta.short)}</span>`}</span>`;
    }

    function formatBytes(value) {
        const bytes = Math.max(0, Number(value) || 0);
        if (bytes < 1024) return `${bytes} B`;
        if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(bytes < 10240 ? 1 : 0)} KB`;
        return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
    }

    function timeAgo(dateString) {
        if (!dateString) return '';
        const normalized = String(dateString).includes('T') ? dateString : dateString.replace(' ', 'T') + 'Z';
        const seconds = Math.max(0, (Date.now() - new Date(normalized).getTime()) / 1000);
        if (seconds < 60) return t('time.justNow');
        if (seconds < 3600) return t('time.minutesAgo', { count: Math.floor(seconds / 60) });
        if (seconds < 86400) return t('time.hoursAgo', { count: Math.floor(seconds / 3600) });
        if (seconds < 604800) return t('time.daysAgo', { count: Math.floor(seconds / 86400) });
        return new Intl.DateTimeFormat(state.i18n?.locale || 'en-US', { month: 'short', day: 'numeric' }).format(new Date(normalized));
    }

    async function api(action, options = {}) {
        const config = { method: options.method || 'GET', headers: { 'Accept': 'application/json' } };
        if (options.signal) config.signal = options.signal;
        if (options.body !== undefined) {
            config.headers['Content-Type'] = 'application/json';
            config.headers['X-CSRF-Token'] = state.csrf;
            config.body = JSON.stringify(options.body);
        }
        const response = await fetch(`api.php?action=${encodeURIComponent(action)}${options.query || ''}`, config);
        const data = await response.json().catch(() => ({ error: t('common.invalidServerResponse') }));
        if (!response.ok) {
            if (response.status === 401) renderLogin();
            const error = new Error(data.error || t('common.operationFailed'));
            error.code = data.code || null;
            error.status = response.status;
            error.data = data;
            throw error;
        }
        synchronizeCsrf(data?.csrf);
        mergeAffectedPublications(data?.affected_publications);
        return data;
    }

    function toast(message, type = 'success') {
        const el = document.createElement('div');
        el.className = `toast ${type}`;
        el.innerHTML = `${svg(type === 'error' ? 'close' : 'check', 'small')}<span>${esc(message)}</span>`;
        toastRegion.appendChild(el);
        setTimeout(() => {
            el.classList.add('out');
            setTimeout(() => el.remove(), 220);
        }, 2600);
    }

    function clearWorkspaceState() {
        cleanupProfileDraft();
        clearTimeout(state.saveTimer);
        clearInterval(notificationPollTimer);
        notificationPollTimer = null;
        clearTimeout(aiPanelCloseTimer);
        aiPanelCloseTimer = null;
        aiPanelClosing = false;
        Object.assign(state, {
            user: null,
            pages: [],
            trash: [],
            files: [],
            profilePhotoLimitMb: 5,
            profileIconChoices: {},
            members: [],
            departments: [],
            notifications: [],
            inboxFilter: 'all',
            commentPicker: null,
            capabilities: {},
            settingsLoading: false,
            aiProviderSettings: null,
            aiProviderDraft: null,
            aiProviderNotice: '',
            aiProviderExpanded: false,
            translationProviderSettings: null,
            translationProviderDraft: null,
            translationProviderNotice: '',
            page: null,
            dirty: false,
            saving: false,
            saveRetryBlocked: false,
            saveFailure: null,
            view: 'home',
            panel: null,
            dialog: null,
            dialogData: null,
            publicShare: null,
            publicShareDraftPageIds: [],
            publicShareDraftSlug: '',
            publicShareLoading: false,
            publicShareSaving: false,
            publicShareError: '',
            affectedPublications: new Map(),
            affectedPublicationsRefreshing: false,
            context: null,
            bubble: null,
            replyTo: null,
            slash: null,
            lastSaved: null,
            saveTimer: null,
            aiQuestion: '',
            aiSearching: false,
            aiSearchError: '',
            aiSearchErrorDetails: null,
            aiMessages: [],
            aiConversations: [],
            aiConversationId: 0,
            aiConversationsLoaded: false,
            aiConversationsLoading: false,
            aiConversationRequest: state.aiConversationRequest + 1,
        });
        document.body.classList.remove('ai-search-open');
    }

    function renderLogin() {
        resetUndoHistory();
        clearWorkspaceState();
        root.innerHTML = `
            <main class="login-page">
                <section class="login-main">
                    <div class="login-brand">${brandLogo()}<span>OpenConcept</span></div>
                    <form class="login-card" id="loginForm">
                        <div class="login-eyebrow">${esc(t('auth.welcomeBack'))}</div>
                        <h1>${esc(t('auth.taglineTitleLine1'))}<br>${esc(t('auth.taglineTitleLine2'))}</h1>
                        <p>${esc(t('auth.taglineBodyLine1'))}<br>${esc(t('auth.taglineBodyLine2'))}</p>
                        <div class="form-field">
                            <label for="email">${esc(t('auth.email'))}</label>
                            <input class="input" id="email" name="email" type="email" placeholder="name@company.jp" autocomplete="username" required>
                        </div>
                        <div class="form-field">
                            <label for="password">${esc(t('auth.password'))}</label>
                            <input class="input" id="password" name="password" type="password" placeholder="${esc(t('auth.password'))}" autocomplete="current-password" required>
                        </div>
                        <div id="loginError" class="login-error"></div>
                        <button class="btn primary login-submit" type="submit">${esc(t('auth.enterWorkspace'))}</button>
                        <div class="login-hint">${esc(t('auth.invitationHint'))}</div>
                    </form>
                    <div class="login-footer">© 2026 OpenConcept</div>
                </section>
                <aside class="login-art" aria-hidden="true">
                    <div class="art-board">
                        <div class="art-top"><span class="art-dot"></span><span class="art-dot"></span><span class="art-dot"></span></div>
                        <div class="art-content">
                            <div class="art-spark">✦</div>
                            <div class="art-line title"></div>
                            <div class="art-line a"></div><div class="art-line b"></div><div class="art-line c"></div>
                            <div class="art-callout"></div>
                        </div>
                        <div class="art-note"><div class="art-line a"></div><div class="art-line b"></div><div class="art-line c"></div></div>
                    </div>
                </aside>
            </main>`;
        document.getElementById('loginForm').addEventListener('submit', login);
    }

    function setupArt() {
        return `<aside class="login-art" aria-hidden="true"><div class="art-board"><div class="art-top"><span class="art-dot"></span><span class="art-dot"></span><span class="art-dot"></span></div><div class="art-content"><div class="art-spark">✦</div><div class="art-line title"></div><div class="art-line a"></div><div class="art-line b"></div><div class="art-line c"></div><div class="art-callout"></div></div><div class="art-note"><div class="art-line a"></div><div class="art-line b"></div><div class="art-line c"></div></div></div></aside>`;
    }

    function renderSetup() {
        resetUndoHistory();
        state.user = null;
        root.innerHTML = `<main class="login-page"><section class="login-main"><div class="login-brand">${brandLogo()}<span>OpenConcept</span></div><form class="login-card setup-card" id="setupForm"><div class="login-eyebrow">${esc(t('setup.eyebrow'))}</div><h1>${esc(t('setup.titleLine1'))}<br>${esc(t('setup.titleLine2'))}</h1><p>${esc(t('setup.description'))}</p><div class="setup-grid"><div class="form-field"><label for="setupName">${esc(t('setup.adminName'))}</label><input class="input" id="setupName" name="name" autocomplete="name" placeholder="${esc(t('setup.adminNamePlaceholder'))}" required></div><div class="form-field"><label for="setupEmail">${esc(t('auth.email'))}</label><input class="input" id="setupEmail" name="email" type="email" autocomplete="email" placeholder="admin@company.jp" required></div><div class="form-field"><label for="setupPassword">${esc(t('auth.password'))}</label><input class="input" id="setupPassword" name="password" type="password" autocomplete="new-password" placeholder="${esc(t('setup.passwordMinimum'))}" required></div><div class="form-field"><label for="setupConfirmation">${esc(t('setup.passwordConfirmation'))}</label><input class="input" id="setupConfirmation" name="password_confirmation" type="password" autocomplete="new-password" placeholder="${esc(t('setup.confirmationPlaceholder'))}" required></div></div><div class="password-requirements">${esc(t('setup.passwordRequirements'))}</div><div id="setupError" class="login-error"></div><button class="btn primary login-submit" type="submit">${esc(t('setup.start'))}</button></form><div class="login-footer">© 2026 OpenConcept</div></section>${setupArt()}</main>`;
        document.getElementById('setupForm').addEventListener('submit', initializeWorkspace);
    }

    async function initializeWorkspace(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const button = form.querySelector('button[type="submit"]');
        const error = document.getElementById('setupError');
        error.textContent = '';
        button.disabled = true;
        button.textContent = t('setup.initializing');
        try {
            const data = await api('initialize', { method: 'POST', body: { name: form.elements.name.value, email: form.elements.email.value, password: form.elements.password.value, password_confirmation: form.elements.password_confirmation.value } });
            state.initialized = true;
            state.user = data.user;
            state.csrf = data.csrf;
            await loadWorkspace();
            toast(t('setup.created'));
        } catch (err) {
            error.textContent = err.message;
            button.disabled = false;
            button.textContent = t('setup.start');
        }
    }

    function renderPasswordChange() {
        resetUndoHistory();
        root.innerHTML = `<main class="login-page"><section class="login-main"><div class="login-brand">${brandLogo()}<span>OpenConcept</span></div><form class="login-card" id="passwordChangeForm"><div class="login-eyebrow">${esc(t('passwordChange.eyebrow'))}</div><h1>${esc(t('passwordChange.titleLine1'))}<br>${esc(t('passwordChange.titleLine2'))}</h1><p>${esc(t('passwordChange.description', { name: state.user?.name || '' }))}</p><div class="form-field"><label for="newPassword">${esc(t('passwordChange.newPassword'))}</label><input class="input" id="newPassword" name="password" type="password" autocomplete="new-password" placeholder="${esc(t('setup.passwordMinimum'))}" required></div><div class="form-field"><label for="newPasswordConfirmation">${esc(t('passwordChange.confirmation'))}</label><input class="input" id="newPasswordConfirmation" name="password_confirmation" type="password" autocomplete="new-password" required></div><div class="password-requirements">${esc(t('passwordChange.requirements'))}</div><div id="passwordChangeError" class="login-error"></div><button class="btn primary login-submit" type="submit">${esc(t('passwordChange.continue'))}</button></form><div class="login-footer">${esc(t('passwordChange.footer'))}</div></section>${setupArt()}</main>`;
        document.getElementById('passwordChangeForm').addEventListener('submit', changePassword);
    }

    async function changePassword(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const button = form.querySelector('button[type="submit"]');
        const error = document.getElementById('passwordChangeError');
        error.textContent = '';
        button.disabled = true;
        button.textContent = t('passwordChange.updating');
        try {
            const data = await api('change-password', { method: 'POST', body: { password: form.elements.password.value, password_confirmation: form.elements.password_confirmation.value } });
            state.csrf = data.csrf;
            state.user.must_change_password = false;
            await loadWorkspace();
            toast(t('passwordChange.updated'));
        } catch (err) {
            error.textContent = err.message;
            button.disabled = false;
            button.textContent = t('passwordChange.continue');
        }
    }

    async function login(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const button = form.querySelector('button[type="submit"]');
        const error = document.getElementById('loginError');
        error.textContent = '';
        button.disabled = true;
        button.textContent = t('auth.checking');
        try {
            const data = await api('login', { method: 'POST', body: { email: form.email.value, password: form.password.value } });
            state.csrf = data.csrf;
            if (data.must_change_password) {
                const session = await api('session');
                state.user = session.user;
                renderPasswordChange();
            } else await loadWorkspace();
        } catch (err) {
            error.textContent = err.message;
            button.disabled = false;
            button.textContent = t('auth.enterWorkspace');
        }
    }

    async function loadWorkspace() {
        resetUndoHistory();
        state.page = null;
        state.view = 'home';
        state.panel = null;
        state.dialog = null;
        state.dialogData = null;
        root.innerHTML = `<div class="app-loading">${brandLogo('brand-mark', 'OpenConcept')}<span class="loading-dot"></span></div>`;
        try {
            const data = await api('bootstrap');
            state.user = data.user;
            state.csrf = data.csrf;
            state.pages = (Array.isArray(data.pages) ? data.pages : []).map(normalizePageAccessDepartments);
            state.trash = (Array.isArray(data.trash) ? data.trash : []).map(normalizePageAccessDepartments);
            state.files = data.files || [];
            state.uploadLimitMb = data.upload_limit_mb || 15;
            state.profilePhotoLimitMb = data.profile_photo_limit_mb || 5;
            state.profileIconChoices = data.profile_icon_choices || {};
            state.members = data.members;
            state.departments = Array.isArray(data.departments) ? data.departments : [];
            state.suspendedMembers = data.suspended_members || [];
            state.notifications = data.notifications;
            state.capabilities = data.capabilities || {};
            applyI18n(data.i18n);
            state.workspaceName = data.settings?.workspace_name || 'OpenConcept';
            state.organizationName = data.settings?.organization_name ?? '';
            startNotificationPolling();
            const hashMatch = location.hash.match(/^#page-(\d+)$/);
            const settingsMatch = location.hash.match(/^#settings(?:-(general|ai|translation|plugins))?$/);
            if (settingsMatch) {
                await showSettings(settingsMatch[1] || 'general', false);
            } else if (location.hash === '#trash') {
                state.view = 'trash';
                renderApp();
            } else if (location.hash === '#files') {
                state.view = 'files';
                renderApp();
            } else if (hashMatch && state.pages.some(page => page.id === Number(hashMatch[1]))) {
                await openPage(Number(hashMatch[1]), false);
            } else {
                if (hashMatch) history.replaceState(null, '', '#home');
                renderApp();
            }
        } catch (err) {
            if (!state.user) renderLogin();
            else toast(err.message, 'error');
        }
    }

    function pageById(id) { return state.pages.find(page => page.id === Number(id)); }

    function availableDepartments(additional = []) {
        const values = [
            ...(Array.isArray(state.departments) ? state.departments : []),
            ...state.members.map(member => member.department || ''),
            ...additional,
        ];
        return [...new Set(values.map(trimDepartmentName).filter(Boolean))]
            .sort((left, right) => left.localeCompare(right, 'ja'));
    }

    function syncDepartmentsFromMembers() {
        state.departments = [...new Set(state.members
            .map(member => trimDepartmentName(member.department))
            .filter(Boolean))]
            .sort((left, right) => left.localeCompare(right, 'ja'));
    }

    function departmentOptions(selected = '', additional = []) {
        return availableDepartments([selected, ...additional])
            .map(department => `<option value="${esc(department)}" ${department === selected ? 'selected' : ''}>${esc(department)}</option>`)
            .join('');
    }

    function trimDepartmentName(value) {
        return String(value || '').replace(/^[\x00\t\n\v\r ]+|[\x00\t\n\v\r ]+$/g, '');
    }

    function normalizeAccessDepartments(value, legacyValue = '') {
        const values = Array.isArray(value) ? value : [legacyValue];
        return [...new Set(values
            .map(trimDepartmentName)
            .filter(Boolean))]
            .sort((left, right) => left.localeCompare(right, 'ja'));
    }

    function normalizeAccessMemberIds(value) {
        return [...new Set((Array.isArray(value) ? value : [])
            .map(Number)
            .filter(memberId => Number.isInteger(memberId) && memberId > 0))]
            .sort((left, right) => left - right);
    }

    function normalizedPageAccessState(value, fallback = {}) {
        const source = value && typeof value === 'object' ? value : {};
        const fallbackSource = fallback && typeof fallback === 'object' ? fallback : {};
        const status = String(source.status || fallbackSource.status || 'draft');
        const visibility = String(source.visibility || fallbackSource.visibility || 'company');
        return {
            status: statusValues.includes(status) ? status : 'draft',
            visibility: visibilityValues.includes(visibility) ? visibility : 'company',
            access_departments: normalizeAccessDepartments(
                source.access_departments ?? fallbackSource.access_departments,
                source.access_department ?? fallbackSource.access_department
            ),
            access_member_ids: normalizeAccessMemberIds(source.access_member_ids ?? fallbackSource.access_member_ids),
        };
    }

    function pageAccessStateSignature(value, fallback = {}) {
        return JSON.stringify(normalizedPageAccessState(value, fallback));
    }

    function normalizePageAccessDepartments(page, resetPersisted = true) {
        if (!page || typeof page !== 'object') return page;
        page.access_departments = normalizeAccessDepartments(page.access_departments, page.access_department);
        page.access_member_ids = normalizeAccessMemberIds(page.access_member_ids);
        if (resetPersisted || !page._persisted_access_state || typeof page._persisted_access_state !== 'object') {
            page._persisted_access_state = normalizedPageAccessState(page);
        }
        delete page.access_department;
        return page;
    }

    function defaultAccessDepartments(current = []) {
        const stored = normalizeAccessDepartments(current);
        if (stored.length) return stored;
        const ownDepartment = trimDepartmentName(state.user?.department);
        const fallback = ownDepartment || availableDepartments()[0] || '';
        return fallback ? [fallback] : [];
    }

    const privateParentMessage = () => t('page.privateParentError');

    function hasPrivateAncestor(page = state.page) {
        const visited = new Set();
        let parentId = Number(page?.parent_id || 0);
        while (parentId > 0) {
            if (visited.has(parentId)) return true;
            visited.add(parentId);
            const parent = pageById(parentId);
            if (!parent) return false;
            if (parent.status === 'private' || parent.visibility === 'private') return true;
            parentId = Number(parent.parent_id || 0);
        }
        return false;
    }
    function childrenOf(parentId) { return state.pages.filter(page => page.parent_id === parentId).sort((a, b) => Number(a.tree_order ?? Number.MAX_SAFE_INTEGER) - Number(b.tree_order ?? Number.MAX_SAFE_INTEGER) || Number(a.sort_order || 0) - Number(b.sort_order || 0) || a.id - b.id); }
    function rootPages() { return state.pages.filter(page => page.parent_id === null).sort((a, b) => new Date(b.updated_at) - new Date(a.updated_at)); }
    function unreadCount() { return state.notifications.filter(item => !Number(item.is_read)).length; }
    function canEditCurrentPage() { return Boolean(state.page?.can_edit); }
    function canEditPageSummary(pageId) {
        const page = pageById(pageId);
        return Boolean(page?.can_edit);
    }

    function memberCanViewPageDirect(member, page) {
        if (!page) return false;
        if (['admin', 'content_admin'].includes(member.role)) return true;
        if (Number(member.id) === Number(page.author_id)) return true;
        const visibility = page.status === 'private' ? 'private' : page.visibility;
        if (visibility === 'company') return true;
        if (visibility === 'department') {
            return normalizeAccessDepartments(page.access_departments)
                .includes(trimDepartmentName(member.department));
        }
        if (visibility === 'group') return normalizeAccessMemberIds(page.access_member_ids).includes(Number(member.id));
        return false;
    }

    function memberCanViewCurrentPage(member) {
        if (!state.page || ['admin', 'content_admin'].includes(member.role)) return Boolean(state.page);
        const visited = new Set();
        let page = state.page;
        while (page) {
            const pageId = Number(page.id || 0);
            if (pageId < 1 || visited.has(pageId) || !memberCanViewPageDirect(member, page)) return false;
            visited.add(pageId);
            const parentId = Number(page.parent_id || 0);
            if (parentId < 1) return true;
            page = pageById(parentId);
        }
        return false;
    }

    function renderShareMember(member) {
        const forced = ['admin', 'content_admin'].includes(member.role) || Number(member.id) === Number(state.page.author_id) || (state.page.visibility === 'group' && Number(member.id) === Number(state.user.id));
        const hasAccess = memberCanViewCurrentPage(member);
        const selector = state.page.visibility === 'group'
            ? `<input class="access-member-toggle" type="checkbox" data-change="access-member" value="${member.id}" ${hasAccess ? 'checked' : ''} ${forced ? 'disabled' : ''} aria-label="${esc(t('share.memberAccessFor', { name: member.name }))}">`
            : `<span class="member-access ${hasAccess ? 'allowed' : 'denied'}">${esc(hasAccess ? t('share.allowed') : t('share.excluded'))}</span>`;
        const rowTag = state.page.visibility === 'group' ? 'label' : 'div';
        return `<${rowTag} class="member-row share-member-row">${memberAvatar(member)}<span class="member-info"><span class="member-name">${esc(member.name)}${Number(member.id) === Number(state.page.author_id) ? ` (${esc(t('share.owner'))})` : ''}</span><span class="member-email">${esc(member.department)} · ${esc(roleNames()[member.role] || member.role)}</span></span>${selector}</${rowTag}>`;
    }

    function memberInitialLoginPending(member) {
        return member.initial_login_pending !== undefined
            ? Boolean(member.initial_login_pending)
            : Boolean(Number(member.must_change_password) && !member.last_login_at);
    }

    function renderSettingsMember(member) {
        const pending = memberInitialLoginPending(member);
        const inviteControls = pending
            ? `<div class="pending-invite-controls"><span class="member-status pending">${esc(t('settings.pendingLogin'))}</span>${state.capabilities.can_manage_members ? `<div class="pending-invite-actions"><button class="btn secondary compact member-invite-action" data-action="resend-invitation" data-member-id="${member.id}" title="${esc(t('settings.resendInvitationHelp', { email: member.email }))}">${svg('send', 'small')} ${esc(t('settings.resendInvitation'))}</button><button class="btn danger compact member-invite-action" data-action="delete-pending-invitation" data-member-id="${member.id}" title="${esc(t('settings.deletePendingInvitation'))}">${svg('trash', 'small')} ${esc(t('common.delete'))}</button></div>` : ''}</div>`
            : '';
        const isSelf = Number(member.id) === Number(state.user.id);
        const roleControl = state.capabilities.can_manage_members && member.role !== 'system' && !isSelf
            ? `<select class="select member-role-select" data-change="member-role" data-member-id="${member.id}" aria-label="${esc(t('settings.memberRole', { name: member.name }))}">${Object.entries(roleNames()).map(([value, label]) => `<option value="${value}" ${value === member.role ? 'selected' : ''}>${esc(label)}</option>`).join('')}</select>`
            : `<span class="member-role">${esc(roleNames()[member.role] || member.role)}</span>`;
        const resetControl = state.capabilities.can_manage_members && !pending && !isSelf
            ? `<button type="button" class="btn secondary compact" data-action="reset-member-password" data-member-id="${member.id}">${svg('lock', 'small')} ${esc(t('settings.resetPassword'))}</button>`
            : '';
        const nameControl = state.capabilities.can_manage_members
            ? `<button type="button" class="btn secondary compact" data-action="edit-member-name" data-member-id="${member.id}">${svg('edit', 'small')} ${esc(t('settings.editMemberName'))}</button>`
            : '';
        return `<div class="member-row member-row-tall">${memberAvatar(member)}<div class="member-info"><div class="member-name">${esc(member.name)}${isSelf ? ` (${esc(t('settings.you'))})` : ''}</div><div class="member-email">${esc(member.email)} · ${esc(member.department)}</div>${inviteControls}</div><div class="member-admin-controls">${nameControl}${resetControl}${roleControl}</div></div>`;
    }

    function renderSuspendedMember(member) {
        const roleControl = `<select class="select member-role-select" data-change="suspended-member-role" data-member-id="${member.id}" aria-label="${esc(t('settings.memberRole', { name: member.name }))}">${Object.entries(roleNames()).map(([value, label]) => `<option value="${value}" ${value === 'suspended' ? 'selected' : ''}>${esc(label)}</option>`).join('')}</select>`;
        const nameControl = `<button type="button" class="btn secondary compact" data-action="edit-member-name" data-member-id="${member.id}">${svg('edit', 'small')} ${esc(t('settings.editMemberName'))}</button>`;
        return `<div class="member-row member-row-tall suspended-member"><span class="avatar suspended-avatar">${svg('lock', 'small')}</span><div class="member-info"><div class="member-name">${esc(member.name)}</div><div class="member-email">${esc(member.email)} · ${esc(member.department)}</div><span class="member-status suspended">${esc(t('settings.suspendedBlocked'))}</span></div><div class="member-admin-controls">${nameControl}${roleControl}</div></div>`;
    }

    function pagePath(id) {
        const result = [];
        let current = pageById(id);
        const seen = new Set();
        while (current && !seen.has(current.id)) {
            result.unshift(current);
            seen.add(current.id);
            current = current.parent_id ? pageById(current.parent_id) : null;
        }
        return result;
    }

    function pageIsDescendantOf(pageId, ancestorId) {
        return pagePath(pageId).some(page => Number(page.id) === Number(ancestorId));
    }

    function renderTree(parentId = null, level = 0) {
        return childrenOf(parentId).map(page => {
            const children = childrenOf(page.id);
            const open = state.expanded.has(page.id);
            const movable = canEditPageSummary(page.id);
            return `<li class="tree-node" data-tree-node-id="${page.id}">
                <div class="tree-row ${state.page?.id === page.id ? 'active' : ''} ${movable ? 'movable' : ''}" data-action="open-page" data-id="${page.id}" data-tree-page-id="${page.id}" draggable="${movable ? 'true' : 'false'}" style="padding-left:${Math.min(level * 14, 56)}px">
                    <button class="tree-toggle ${children.length ? '' : 'placeholder'} ${open ? 'open' : ''}" data-action="toggle-tree" data-id="${page.id}" aria-label="${esc(t('common.openClose'))}">${svg('chevron', 'tiny')}</button>
                    <span class="tree-icon">${esc(page.icon)}</span>
                    <span class="tree-label">${esc(page.title)}</span>
                    <button class="tree-more" data-action="tree-more" data-id="${page.id}" aria-label="${esc(t('common.menu'))}">•••</button>
                </div>
                ${children.length ? `<ul class="tree-children ${open ? '' : 'hidden'}">${renderTree(page.id, level + 1)}</ul>` : ''}
            </li>`;
        }).join('');
    }

    function normalizeLocalSearch(value) {
        return String(value || '').normalize('NFKC').toLocaleLowerCase('ja-JP').trim();
    }

    function normalizePageTag(value) {
        return String(value || '').normalize('NFKC').toLocaleLowerCase('ja-JP').trim().replace(/[\s\p{P}\p{S}]+/gu, '');
    }

    function uniquePageTagNames(values, limit = 12) {
        const tags = [];
        const seen = new Set();
        for (const value of Array.isArray(values) ? values : []) {
            const name = String(value || '').trim().replace(/^#+/u, '').slice(0, 40);
            const normalized = normalizePageTag(name);
            if (!name || !normalized || seen.has(normalized)) continue;
            tags.push(name);
            seen.add(normalized);
            if (tags.length >= limit) break;
        }
        return tags;
    }

    function syncPageTags(page) {
        if (!page) return;
        page.manual_tags = uniquePageTagNames(Array.isArray(page.manual_tags) ? page.manual_tags : page.tags, 12);
        page.generated_tags = uniquePageTagNames(page.generated_tags || [], 8);
        page.tags = uniquePageTagNames([...page.manual_tags, ...page.generated_tags], 12);
    }

    function orderedVisiblePages(parentId = null, visited = new Set()) {
        const ordered = [];
        childrenOf(parentId).forEach(page => {
            if (visited.has(page.id)) return;
            visited.add(page.id);
            ordered.push(page, ...orderedVisiblePages(page.id, visited));
        });
        return ordered;
    }

    function localSearchMatches() {
        const normalizedQuery = normalizeLocalSearch(state.localSearchQuery);
        const tokens = normalizedQuery.split(/\s+/).map(token => token.replace(/^#+/, '')).filter(Boolean);
        if (!tokens.length) return { pages: [], files: [] };
        const exactTag = normalizeLocalSearch(state.localSearchTag);
        const pages = orderedVisiblePages().filter(page => {
            const normalizedTags = (page.tags || []).map(normalizeLocalSearch);
            if (exactTag) return normalizedTags.includes(exactTag);
            const haystack = normalizeLocalSearch([page.title, page.plain_text, page.category, page.author_name, ...normalizedTags].join(' '));
            return tokens.every(token => haystack.includes(token));
        });
        const files = exactTag ? [] : state.files.filter(file => {
            const haystack = normalizeLocalSearch([file.original_name, file.page_title, file.uploader_name, file.category].join(' '));
            return tokens.every(token => haystack.includes(token));
        });
        return { pages, files };
    }

    function renderSidebarPageTree() {
        if (!normalizeLocalSearch(state.localSearchQuery)) {
            return `${renderTree()}<li class="tree-root-drop" data-tree-root-drop>${esc(t('sidebar.moveTopLevel'))}</li>`;
        }
        const matches = localSearchMatches();
        const total = matches.pages.length + matches.files.length;
        return `<li class="local-search-summary" role="status">${esc(total ? t('sidebar.results', { count: total }) : t('sidebar.noResults'))}</li>
            ${matches.pages.map(page => `<li class="local-search-result"><button class="tree-row local-search-page" data-action="open-page" data-id="${page.id}"><span class="tree-icon">${esc(page.icon)}</span><span class="local-search-result-copy"><strong>${esc(page.title)}</strong><small>${esc(page.category)}${page.tags?.length ? ` · ${page.tags.slice(0, 3).map(tag => `#${esc(tag)}`).join(' ')}` : ''}</small></span></button></li>`).join('')}
            ${matches.files.length ? `<li class="local-search-kind">${esc(t('navigation.files'))}</li>${matches.files.map(file => `<li class="local-search-result"><a class="tree-row local-search-file" href="api.php?action=file-content&id=${file.id}" target="_blank" rel="noopener">${svg('fileText', 'small')}<span class="local-search-result-copy"><strong>${esc(file.original_name)}</strong><small>${esc(file.page_title || t('sidebar.pageUnspecified'))}</small></span></a></li>`).join('')}` : ''}`;
    }

    function updateLocalSearch(query, exactTag = '') {
        state.localSearchQuery = String(query || '');
        state.localSearchTag = exactTag;
        const input = root.querySelector('#localSearchInput');
        if (input && input.value !== state.localSearchQuery) input.value = state.localSearchQuery;
        const tree = root.querySelector('.page-tree-main');
        if (tree) tree.innerHTML = renderSidebarPageTree();
        const clearButton = root.querySelector('[data-action="clear-local-search"]');
        if (clearButton) clearButton.hidden = !normalizeLocalSearch(state.localSearchQuery);
    }

    function searchByTag(tag) {
        const cleanTag = String(tag || '').replace(/^#+/, '').trim();
        if (!cleanTag) return;
        state.sidebarCollapsed = false;
        state.mobileSidebar = innerWidth <= 760;
        state.localSearchQuery = cleanTag;
        state.localSearchTag = '';
        renderApp();
        setTimeout(() => root.querySelector('#localSearchInput')?.focus(), 0);
    }

    function renderSidebar() {
        const favorites = state.pages.filter(page => page.is_favorite).slice(0, 5);
        const renderPluginSidebarItem = (item, nested = false) => {
            const label = pluginT(item.id, 'plugin.sidebar.label', {}, item.label);
            const icon = String(item.icon || '🧩').trim() || '🧩';
            const iconClass = /^[a-z0-9]{2,4}$/i.test(icon) ? ' is-text-badge' : '';
            return `<li class="${nested ? 'ai-search-plugin-entry' : ''}"><button class="side-item plugin-side-item ${nested ? 'ai-search-plugin-side-item' : ''}" data-action="open-plugin" data-plugin-id="${esc(item.id)}" title="${esc(label)}"><span class="plugin-side-icon${iconClass}" aria-hidden="true">${esc(icon)}</span><span class="label">${esc(label)}</span></button></li>`;
        };
        const aiSearchPluginItems = state.pluginUi.filter(item => item.placement === 'ai-search').map(item => renderPluginSidebarItem(item, true)).join('');
        const pluginItems = state.pluginUi.filter(item => item.placement !== 'ai-search').map(item => renderPluginSidebarItem(item)).join('');
        return `<aside class="sidebar ${state.sidebarCollapsed ? 'collapsed' : ''} ${state.mobileSidebar ? 'mobile-open' : ''}">
            <button type="button" class="workspace-switcher" data-action="workspace-menu" aria-haspopup="menu" aria-expanded="${state.context?.kind === 'workspace' ? 'true' : 'false'}">
                ${brandLogo('workspace-avatar')}
                <div class="workspace-meta"><div class="workspace-name">${esc(state.workspaceName)}</div><div class="workspace-plan">OpenConcept workspace</div></div>
                ${svg('chevron', 'tiny')}
            </button>
            <div class="sidebar-body">
                ${state.organizationName ? `<div class="sidebar-organization" title="${esc(state.organizationName)}">${svg('users', 'small')}<span>${esc(state.organizationName)}</span></div>` : ''}
                <ul class="side-list">
                    <li><button class="side-item ${state.dialog === 'ai-search' ? 'active' : ''}" data-action="search">${svg('search')}<span class="label">${esc(t('navigation.aiSearch'))}</span><span class="kbd">⌘ K</span></button></li>
                    ${aiSearchPluginItems}
                    <li><button class="side-item ${state.view === 'home' ? 'active' : ''}" data-action="home">${svg('home')}<span class="label">${esc(t('navigation.home'))}</span></button></li>
                    <li><button class="side-item" data-action="inbox">${svg('inbox')}<span class="label">${esc(t('navigation.inbox'))}</span>${unreadCount() ? `<span class="count-badge unread">${unreadCount()}</span>` : ''}</button></li>
                </ul>
                ${pluginItems ? `<ul class="side-list plugin-side-list">${pluginItems}</ul>` : ''}
                ${favorites.length ? `<section class="sidebar-section"><div class="sidebar-title"><span>${esc(t('common.favorites'))}</span></div><ul class="page-tree">${favorites.map(page => `<li><div class="tree-row ${state.page?.id === page.id ? 'active' : ''}" data-action="open-page" data-id="${page.id}"><span class="tree-toggle placeholder"></span><span class="tree-icon">${esc(page.icon)}</span><span class="tree-label">${esc(page.title)}</span></div></li>`).join('')}</ul></section>` : ''}
                <section class="sidebar-section">
                    <div class="sidebar-title"><span>${esc(t('navigation.pages'))}</span>${state.capabilities.can_create_page ? `<button data-action="new-page" title="${esc(t('page.new'))}">${svg('plus', 'tiny')}</button>` : ''}</div>
                    <div class="tree-search"><span class="tree-search-icon">${svg('search', 'small')}</span><input id="localSearchInput" type="search" value="${esc(state.localSearchQuery)}" placeholder="${esc(t('sidebar.searchPlaceholder'))}" aria-label="${esc(t('sidebar.searchAria'))}"><button type="button" data-action="clear-local-search" aria-label="${esc(t('common.clearSearch'))}" ${normalizeLocalSearch(state.localSearchQuery) ? '' : 'hidden'}>${svg('close', 'tiny')}</button></div>
                    <ul class="page-tree page-tree-main">${renderSidebarPageTree()}</ul>
                </section>
            </div>
            <div class="sidebar-footer">
                ${state.capabilities.can_create_page ? `<button class="side-item" data-action="new-page">${svg('plus')}<span class="label">${esc(t('page.new'))}</span></button>` : ''}
                <button class="side-item ${state.view === 'files' ? 'active' : ''}" data-action="files">${svg('folder')}<span class="label">${esc(t('navigation.files'))}</span>${state.files.length ? `<span class="count-badge">${state.files.length}</span>` : ''}</button>
                <button class="side-item ${state.view === 'trash' ? 'active' : ''}" data-action="trash">${svg('trash')}<span class="label">${esc(t('navigation.trash'))}</span>${state.trash.length ? `<span class="count-badge">${state.trash.length}</span>` : ''}</button>
                <button class="side-item ${state.dialog === 'members' ? 'active' : ''}" data-action="members">${svg('users')}<span class="label">${esc(t('common.members'))}</span></button>
                <button class="side-item ${state.view === 'settings' ? 'active' : ''}" data-action="settings">${svg('settings')}<span class="label">${esc(t('navigation.settings'))}</span></button>
                <button type="button" class="sidebar-user" data-action="profile" aria-label="${esc(t('profile.open'))}">${memberAvatar(state.user, 'sm')}<span>${esc(state.user.name)}</span><span class="sidebar-user-edit">${svg('edit', 'tiny')}</span></button>
            </div>
        </aside>`;
    }

    function renderTopbar() {
        const path = state.page ? pagePath(state.page.id) : [];
        const registeredTranslationProviders = Array.isArray(state.page?.translation?.providers)
            ? state.page.translation.providers
            : [];
        return `<header class="topbar">
            <div class="topbar-left">
                <button class="btn icon-only sidebar-open" data-action="toggle-sidebar" title="${esc(t('sidebar.open'))}">${svg('menu')}</button>
                <div class="breadcrumbs">
                    ${state.view === 'home' ? `<span class="breadcrumb">${esc(t('navigation.home'))}</span>` : state.view === 'trash' ? `<span class="breadcrumb">${esc(t('navigation.trash'))}</span>` : state.view === 'files' ? `<span class="breadcrumb">${esc(t('navigation.files'))}</span>` : state.view === 'settings' ? `<span class="breadcrumb">${esc(t('navigation.settings'))}</span>` : path.map((page, index) => `${index ? '<span class="breadcrumb-sep">/</span>' : ''}<span class="breadcrumb" data-action="open-page" data-id="${page.id}">${esc(page.title)}</span>`).join('')}
                </div>
            </div>
            <div class="topbar-actions">
                ${state.page ? `<div class="save-state ${state.saving ? 'saving' : ''}"><span class="saved-dot"></span><span>${esc(state.saving ? t('common.saving') : (state.lastSaved ? t('common.saved') : t('common.latest')))}</span></div>
                    ${canEditCurrentPage() ? `<button class="btn undo-button" data-action="undo" title="${esc(state.undoHistory.length ? t('common.undoRemaining', { count: state.undoHistory.length }) : t('common.undo'))}" aria-label="${esc(t('common.undo'))}" ${state.undoHistory.length ? '' : 'disabled'}>${svg('history', 'small')}<span class="btn-label">${esc(t('common.undo'))}</span></button>` : ''}
                    ${state.capabilities.can_create_page && registeredTranslationProviders.length ? `<button class="btn" data-action="translate-page">${svg('globe', 'small')}<span class="btn-label">${esc(t('page.translate'))}</span></button>` : ''}
                    <button class="btn editor-find-open ${state.editorFind.open ? 'active' : ''}" data-action="editor-find-open" title="${esc(t('page.findReplaceShortcut'))}" aria-label="${esc(t('page.findReplace'))}" aria-expanded="${state.editorFind.open ? 'true' : 'false'}">${svg('search', 'small')}<span class="btn-label">${esc(t('page.findReplace'))}</span></button>
                    <button class="btn icon-only favorite-button ${state.page.is_favorite ? 'active' : ''}" data-action="favorite" title="${esc(state.page.is_favorite ? t('page.favorite.remove') : t('page.favorite.add'))}" aria-label="${esc(state.page.is_favorite ? t('page.favorite.selected') : t('page.favorite.unselected'))}" aria-pressed="${state.page.is_favorite ? 'true' : 'false'}">${svg('star')}</button>
                    <button class="btn icon-only comment-button" data-action="comments" title="${esc(t('common.comments'))}" aria-label="${esc(t('common.comments'))}">${svg('comment')}${unresolvedComments().length ? `<span class="mini-count" aria-hidden="true">${unresolvedComments().length}</span>` : ''}</button>
                    ${canEditCurrentPage() ? `<button class="btn share-button" data-action="share">${svg('share', 'small')}<span class="btn-label">${esc(t('common.share'))}</span></button>` : ''}
                    <button class="btn icon-only" data-action="page-more" aria-label="${esc(t('page.menu'))}">${svg('more')}</button>` : ''}
            </div>
        </header>`;
    }

    function normalizeAffectedPublication(value) {
        if (!value || typeof value !== 'object') return null;
        const rootPageId = Number(value.root_page_id || 0);
        if (!Number.isInteger(rootPageId) || rootPageId < 1) return null;
        const revision = typeof value.revision === 'string' || typeof value.revision === 'number'
            ? value.revision
            : null;
        return {
            root_page_id: rootPageId,
            title: String(value.title || t('page.untitled')),
            public_url: normalizePublicUrl(value.public_url),
            revision,
        };
    }

    function mergeAffectedPublications(value) {
        if (!Array.isArray(value) || !value.length) return;
        if (!(state.affectedPublications instanceof Map)) state.affectedPublications = new Map();
        value.forEach(item => {
            const publication = normalizeAffectedPublication(item);
            if (!publication) return;
            state.affectedPublications.set(publication.root_page_id, publication);
            if (Number(state.page?.id || 0) === publication.root_page_id && state.publicShare?.enabled) {
                state.publicShare.revision = publication.revision;
                if (!['modified', 'missing', 'publishing'].includes(state.publicShare.publication_state)) {
                    state.publicShare.publication_state = 'stale';
                    state.publicShare.can_update = true;
                }
            }
        });
        syncAffectedPublicationsBanner();
    }

    function clearAffectedPublication(rootPageId, sync = true) {
        if (state.affectedPublications instanceof Map) {
            state.affectedPublications.delete(Number(rootPageId || 0));
        }
        if (sync) syncAffectedPublicationsBanner();
    }

    function renderAffectedPublicationsBanner() {
        const publications = state.affectedPublications instanceof Map
            ? [...state.affectedPublications.values()]
            : [];
        if (!publications.length) return '';
        const count = publications.length;
        const titles = publications.slice(0, 3).map(item => item.title).join(', ');
        const more = Math.max(0, count - 3);
        const summary = more > 0 ? `${titles} +${more}` : titles;
        return `<aside id="affectedPublicationsBanner" class="affected-publications-banner" aria-live="polite"><span class="affected-publications-icon" aria-hidden="true">${svg('globe', 'small')}</span><div class="affected-publications-copy"><strong>${esc(t('share.public.updateBanner'))}</strong><span>${esc(t('share.public.updateBannerSites', { count }))}${summary ? ` <span class="affected-publications-titles">${esc(summary)}</span>` : ''}</span></div><div class="affected-publications-actions"><button type="button" class="btn primary compact" data-action="refresh-affected-publications" ${state.affectedPublicationsRefreshing ? 'disabled' : ''}>${esc(state.affectedPublicationsRefreshing ? t('share.public.publishing') : t('share.public.updateNow'))}</button><button type="button" class="btn secondary compact" data-action="dismiss-affected-publications" ${state.affectedPublicationsRefreshing ? 'disabled' : ''}>${esc(t('share.public.later'))}</button></div></aside>`;
    }

    function syncAffectedPublicationsBanner() {
        if (!state.initialized || !root) return;
        const markup = renderAffectedPublicationsBanner();
        const existing = root.querySelector('#affectedPublicationsBanner');
        if (!markup) {
            existing?.remove();
            return;
        }
        if (existing) {
            existing.outerHTML = markup;
            return;
        }
        root.querySelector('.topbar')?.insertAdjacentHTML('afterend', markup);
    }

    function rememberSidebarScroll() {
        const sidebarBody = root.querySelector('.sidebar-body');
        if (sidebarBody) state.sidebarScrollTop = sidebarBody.scrollTop;
    }

    function restoreSidebarScroll() {
        const sidebarBody = root.querySelector('.sidebar-body');
        if (!sidebarBody) return;
        const scrollTop = Math.max(0, Number(state.sidebarScrollTop) || 0);
        sidebarBody.scrollTop = scrollTop;
        requestAnimationFrame(() => { sidebarBody.scrollTop = scrollTop; });
        sidebarBody.addEventListener('scroll', () => {
            state.sidebarScrollTop = sidebarBody.scrollTop;
        }, { passive: true });
    }

    function renderApp() {
        rememberSidebarScroll();
        state.commentPicker = null;
        const view = state.view === 'home'
            ? renderHome()
            : state.view === 'trash'
            ? renderTrash()
            : state.view === 'files'
            ? renderFiles()
            : state.view === 'settings'
            ? renderSettingsPage()
            : renderPage();
        const aiLauncher = state.panel === 'comments' && state.page ? '' : renderAiLauncher();
        root.innerHTML = `${renderSidebar()}<div class="mobile-overlay ${state.mobileSidebar ? 'visible' : ''}" data-action="close-mobile-sidebar"></div><main class="main-shell"><section class="main-view">${renderTopbar()}${renderAffectedPublicationsBanner()}${view}</section>${state.panel ? renderPanel() : ''}</main>${aiLauncher}${renderFloating()}${renderDialog()}`;
        restoreSidebarScroll();
        afterRender();
    }

    function renderHome() {
        const recents = [...state.pages].sort((a, b) => new Date(b.updated_at) - new Date(a.updated_at)).slice(0, 6);
        return `<div class="home-view"><div class="home-inner">
            <header class="home-header"><div class="home-user-identity">${memberAvatar(state.user)}<div class="home-user-name">${esc(state.user.name)}（${esc(state.user.email)}）</div></div><div class="home-subtitle">${esc(t('home.subtitle'))}</div></header>
            <section class="home-section">
                <div class="home-section-title"><span>${esc(t('home.recentPages'))}</span><button data-action="search">${esc(t('home.showAll'))}</button></div>
                <div class="recent-grid">${recents.slice(0, 3).map(page => `<article class="page-card" data-action="open-page" data-id="${page.id}"><div class="card-cover ${esc(page.cover)}"><span class="card-icon">${esc(page.icon)}</span></div><div class="card-body"><div class="card-title">${esc(page.title)}</div><div class="card-meta">${esc(t('home.updatedAt', { category: page.category, time: timeAgo(page.updated_at) }))}</div></div></article>`).join('')}</div>
            </section>
            <section class="home-section">
                <div class="home-section-title"><span>${esc(t('home.teamUpdates'))}</span><button data-action="inbox">${esc(t('home.openInbox'))}</button></div>
                <div class="updates-list">${recents.slice(0, 5).map(page => `<div class="update-row" data-action="open-page" data-id="${page.id}"><span class="update-icon">${esc(page.icon)}</span><div class="update-main"><div class="update-title">${esc(page.title)}</div><div class="update-detail">${esc(t('home.updatedBy', { name: page.author_name }))}</div></div><span class="update-time">${timeAgo(page.updated_at)}</span></div>`).join('')}</div>
            </section>
        </div></div>`;
    }

    function renderTrash() {
        return `<div class="trash-view"><div class="trash-inner">
            <header class="trash-header"><div class="trash-title-icon">${svg('trash')}</div><div><h1>${esc(t('navigation.trash'))}</h1><p>${esc(t('trash.description'))}</p></div></header>
            ${state.trash.length ? `<div class="trash-list">${state.trash.map(page => `<article class="trash-row"><span class="trash-page-icon">${esc(page.icon)}</span><div class="trash-page-info"><strong>${esc(page.title)}</strong><span>${esc(t('trash.movedAt', { category: page.category, time: timeAgo(page.archived_at) }))}</span></div><div class="trash-actions">${page.can_restore ? `<button class="btn secondary compact" data-action="restore-page" data-id="${page.id}">${svg('history', 'small')} ${esc(t('trash.restore'))}</button>` : ''}${state.capabilities.can_delete_permanently ? `<button class="btn danger compact" data-action="delete-page-permanently" data-id="${page.id}" data-title="${esc(page.title)}">${svg('trash', 'small')} ${esc(t('trash.deletePermanently'))}</button>` : ''}</div></article>`).join('')}</div>` : `<div class="trash-empty"><span>${svg('trash')}</span><strong>${esc(t('trash.empty'))}</strong><p>${esc(t('trash.emptyHelp'))}</p></div>`}
        </div></div>`;
    }

    function renderFileUsage(file) {
        const references = Array.isArray(file.references)
            ? file.references.filter(reference => Number(reference?.page_id) > 0)
            : [];
        if (!references.length) {
            return `<div class="file-usage unused"><span class="file-usage-badge">${esc(t('files.unused'))}</span><small>${esc(t('files.noPageLinks'))}</small></div>`;
        }
        const links = references.map(reference => `<button type="button" class="file-reference-link" data-action="open-page" data-id="${Number(reference.page_id)}" title="${esc(t('files.openPage', { title: reference.page_title || t('page.untitled') }))}">${esc(reference.page_title || t('page.untitled'))}</button>`).join('');
        return `<div class="file-usage linked"><span class="file-usage-badge">${svg('check', 'tiny')} ${esc(t('files.inUse'))}</span><div class="file-reference-pages"><small>${esc(t('files.linkTargets'))}</small>${links}</div></div>`;
    }

    function renderFileRow(file) {
        return `<article class="file-library-row">${fileKindIcon(file.category)}<a class="file-library-link" href="api.php?action=file-content&id=${file.id}" target="_blank" rel="noopener"><strong>${esc(file.original_name)}</strong><span>${formatBytes(file.size_bytes)} · ${esc(file.uploader_name)} · ${timeAgo(file.created_at)}${file.page_title ? ` · ${esc(t('files.uploadSource', { page: file.page_title }))}` : ''}</span></a>${renderFileUsage(file)}<div class="file-library-actions">${svg('external', 'small')}${file.can_delete ? `<button class="file-delete-button" data-action="delete-file" data-id="${file.id}" data-title="${esc(file.original_name)}" aria-label="${esc(t('files.deleteFile', { name: file.original_name }))}" title="${esc(t('common.delete'))}">${svg('trash', 'small')}</button>` : ''}</div></article>`;
    }

    function fileLibraryResults() {
        const query = state.fileSearchQuery.normalize('NFKC').toLocaleLowerCase().trim();
        const matches = state.files.filter(file => String(file.original_name || '').normalize('NFKC').toLocaleLowerCase().includes(query));
        const filtered = matches.filter(file => !state.fileTypeFilter || file.category === state.fileTypeFilter);
        const pages = Math.max(1, Math.ceil(filtered.length / 20));
        state.fileListPage = Math.max(1, Math.min(state.fileListPage, pages));
        return { matches, filtered, pages, rows: filtered.slice((state.fileListPage - 1) * 20, state.fileListPage * 20) };
    }

    function renderFiles() {
        const { matches, filtered, pages, rows } = fileLibraryResults();
        const listing = Boolean(state.fileSearchQuery.trim() || state.fileTypeFilter);
        const categories = [...new Set([...Object.keys(fileCategory), ...state.files.map(file => file.category)])];
        const groups = categories.map(category => ({ category, files: state.files.filter(file => file.category === category) })).filter(group => group.files.length);
        const empty = `<div class="files-empty"><span>${svg('folder')}</span><strong>${esc(t(listing ? 'files.noResults' : 'files.empty'))}</strong><p>${esc(t(listing ? 'files.noResultsHelp' : 'files.emptyHelp'))}</p></div>`;
        return `<div class="files-view"><div class="files-inner">
            <header class="files-header"><div class="files-title-icon">${svg('folder')}</div><div><h1>${esc(t('navigation.files'))}</h1><p>${esc(t('files.description'))}</p></div><span class="files-total">${esc(t('common.itemCount', { count: state.files.length }))}</span></header>
            <form id="fileSearchForm" class="file-search-form" role="search">
                <label for="fileSearchInput">${esc(t('files.searchName'))}</label>
                <div class="file-search-controls"><input id="fileSearchInput" type="search" value="${esc(state.fileSearchQuery)}" placeholder="${esc(t('files.searchPlaceholder'))}"><button class="btn secondary" type="submit">${esc(t('files.searchAll'))}</button></div>
            </form>
            <div class="file-filter-controls"><label for="fileTypeFilter">${esc(t('files.filterType'))}</label><select id="fileTypeFilter"><option value="">${esc(t('files.allTypes'))} (${matches.length})</option>${categories.map(category => `<option value="${esc(category)}" ${state.fileTypeFilter === category ? 'selected' : ''}>${esc(fileCategoryLabel(category))} (${matches.filter(file => file.category === category).length})</option>`).join('')}</select>${listing ? `<button class="btn secondary compact" data-action="file-overview">${esc(t('files.backToOverview'))}</button>` : ''}</div>
            ${listing ? `<section class="file-results" aria-live="polite"><div class="file-results-summary">${esc(t('files.resultCount', { count: filtered.length }))}</div>${rows.length ? `<div class="file-library-list">${rows.map(renderFileRow).join('')}</div><nav class="file-pagination" aria-label="${esc(t('files.pagination'))}"><button class="btn secondary compact" data-action="file-list-page" data-page="${state.fileListPage - 1}" ${state.fileListPage <= 1 ? 'disabled' : ''}>${esc(t('files.previous'))}</button><span>${esc(t('files.pageStatus', { page: state.fileListPage, pages, start: (state.fileListPage - 1) * 20 + 1, end: Math.min(state.fileListPage * 20, filtered.length), total: filtered.length }))}</span><button class="btn secondary compact" data-action="file-list-page" data-page="${state.fileListPage + 1}" ${state.fileListPage >= pages ? 'disabled' : ''}>${esc(t('files.next'))}</button></nav>` : empty}</section>` : groups.length ? groups.map(group => `<section class="file-group"><div class="file-group-heading">${fileKindIcon(group.category)}<strong>${esc(fileCategoryLabel(group.category))}</strong><span class="file-group-count">${group.files.length}</span>${group.files.length >= 5 ? `<button class="btn secondary compact file-show-all" data-action="file-category-list" data-category="${esc(group.category)}">${esc(t('files.showAll'))}</button>` : ''}</div><div class="file-library-list">${group.files.slice(0, 5).map(renderFileRow).join('')}</div></section>`).join('') : empty}
        </div></div>`;
    }

    function renderPage() {
        if (!state.page) return '<div class="app-loading"><span class="loading-dot"></span></div>';
        const page = state.page;
        const withCover = page.cover !== 'none';
        const childPageToc = renderChildPageToc(page.id);
        const translationVersions = Array.isArray(page.translation?.versions) ? page.translation.versions : [];
        const currentTranslation = translationVersions.find(version => Number(version.page_id) === Number(page.id));
        const otherTranslations = translationVersions.filter(version => Number(version.page_id) !== Number(page.id));
        return `<div class="page-scroller" id="pageScroller">
            <div class="cover ${esc(page.cover)}">
                ${state.panel === 'comments' ? renderAiLauncher('cover') : ''}
                ${withCover && canEditCurrentPage() ? `<div class="cover-controls"><button class="btn cover-control" data-action="cover-picker">${svg('image', 'tiny')} ${esc(t('page.cover.change'))}</button></div>` : ''}
            </div>
            <article class="page ${withCover ? 'with-cover' : 'without-cover'}">
                <div class="page-icon-wrap"><button class="page-icon" ${canEditCurrentPage() ? 'data-action="icon-picker"' : ''} aria-label="${esc(t('page.icon.label'))}">${esc(page.icon)}</button></div>
                ${canEditCurrentPage() ? `<div class="page-actions-inline"><button class="page-action" data-action="icon-picker">${esc(t('page.icon.change'))}</button>${!withCover ? `<button class="page-action" data-action="cover-picker">${esc(t('page.cover.add'))}</button>` : ''}</div>` : '<div class="page-actions-inline"></div>'}
                <h1 class="page-title" id="pageTitle" contenteditable="${canEditCurrentPage() ? 'true' : 'false'}" spellcheck="false">${esc(page.title)}</h1>
                <div class="page-meta">
                    <button class="status-pill ${esc(page.status)}" ${canEditCurrentPage() ? 'data-action="metadata"' : ''}>${svg(page.status === 'published' ? 'check' : 'clock', 'tiny')} ${esc(statusNames()[page.status] || page.status)}</button>
                    <span class="category-pill">${esc(page.category)}</span>
                    <span class="language-pill" title="${esc(t('common.language'))}">${esc(languageName(page.language_code))}</span>
                    ${currentTranslation?.outdated ? `<span class="translation-warning">⚠ ${esc(t('page.translation.outdated'))}</span>` : ''}
                    ${otherTranslations.map(version => `<button class="translation-version-link" data-action="open-page" data-id="${Number(version.page_id)}">${esc(languageName(version.language))}</button>`).join('')}
                    ${(page.tags || []).map(tag => `<button class="tag-pill" data-action="search-by-tag" data-tag="${esc(tag)}" title="${esc(t('page.searchRelatedTag', { tag: `#${tag}` }))}">#${esc(tag)}</button>`).join('')}
                    ${canEditCurrentPage() ? `<button class="meta-add" data-action="metadata">＋ ${esc(t('page.propertiesShort'))}</button>` : ''}
                    <span class="meta-sep">·</span>
                    <span class="author-inline">${authorAvatar(page, 'xs')}${esc(page.author_name)}</span>
                    <span>${esc(t('page.updated', { time: timeAgo(page.updated_at) }))}</span>
                </div>
                <div class="editor" id="editor">${renderEditorTopInsert()}${page.blocks.map(renderBlock).join('')}${canEditCurrentPage() ? `<div class="editor-bottom" data-action="append-block">${esc(t('page.continueEditing'))}</div>` : ''}${childPageToc}</div>
            </article>
        </div>`;
    }

    function renderEditorTopInsert() {
        if (!canEditCurrentPage()) return '';
        const firstBlockId = state.page?.blocks?.[0]?.id || '';
        return `<div class="editor-prepend"><button class="editor-prepend-button" data-action="prepend-block" data-id="${esc(firstBlockId)}" type="button">${svg('plus', 'tiny')}<span>${esc(t('page.addBlockTop'))}</span></button></div>`;
    }

    function renderChildPageToc(parentId) {
        const children = childrenOf(Number(parentId));
        if (!children.length) return '';
        return `<nav class="child-page-toc" aria-label="${esc(t('page.childPages'))}">
            <div class="child-page-toc-header"><strong>${esc(t('page.childPages'))}</strong><span>${esc(t('common.itemCount', { count: children.length }))}</span></div>
            <div class="child-page-toc-list">
                ${children.map(child => `<a class="child-page-link" href="#page-${child.id}" data-action="open-page" data-id="${child.id}"><span class="child-page-link-icon">${esc(child.icon)}</span><span class="child-page-link-title">${esc(child.title)}</span><span class="child-page-link-arrow" aria-hidden="true">›</span></a>`).join('')}
            </div>
        </nav>`;
    }

    const tableBackgroundPalette = ['default', 'gray', 'brown', 'orange', 'yellow', 'green', 'blue', 'purple', 'pink', 'red'];

    function normalizeTableBackground(background) {
        return tableBackgroundPalette.includes(background) ? background : 'default';
    }

    function emptyTableCell() {
        return { content: '', align: 'left', background: 'default' };
    }

    function createTableFields(rowCount = 3, columnCount = 3) {
        return {
            table_rows: Array.from({ length: rowCount }, () => Array.from({ length: columnCount }, emptyTableCell)),
            table_widths: Array.from({ length: columnCount }, () => 100 / columnCount),
        };
    }

    function normalizeTableBlock(block) {
        if (!Array.isArray(block.table_rows) || !block.table_rows.length) Object.assign(block, createTableFields());
        const columnCount = Math.max(1, Math.min(12, ...block.table_rows.map(row => Array.isArray(row) ? row.length : 0)));
        block.table_rows = block.table_rows.slice(0, 50).map(row => {
            const cells = Array.isArray(row) ? row.slice(0, columnCount) : [];
            while (cells.length < columnCount) cells.push(emptyTableCell());
            return cells.map(cell => typeof cell === 'object' && cell !== null
                ? { content: String(cell.content || ''), align: ['left', 'center', 'right'].includes(cell.align) ? cell.align : 'left', background: normalizeTableBackground(cell.background) }
                : { content: String(cell || ''), align: 'left', background: 'default' });
        });
        if (!Array.isArray(block.table_widths) || block.table_widths.length !== columnCount) {
            block.table_widths = Array.from({ length: columnCount }, () => 100 / columnCount);
        }
        return block;
    }

    function tableAlignIcon(align) {
        return `<span class="table-align-icon ${align}" aria-hidden="true"><i></i><i></i><i></i></span>`;
    }

    function tableSelectionBounds(selection = state.tableSelection) {
        if (!selection) return null;
        const anchorRow = Number(selection.anchorRow ?? selection.row ?? 0);
        const anchorCol = Number(selection.anchorCol ?? selection.col ?? 0);
        const focusRow = Number(selection.focusRow ?? selection.row ?? anchorRow);
        const focusCol = Number(selection.focusCol ?? selection.col ?? anchorCol);
        return {
            startRow: Math.min(anchorRow, focusRow),
            endRow: Math.max(anchorRow, focusRow),
            startCol: Math.min(anchorCol, focusCol),
            endCol: Math.max(anchorCol, focusCol),
        };
    }

    function tableCellIsSelected(selection, row, col) {
        const bounds = tableSelectionBounds(selection);
        return Boolean(bounds && row >= bounds.startRow && row <= bounds.endRow && col >= bounds.startCol && col <= bounds.endCol);
    }

    function selectedTableCells(block, selection = state.tableSelection) {
        const bounds = tableSelectionBounds(selection);
        if (!bounds) return [];
        const cells = [];
        for (let row = bounds.startRow; row <= bounds.endRow; row++) {
            for (let col = bounds.startCol; col <= bounds.endCol; col++) {
                if (block.table_rows[row]?.[col]) cells.push(block.table_rows[row][col]);
            }
        }
        return cells;
    }

    function renderTableBlock(block) {
        normalizeTableBlock(block);
        const selected = state.tableSelection?.blockId === block.id ? state.tableSelection : null;
        const bounds = tableSelectionBounds(selected);
        const selectedCells = selected ? selectedTableCells(block, selected) : [];
        const selectedAlignments = new Set(selectedCells.map(cell => cell.align));
        const selectedAlign = selectedAlignments.size === 1 ? [...selectedAlignments][0] : '';
        const selectedBackgrounds = new Set(selectedCells.map(cell => normalizeTableBackground(cell.background)));
        const selectedBackground = selectedBackgrounds.size === 1 ? [...selectedBackgrounds][0] : '';
        const selectedCount = bounds ? (bounds.endRow - bounds.startRow + 1) * (bounds.endCol - bounds.startCol + 1) : 0;
        const selectedRowCount = bounds ? bounds.endRow - bounds.startRow + 1 : 0;
        const selectedColumnCount = bounds ? bounds.endCol - bounds.startCol + 1 : 0;
        const editable = canEditCurrentPage();
        return `<div class="table-block">
            ${editable ? `<div class="table-toolbar ${selected ? 'visible' : ''}" aria-label="${esc(t('editor.tableActions'))}" aria-hidden="${selected ? 'false' : 'true'}">
                <span class="table-toolbar-label">${esc(selectedCount ? t('editor.cellsSelected', { count: selectedCount }) : t('editor.selectCells'))}</span>
                ${['left', 'center', 'right'].map(align => `<button class="table-tool ${selectedAlign === align ? 'active' : ''}" data-action="table-align" data-align="${align}" data-id="${esc(block.id)}" title="${esc(t(`editor.align.${align}`))}" ${selected ? '' : 'disabled'}>${tableAlignIcon(align)}</button>`).join('')}
                <label class="table-background-control" title="${esc(t('editor.selectedCellBackground'))}">
                    <span class="table-background-swatch background-${selectedBackground || 'mixed'}" aria-hidden="true"></span>
                    <select class="table-background-select" data-action="table-background" data-id="${esc(block.id)}" aria-label="${esc(t('editor.cellBackground'))}" ${selected ? '' : 'disabled'}>
                        ${selectedBackground ? '' : `<option value="" selected disabled>${esc(t('editor.backgroundColor'))}</option>`}
                        ${tableBackgroundPalette.map(value => `<option value="${value}" ${selectedBackground === value ? 'selected' : ''}>${esc(t(`editor.tableColor.${value}`))}</option>`).join('')}
                    </select>
                </label>
                <span class="table-toolbar-separator"></span>
                <button class="table-tool text" data-action="table-add-row" data-id="${esc(block.id)}" title="${esc(t('editor.addRowHelp'))}">＋ ${esc(t('editor.row'))}</button>
                <button class="table-tool text" data-action="table-add-column" data-id="${esc(block.id)}" title="${esc(t('editor.addColumnHelp'))}" ${block.table_widths.length >= 12 ? 'disabled' : ''}>＋ ${esc(t('editor.column'))}</button>
                <button class="table-tool text danger" data-action="table-delete-row" data-id="${esc(block.id)}" title="${esc(t('editor.deleteRowHelp'))}" ${!selected || selectedRowCount >= block.table_rows.length ? 'disabled' : ''}>− ${esc(t('editor.row'))}</button>
                <button class="table-tool text danger" data-action="table-delete-column" data-id="${esc(block.id)}" title="${esc(t('editor.deleteColumnHelp'))}" ${!selected || selectedColumnCount >= block.table_widths.length ? 'disabled' : ''}>− ${esc(t('editor.column'))}</button>
                <span class="table-toolbar-separator"></span>
                <button class="table-tool text" data-action="table-copy" data-id="${esc(block.id)}" title="${esc(t('editor.copyCellsHelp'))}" ${!selected ? 'disabled' : ''}>${esc(t('editor.copy'))}</button>
                <button class="table-tool text" data-action="table-paste" data-id="${esc(block.id)}" title="${esc(t('editor.pasteCellsHelp'))}" ${!selected || !state.tableClipboard ? 'disabled' : ''}>${esc(t('editor.paste'))}</button>
                <span class="table-selection-hint">${esc(t('editor.rangeSelectionHelp'))}</span>
            </div>` : ''}
            <div class="table-scroll"><table class="editable-table" data-table-id="${esc(block.id)}"><colgroup>${block.table_widths.map(width => `<col style="width:${Number(width)}%">`).join('')}</colgroup><tbody>
                ${block.table_rows.map((row, rowIndex) => `<tr>${row.map((cell, colIndex) => `<td class="table-cell align-${cell.align} background-${normalizeTableBackground(cell.background)} ${tableCellIsSelected(selected, rowIndex, colIndex) ? 'selected' : ''} ${selected?.focusRow === rowIndex && selected?.focusCol === colIndex ? 'selection-focus' : ''}" data-row="${rowIndex}" data-col="${colIndex}" aria-selected="${tableCellIsSelected(selected, rowIndex, colIndex) ? 'true' : 'false'}"><div class="table-cell-content" contenteditable="${editable ? 'true' : 'false'}" spellcheck="true" data-block-id="${esc(block.id)}" data-row="${rowIndex}" data-col="${colIndex}">${cell.content}</div>${editable && rowIndex === 0 && colIndex < row.length - 1 ? `<span class="table-column-resizer" data-action="resize-table-column" data-id="${esc(block.id)}" data-col="${colIndex}" title="${esc(t('editor.resizeColumn'))}"></span>` : ''}</td>`).join('')}</tr>`).join('')}
            </tbody></table></div>
        </div>`;
    }

    function renderBlock(block) {
        const controls = canEditCurrentPage() ? `<div class="block-controls"><button class="block-add" data-action="add-block" data-id="${esc(block.id)}" title="${esc(t('editor.addBlock'))}">${svg('plus', 'small')}</button><button class="block-grip" data-action="block-menu" data-id="${esc(block.id)}" title="${esc(t('editor.blockMenu'))}">${svg('grip', 'small')}</button></div>` : '';
        if (block.type === 'divider') return `<div class="block-wrap" data-block-id="${esc(block.id)}">${controls}<div class="divider-block"></div></div>`;
        if (block.type === 'image') {
            const body = block.file_id && block.file_category === 'image'
                ? `<figure class="image-block"><a class="image-block-link" href="api.php?action=file-content&id=${Number(block.file_id)}" target="_blank" rel="noopener"><img src="api.php?action=file-content&id=${Number(block.file_id)}" alt="${esc(block.content?.replace(/<[^>]*>/g, '').trim() || block.file_name || t('editor.pageImage'))}" loading="lazy"></a>${canEditCurrentPage() ? `<button class="btn image-replace-button" data-action="upload-file-block" data-id="${esc(block.id)}">${svg('image', 'small')} ${esc(t('editor.replaceFile'))}</button>` : ''}<figcaption class="block-content image-caption" contenteditable="${canEditCurrentPage() ? 'true' : 'false'}" spellcheck="true" data-id="${esc(block.id)}" data-type="image" data-placeholder="${esc(t('editor.imageCaptionPlaceholder'))}">${block.content || ''}</figcaption></figure>`
                : `<button class="image-upload-placeholder ${block.uploading ? 'uploading' : ''}" data-action="upload-file-block" data-id="${esc(block.id)}" ${block.uploading ? 'disabled' : ''}><span class="image-upload-icon">${svg('image')}</span><span><strong>${esc(block.uploading ? t('editor.uploadingImage') : t('editor.uploadImage'))}</strong><small>${esc(t('editor.imageTypes', { size: state.uploadLimitMb }))}</small></span></button>`;
            return `<div class="block-wrap image-block-wrap" data-block-id="${esc(block.id)}">${controls}${body}</div>`;
        }
        if (block.type === 'file') {
            const body = block.file_id
                ? `<div class="file-block">${fileKindIcon(block.file_category)}<a class="uploaded-file-link" href="api.php?action=file-content&id=${Number(block.file_id)}" target="_blank" rel="noopener"><strong>${esc(block.file_name)}</strong><span>${esc(fileCategoryLabel(block.file_category))} · ${formatBytes(block.file_size)}</span></a>${svg('external', 'small')}${canEditCurrentPage() ? `<button class="btn subtle compact" data-action="upload-file-block" data-id="${esc(block.id)}">${esc(t('editor.replaceFile'))}</button>` : ''}</div>`
                : `<button class="file-upload-placeholder ${block.uploading ? 'uploading' : ''}" data-action="upload-file-block" data-id="${esc(block.id)}" ${block.uploading ? 'disabled' : ''}><span class="file-upload-icon">${svg('paperclip')}</span><span><strong>${esc(block.uploading ? t('editor.uploadingFile') : t('editor.uploadFile'))}</strong><small>${esc(t('editor.fileTypes', { size: state.uploadLimitMb }))}</small></span></button>`;
            return `<div class="block-wrap" data-block-id="${esc(block.id)}">${controls}${body}</div>`;
        }
        if (block.type === 'table') return `<div class="block-wrap table-block-wrap" data-block-id="${esc(block.id)}">${controls}${renderTableBlock(block)}</div>`;
        const content = block.content || '';
        const editable = `<div class="block-content" contenteditable="${canEditCurrentPage() ? 'true' : 'false'}" spellcheck="true" data-id="${esc(block.id)}" data-type="${esc(block.type)}" data-placeholder="${esc(t('editor.blockPlaceholder'))}">${content}</div>`;
        if (block.type === 'todo') return `<div class="block-wrap" data-block-id="${esc(block.id)}">${controls}<div class="todo-block ${block.checked ? 'checked' : ''}"><input class="todo-check" type="checkbox" ${canEditCurrentPage() ? 'data-action="toggle-todo"' : 'disabled'} data-id="${esc(block.id)}" ${block.checked ? 'checked' : ''}>${editable}</div></div>`;
        if (block.type === 'callout') return `<div class="block-wrap" data-block-id="${esc(block.id)}">${controls}<div class="callout-block"><span class="callout-emoji">${esc(block.emoji || '💡')}</span>${editable}</div></div>`;
        return `<div class="block-wrap" data-block-id="${esc(block.id)}">${controls}${editable}</div>`;
    }

    function unresolvedComments() { return (state.page?.comments || []).filter(comment => !comment.resolved_at && !comment.parent_id); }

    function renderPanel() {
        if (state.panel !== 'comments' || !state.page) return '';
        const parents = (state.page.comments || []).filter(comment => !comment.parent_id);
        return `<aside class="side-panel">
            <header class="panel-header"><div class="panel-title">${esc(t('common.comments'))}</div><button class="btn icon-only" data-action="close-panel" aria-label="${esc(t('common.close'))}">${svg('close')}</button></header>
            <div class="panel-tabs"><button class="panel-tab active">${esc(t('comments.unresolved', { count: unresolvedComments().length }))}</button><button class="panel-tab">${esc(t('comments.all', { count: parents.length }))}</button></div>
            <div class="comments-list">${parents.length ? parents.map(renderComment).join('') : `<div class="empty-comments"><div class="empty-icon">${svg('comment')}</div><strong>${esc(t('comments.empty'))}</strong><span>${esc(t('comments.emptyHelp'))}</span></div>`}</div>
            ${state.page.comments_enabled && state.capabilities.can_comment ? `<div class="comment-composer">${state.replyTo ? `<div class="replying-banner"><span>${esc(t('comments.replyingTo', { name: state.replyTo.user_name }))}</span><button type="button" data-action="cancel-reply" aria-label="${esc(t('comments.cancelReply'))}">${svg('close', 'tiny')}</button></div>` : ''}<div class="composer-box"><textarea id="commentInput" placeholder="${esc(t('comments.placeholder'))}"></textarea><div class="composer-footer"><div class="composer-tools"><button type="button" data-action="comment-mention" title="${esc(t('comments.mention'))}" aria-label="${esc(t('comments.mentionMember'))}">${svg('at', 'small')}</button><button type="button" data-action="comment-emoji" title="${esc(t('comments.emoji'))}" aria-label="${esc(t('comments.addEmoji'))}">${svg('smile', 'small')}</button></div><button type="button" class="btn primary send-comment" data-action="send-comment" title="${esc(t('comments.send'))}" aria-label="${esc(t('comments.sendComment'))}">${svg('send', 'small')}</button></div></div></div>` : `<div class="comment-composer"><div class="dialog-intro">${esc(t('comments.cannotPost'))}</div></div>`}
        </aside>`;
    }

    function mentionMarkup(text) {
        const names = [...new Set(state.members.filter(member => member.role !== 'system').map(member => String(member.name || '').trim()).filter(Boolean))]
            .sort((left, right) => right.length - left.length)
            .map(name => name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
        if (!names.length) return esc(text);
        const pattern = new RegExp(`(^|[^\\p{L}\\p{N}_@])@(${names.join('|')})(?![\\p{L}\\p{N}_])`, 'gu');
        let html = '';
        let offset = 0;
        let match;
        while ((match = pattern.exec(String(text))) !== null) {
            const mentionStart = match.index + match[1].length;
            const mentionEnd = pattern.lastIndex;
            html += esc(String(text).slice(offset, mentionStart));
            html += `<span class="mention">${esc(String(text).slice(mentionStart, mentionEnd))}</span>`;
            offset = mentionEnd;
        }
        return html + esc(String(text).slice(offset));
    }

    function renderComment(comment) {
        const replies = (state.page.comments || []).filter(item => Number(item.parent_id) === Number(comment.id));
        const controls = state.capabilities.can_comment
            ? `<button class="btn icon-only compact" data-action="resolve-comment" data-id="${comment.id}" title="${esc(comment.resolved_at ? t('comments.reopen') : t('comments.resolve'))}">${svg(comment.resolved_at ? 'history' : 'check', 'small')}</button>`
            : '';
        const actions = state.capabilities.can_comment
            ? `<div class="comment-actions"><button class="comment-action" data-action="reply-comment" data-id="${comment.id}">${esc(t('comments.reply'))}</button><button class="comment-action" data-action="resolve-comment" data-id="${comment.id}">${esc(comment.resolved_at ? t('comments.reopen') : t('comments.resolve'))}</button></div>`
            : '';
        return `<div class="comment-thread ${comment.resolved_at ? 'resolved' : ''}">
            <div class="comment-head">${commentAvatar(comment, 'sm')}<div class="comment-person"><div class="comment-name">${esc(comment.user_name)}</div><div class="comment-time">${timeAgo(comment.created_at)}${comment.edited_at ? ` · ${esc(t('comments.edited'))}` : ''}</div></div>${controls}</div>
            <div class="comment-body">${mentionMarkup(comment.body)}</div>
            ${actions}
            ${replies.map(reply => `<div class="reply"><div class="comment-head">${commentAvatar(reply, 'sm')}<div class="comment-person"><div class="comment-name">${esc(reply.user_name)}</div><div class="comment-time">${timeAgo(reply.created_at)}</div></div></div><div class="comment-body">${mentionMarkup(reply.body)}</div>${state.capabilities.can_comment ? `<div class="comment-actions"><button class="comment-action" data-action="reply-comment" data-id="${comment.id}">${esc(t('comments.reply'))}</button></div>` : ''}</div>`).join('')}
        </div>`;
    }

    function renderFloating() {
        let html = '';
        if (state.editorFind.open && state.view === 'page' && state.page) html += renderEditorFind();
        if (state.slash) html += renderSlashMenu();
        if (state.context) html += renderContextMenu();
        if (state.bubble) html += renderBubbleMenu();
        return html;
    }

    function renderEditorFind() {
        const canReplace = canEditCurrentPage();
        const count = state.editorFind.matches.length;
        const current = count && state.editorFind.index >= 0 ? state.editorFind.index + 1 : 0;
        return `<section class="editor-find-bar" role="search" aria-label="${esc(t('editor.findAria'))}">
            <div class="editor-find-row">
                <span class="editor-find-icon">${svg('search', 'small')}</span>
                <label class="sr-only" for="editorFindInput">${esc(t('editor.findQuery'))}</label>
                <input id="editorFindInput" class="editor-find-input" type="text" value="${esc(state.editorFind.query)}" placeholder="${esc(t('editor.findPlaceholder'))}" autocomplete="off" spellcheck="false">
                <span class="editor-find-count" id="editorFindCount" aria-live="polite">${current}/${count}</span>
                <button class="editor-find-button" type="button" data-action="editor-find-prev" title="${esc(t('editor.previousShortcut'))}" aria-label="${esc(t('editor.previous'))}" ${count ? '' : 'disabled'}>↑</button>
                <button class="editor-find-button" type="button" data-action="editor-find-next" title="${esc(t('editor.nextShortcut'))}" aria-label="${esc(t('editor.next'))}" ${count ? '' : 'disabled'}>↓</button>
                <button class="editor-find-button close" type="button" data-action="editor-find-close" title="${esc(t('editor.closeShortcut'))}" aria-label="${esc(t('editor.closeFind'))}">${svg('close', 'small')}</button>
            </div>
            ${canReplace ? `<div class="editor-find-row replace">
                <span class="editor-find-replace-symbol" aria-hidden="true">↳</span>
                <label class="sr-only" for="editorReplaceInput">${esc(t('editor.replacement'))}</label>
                <input id="editorReplaceInput" class="editor-find-input" type="text" value="${esc(state.editorFind.replacement)}" placeholder="${esc(t('editor.replacement'))}" autocomplete="off" spellcheck="false">
                <button class="editor-find-text-button" type="button" data-action="editor-find-replace" ${count ? '' : 'disabled'}>${esc(t('editor.replace'))}</button>
                <button class="editor-find-text-button" type="button" data-action="editor-find-replace-all" ${count ? '' : 'disabled'}>${esc(t('editor.replaceAll'))}</button>
            </div>` : ''}
        </section>`;
    }

    function clearEditorFindHighlights() {
        if (globalThis.CSS?.highlights) {
            CSS.highlights.delete('editor-find-match');
            CSS.highlights.delete('editor-find-current');
        }
        root.querySelectorAll('.editor-find-current').forEach(element => element.classList.remove('editor-find-current'));
    }

    function editorFindTargets() {
        return [...root.querySelectorAll('#editor .block-content[data-id], #editor .table-cell-content[data-block-id]')];
    }

    function editorTextRanges(target, query) {
        const walker = document.createTreeWalker(target, NodeFilter.SHOW_TEXT);
        const segments = [];
        let text = '';
        let node = walker.nextNode();
        while (node) {
            const value = String(node.nodeValue || '');
            if (value) {
                segments.push({ node, start: text.length, end: text.length + value.length });
                text += value;
            }
            node = walker.nextNode();
        }
        if (!text || !query) return [];
        const haystack = text.toLocaleLowerCase('ja-JP');
        const needle = query.toLocaleLowerCase('ja-JP');
        const ranges = [];
        let offset = 0;
        while (needle && offset <= haystack.length - needle.length) {
            const start = haystack.indexOf(needle, offset);
            if (start < 0) break;
            const end = start + needle.length;
            const startSegment = segments.find(segment => start >= segment.start && start < segment.end);
            const endSegment = segments.find(segment => end > segment.start && end <= segment.end);
            if (startSegment && endSegment) {
                const range = document.createRange();
                range.setStart(startSegment.node, start - startSegment.start);
                range.setEnd(endSegment.node, end - endSegment.start);
                ranges.push({ range, target });
            }
            offset = end || start + 1;
        }
        return ranges;
    }

    function updateEditorFindChrome() {
        const count = state.editorFind.matches.length;
        const current = count && state.editorFind.index >= 0 ? state.editorFind.index + 1 : 0;
        const counter = document.getElementById('editorFindCount');
        if (counter) counter.textContent = `${current}/${count}`;
        root.querySelectorAll('[data-action="editor-find-prev"],[data-action="editor-find-next"],[data-action="editor-find-replace"],[data-action="editor-find-replace-all"]').forEach(button => {
            button.disabled = count === 0;
        });
    }

    function applyEditorFindHighlights(scrollCurrent = false) {
        clearEditorFindHighlights();
        const matches = state.editorFind.matches;
        const current = matches[state.editorFind.index] || null;
        if (globalThis.CSS?.highlights && typeof globalThis.Highlight === 'function' && matches.length) {
            CSS.highlights.set('editor-find-match', new Highlight(...matches.map(match => match.range)));
            if (current) CSS.highlights.set('editor-find-current', new Highlight(current.range));
        }
        if (current) {
            current.target.classList.add('editor-find-current');
            if (scrollCurrent) current.target.scrollIntoView({ block: 'center', behavior: 'smooth' });
        }
        updateEditorFindChrome();
    }

    function refreshEditorFindMatches(resetIndex = true, scrollCurrent = false) {
        const query = state.editorFind.query;
        state.editorFind.matches = query
            ? editorFindTargets().flatMap(target => editorTextRanges(target, query))
            : [];
        if (!state.editorFind.matches.length) state.editorFind.index = -1;
        else if (resetIndex || state.editorFind.index < 0) state.editorFind.index = 0;
        else state.editorFind.index = Math.min(state.editorFind.index, state.editorFind.matches.length - 1);
        applyEditorFindHighlights(scrollCurrent);
    }

    function openEditorFind() {
        if (state.view !== 'page' || !state.page) return;
        if (state.editorFind.open) {
            const input = document.getElementById('editorFindInput');
            input?.focus();
            input?.select();
            return;
        }
        syncEditorFromDom();
        const selection = getSelection();
        const selectedText = selection && !selection.isCollapsed && document.getElementById('editor')?.contains(selection.anchorNode)
            ? String(selection.toString() || '').trim()
            : '';
        state.editorFind.open = true;
        if (selectedText && selectedText.length <= 120 && !/[\r\n]/.test(selectedText)) state.editorFind.query = selectedText;
        state.editorFind.matches = [];
        state.editorFind.index = -1;
        renderApp();
        setTimeout(() => {
            refreshEditorFindMatches(true, Boolean(state.editorFind.query));
            const input = document.getElementById('editorFindInput');
            input?.focus();
            input?.select();
        }, 20);
    }

    function closeEditorFind(clearValues = false) {
        clearEditorFindHighlights();
        state.editorFind.open = false;
        state.editorFind.matches = [];
        state.editorFind.index = -1;
        if (clearValues) {
            state.editorFind.query = '';
            state.editorFind.replacement = '';
        }
        root.querySelector('.editor-find-bar')?.remove();
        const trigger = root.querySelector('[data-action="editor-find-open"]');
        trigger?.classList.remove('active');
        trigger?.setAttribute('aria-expanded', 'false');
    }

    function moveEditorFind(direction) {
        const count = state.editorFind.matches.length;
        if (!count) return;
        state.editorFind.index = (state.editorFind.index + direction + count) % count;
        applyEditorFindHighlights(true);
    }

    function replaceEditorFindRange(match, replacement) {
        match.range.deleteContents();
        if (replacement) match.range.insertNode(document.createTextNode(replacement));
    }

    function replaceCurrentEditorFind() {
        if (!canEditCurrentPage()) return;
        const match = state.editorFind.matches[state.editorFind.index];
        if (!match) return;
        clearEditorFindHighlights();
        replaceEditorFindRange(match, state.editorFind.replacement);
        match.target.normalize();
        syncEditorFromDom();
        scheduleSave();
        refreshEditorFindMatches(true, true);
    }

    function replaceAllEditorFind() {
        if (!canEditCurrentPage() || !state.editorFind.matches.length) return;
        const matches = [...state.editorFind.matches];
        const targets = new Set(matches.map(match => match.target));
        clearEditorFindHighlights();
        [...matches].reverse().forEach(match => replaceEditorFindRange(match, state.editorFind.replacement));
        targets.forEach(target => target.normalize());
        syncEditorFromDom();
        scheduleSave(true);
        refreshEditorFindMatches(true, false);
        toast(t('editor.replacedCount', { count: matches.length }));
    }

    function renderAiLauncher(position = 'floating') {
        const open = state.dialog === 'ai-search';
        return `<button class="ai-launcher ${position === 'cover' ? 'in-cover' : ''} ${open ? 'active' : ''}" data-action="search" type="button" aria-label="${esc(t('ai.openSearch'))}" aria-expanded="${open ? 'true' : 'false'}">
            ${brandLogo('ai-launcher-logo', 'OpenConcept AI')}
            <span class="ai-launcher-label">${esc(t('ai.ask'))}</span>
        </button>`;
    }

    function openAiSearch() {
        const editorContext = captureAiEditorContext();
        if (editorContext.target_type !== 'none' || !state.aiEditorContext || Number(state.aiEditorContext.page_id) !== Number(state.page?.id || 0)) {
            state.aiEditorContext = editorContext;
        }
        clearTimeout(aiPanelCloseTimer);
        aiPanelCloseTimer = null;
        aiPanelClosing = false;
        state.mobileSidebar = false;
        state.aiSearchError = '';
        state.aiSearchErrorDetails = null;
        state.dialog = 'ai-search';
        renderApp();
        if (!state.aiConversationsLoaded && !state.aiConversationsLoading) loadAiConversations();
    }

    function captureAiEditorContext(target = document.activeElement) {
        const pageId = state.view === 'page' && state.page?.id ? Number(state.page.id) : 0;
        const empty = { page_id: pageId, target_type: 'none', block_id: '', block_type: '', selected_text: '', text: '', row: 0, column: 0 };
        if (!pageId || !(target instanceof Element)) return empty;
        const title = target.closest?.('#pageTitle');
        const block = target.closest?.('.block-content[data-id]');
        const cell = target.closest?.('.table-cell-content[data-block-id]');
        const editorTarget = title || cell || block;
        if (!editorTarget) return empty;
        const selection = getSelection();
        const selectionInside = selection?.rangeCount
            && editorTarget.contains(selection.anchorNode)
            && editorTarget.contains(selection.focusNode);
        const selectedText = selectionInside && !selection.isCollapsed ? String(selection.toString() || '').slice(0, 2000) : '';
        if (title) return { ...empty, target_type: 'title', selected_text: selectedText, text: String(title.textContent || '').slice(0, 6000) };
        if (cell) return {
            ...empty,
            target_type: 'table_cell',
            block_id: String(cell.dataset.blockId || ''),
            block_type: 'table',
            selected_text: selectedText,
            text: String(cell.textContent || '').slice(0, 6000),
            row: Number(cell.dataset.row || 0),
            column: Number(cell.dataset.col || 0),
        };
        return {
            ...empty,
            target_type: 'block',
            block_id: String(block.dataset.id || ''),
            block_type: String(block.dataset.type || ''),
            selected_text: selectedText,
            text: String(block.textContent || '').slice(0, 6000),
        };
    }

    function rememberAiEditorContext(target = document.activeElement) {
        const context = captureAiEditorContext(target);
        if (context.target_type !== 'none') state.aiEditorContext = context;
    }

    function closeAiSearch() {
        if (state.dialog !== 'ai-search') return false;
        if (aiPanelClosing) return true;
        aiPanelClosing = true;
        document.body.classList.remove('ai-search-open');
        root.querySelector('.ai-search-backdrop')?.setAttribute('aria-hidden', 'true');
        aiPanelCloseTimer = setTimeout(() => {
            aiPanelCloseTimer = null;
            aiPanelClosing = false;
            if (state.dialog === 'ai-search') {
                state.dialog = null;
                state.dialogData = null;
                renderApp();
            }
        }, 260);
        return true;
    }

    const blockTypes = [
        ['paragraph', 'T', 'editor.block.paragraph', 'editor.block.paragraphHelp', 'text paragraph p 段落 本文', true],
        ['heading1', 'H1', 'editor.block.heading1', 'editor.block.heading1Help', 'h1 heading1 title 見出し1 大見出し', true],
        ['heading2', 'H2', 'editor.block.heading2', 'editor.block.heading2Help', 'h2 heading2 見出し2 中見出し', true],
        ['heading3', 'H3', 'editor.block.heading3', 'editor.block.heading3Help', 'h3 heading3 見出し3 小見出し', true],
        ['bullet', '•', 'editor.block.bullet', 'editor.block.bulletHelp', 'bullet ul list リスト 箇条書き', true],
        ['number', '1.', 'editor.block.number', 'editor.block.numberHelp', 'number ol ordered list 番号 リスト', true],
        ['todo', '☑', 'editor.block.todo', 'editor.block.todoHelp', 'todo task checkbox check タスク チェック', true],
        ['quote', '❝', 'editor.block.quote', 'editor.block.quoteHelp', 'quote blockquote 引用', true],
        ['callout', '💡', 'editor.block.callout', 'editor.block.calloutHelp', 'callout info note ヒント 注意 情報', true],
        ['code', '</>', 'editor.block.code', 'editor.block.codeHelp', 'code source コード ソース', true],
        ['divider', '―', 'editor.block.divider', 'editor.block.dividerHelp', 'divider hr line 区切り線 水平線', true],
        ['table', '▦', 'editor.block.table', 'editor.block.tableHelp', 'table grid 表 テーブル 行 列', true],
        ['image', '🖼', 'editor.block.image', 'editor.block.imageHelp', 'image picture photo upload 画像 写真 アップロード', true],
        ['file', '📎', 'editor.block.file', 'editor.block.fileHelp', 'file upload attachment ファイル アップロード 添付 資料 pdf excel word md txt テキスト 画像', true],
    ];

    const coreBlockTypes = new Set(blockTypes.map(([type]) => type));

    window.OpenConceptEditor = Object.freeze({
        registerSlashCommand(command) {
            if (!command || !coreBlockTypes.has(command.type) || !command.label) {
                throw new Error(t('editor.invalidSlashCommand'));
            }
            const entry = [
                command.type,
                String(command.symbol || '•'),
                String(command.label),
                String(command.description || ''),
                [command.type, command.label, ...(command.keywords || [])].join(' '),
                false,
            ];
            blockTypes.push(entry);
            return () => {
                const index = blockTypes.indexOf(entry);
                if (index >= 0) blockTypes.splice(index, 1);
            };
        },
    });
    window.dispatchEvent(new CustomEvent('openconcept:editor-ready', { detail: window.OpenConceptEditor }));

    function matchingSlashCommands() {
        const query = String(state.slash?.query || '').trim().toLocaleLowerCase('ja');
        const localizedCommands = blockTypes.map(([type, symbol, label, desc, keywords, localized]) => [type, symbol, localized ? t(label) : label, localized ? t(desc) : desc, keywords]);
        if (!query) return localizedCommands;
        return localizedCommands.filter(([type, , label, desc, keywords]) =>
            [type, label, desc, keywords].join(' ').toLocaleLowerCase('ja').includes(query)
        );
    }

    function renderSlashMenu() {
        const { x, y, selectedIndex = 0, query = '' } = state.slash;
        const commands = matchingSlashCommands();
        const items = commands.length
            ? commands.map(([type, symbol, label, desc], index) => `<button class="slash-item ${index === selectedIndex ? 'selected' : ''}" data-action="choose-block" data-type="${type}"><span class="slash-icon">${esc(symbol)}</span><span><div class="slash-label">${esc(label)}</div><div class="slash-desc">${esc(desc)}</div></span></button>`).join('')
            : `<div class="slash-empty"><strong>${esc(t('editor.slashNoCommands'))}</strong><span>${esc(t('editor.slashTryAnother', { query }))}</span></div>`;
        return `<div class="slash-menu" style="left:${Math.max(8, Math.min(x, innerWidth - 305))}px;top:${Math.max(8, Math.min(y, innerHeight - 410))}px"><div class="slash-title">${esc(query ? t('editor.slashResults', { query }) : t('editor.basicBlocks'))}</div>${items}<div class="slash-hint"><span>↑↓ ${esc(t('editor.select'))}</span><span>Enter ${esc(t('editor.confirm'))}</span><span>Esc ${esc(t('common.close'))}</span></div></div>`;
    }

    function renderContextMenu() {
        const { x, y, id, pageId, kind } = state.context;
        if (kind === 'workspace') {
            return `<div class="context-menu workspace-context-menu" role="menu" aria-label="${esc(t('about.menu'))}" style="left:${Math.max(8, Math.min(x, innerWidth - 225))}px;top:${Math.max(8, Math.min(y, innerHeight - 70))}px"><button type="button" class="menu-item" data-action="about-openconcept" role="menuitem">${svg('info', 'small')}<span>${esc(t('about.title'))}</span></button></div>`;
        }
        if (pageId) {
            const canCreate = Boolean(state.capabilities.can_create_page);
            const canEdit = canEditPageSummary(pageId);
            return `<div class="context-menu" style="left:${Math.min(x, innerWidth - 225)}px;top:${Math.min(y, innerHeight - 180)}px">${canCreate ? `<button class="menu-item" data-action="new-page" data-parent="${pageId}">${svg('plus', 'small')}<span>${esc(t('page.addChild'))}</span></button>` : ''}<button class="menu-item" data-action="favorite-by-id" data-id="${pageId}">${svg('star', 'small')}<span>${esc(t('common.favorites'))}</span></button>${canEdit ? `<div class="menu-sep"></div><button class="menu-item danger" data-action="archive-by-id" data-id="${pageId}">${svg('archive', 'small')}<span>${esc(t('page.moveToTrash'))}</span></button>` : ''}</div>`;
        }
        const canDeleteSelectedText = Boolean(state.context.textSelection);
        return `<div class="context-menu" style="left:${Math.min(x, innerWidth - 225)}px;top:${Math.min(y, innerHeight - 245)}px"><button class="menu-item" data-action="turn-block" data-id="${id}">${svg('edit', 'small')}<span>${esc(t('editor.changeBlockType'))}</span></button><button class="menu-item" data-action="duplicate-block" data-id="${id}">${svg('copy', 'small')}<span>${esc(t('editor.duplicate'))}</span></button><div class="menu-sep"></div><button class="menu-item danger" data-action="delete-selected-text" ${canDeleteSelectedText ? '' : 'disabled'}>${svg('trash', 'small')}<span>${esc(t('editor.deleteSelectedText'))}</span></button><button class="menu-item danger" data-action="delete-block" data-id="${id}">${svg('trash', 'small')}<span>${esc(t('editor.deleteBlock'))}</span></button></div>`;
    }

    function renderBubbleMenu() {
        if (!canEditCurrentPage() || !state.bubble) return '';
        const { x, y } = state.bubble;
        return `<div class="bubble-menu" style="left:${Math.max(8, Math.min(x, innerWidth - 285))}px;top:${Math.max(8, y)}px"><button data-action="format" data-command="bold"><b>B</b></button><button data-action="format" data-command="italic"><i>I</i></button><button data-action="format" data-command="underline"><u>U</u></button><button data-action="format" data-command="strikeThrough"><s>S</s></button><div class="bubble-divider"></div><button data-action="format-code">&lt;/&gt;</button><button data-action="format-link">${svg('external', 'small')}</button><button data-action="clear-format">${svg('close', 'small')}</button></div>`;
    }

    function notificationIcon(type) {
        if (type === 'mention') return 'at';
        if (type === 'comment' || type === 'reply') return 'comment';
        return 'edit';
    }

    function renderInboxItems() {
        const notifications = state.inboxFilter === 'unread'
            ? state.notifications.filter(item => !Number(item.is_read))
            : state.notifications;
        if (!notifications.length) {
            return `<div class="history-empty">${esc(t(state.inboxFilter === 'unread' ? 'inbox.noUnread' : 'inbox.noNotifications'))}</div>`;
        }
        return notifications.map(item => {
            const isRead = Boolean(Number(item.is_read));
            return `<article class="notification-item ${isRead ? '' : 'unread'}">
                <button type="button" class="notification-main" data-action="notification" data-id="${item.id}" data-page-id="${item.page_id || ''}" data-type="${esc(item.type)}">
                    <span class="notification-icon">${svg(notificationIcon(item.type), 'small')}</span>
                    <span class="notification-body">${esc(item.message)}<span class="notification-time">${timeAgo(item.created_at)}</span></span>
                    ${isRead ? '' : `<span class="unread-dot" aria-label="${esc(t('inbox.unread'))}"></span>`}
                </button>
                <button type="button" class="notification-read-toggle" data-action="toggle-notification-read" data-id="${item.id}" data-read="${isRead ? '0' : '1'}" title="${esc(t(isRead ? 'inbox.markUnread' : 'inbox.markRead'))}" aria-label="${esc(t(isRead ? 'inbox.markUnread' : 'inbox.markRead'))}">${isRead ? svg('bell', 'small') : svg('check', 'small')}</button>
            </article>`;
        }).join('');
    }

    function renderInboxBody() {
        const unread = unreadCount();
        return `<div class="inbox-tabs" role="tablist" aria-label="${esc(t('inbox.filterAria'))}"><button type="button" class="inbox-tab ${state.inboxFilter === 'all' ? 'active' : ''}" data-action="inbox-filter" data-filter="all" role="tab" aria-selected="${state.inboxFilter === 'all'}">${esc(t('inbox.all'))} <span>${state.notifications.length}</span></button><button type="button" class="inbox-tab ${state.inboxFilter === 'unread' ? 'active' : ''}" data-action="inbox-filter" data-filter="unread" role="tab" aria-selected="${state.inboxFilter === 'unread'}">${esc(t('inbox.unread'))} <span>${unread}</span></button></div><div class="inbox-list-container">${renderInboxItems()}</div>`;
    }

    const profileCategoryNames = () => translatedMap('profile.category', ['animals', 'fruits', 'vegetables', 'vehicles']);

    function cleanupProfileDraft(clearFile = false) {
        const draft = state.dialogData;
        if (draft?.photo_preview) URL.revokeObjectURL(draft.photo_preview);
        if (draft) {
            draft.photo_preview = '';
            if (clearFile) draft.photo_file = null;
        }
    }

    function openProfileDialog() {
        cleanupProfileDraft(true);
        state.dialog = 'profile';
        state.dialogData = {
            department: state.user.department || '',
            avatar_kind: state.user.avatar_kind || 'initials',
            avatar_value: state.user.avatar_value || '',
            photo_file: null,
            photo_preview: '',
        };
        renderApp();
    }

    function closeProfileDialog() {
        cleanupProfileDraft(true);
        state.dialog = null;
        state.dialogData = null;
    }

    function chooseProfileAvatar(kind, value = '') {
        if (!state.dialogData || !['initials', 'emoji'].includes(kind)) return;
        cleanupProfileDraft(true);
        state.dialogData.avatar_kind = kind;
        state.dialogData.avatar_value = kind === 'emoji' ? value : '';
        renderApp();
    }

    function selectProfilePhoto() {
        document.getElementById('profilePhotoInput')?.click();
    }

    function useProfilePhoto(file) {
        if (!file) return;
        if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type)) {
            toast(t('profile.photoTypeError'), 'error');
            return;
        }
        if (file.size < 1 || file.size > state.profilePhotoLimitMb * 1024 * 1024) {
            toast(t('profile.photoSizeError', { size: state.profilePhotoLimitMb }), 'error');
            return;
        }
        cleanupProfileDraft(true);
        state.dialogData.photo_file = file;
        state.dialogData.photo_preview = URL.createObjectURL(file);
        state.dialogData.avatar_kind = 'photo';
        state.dialogData.avatar_value = '';
        renderApp();
    }

    async function saveProfile() {
        const draft = state.dialogData;
        const error = document.getElementById('profileError');
        const button = root.querySelector('[data-action="save-profile"]');
        if (!draft || !button) return;
        if (error) error.textContent = '';
        button.disabled = true;
        button.textContent = `${t('common.saving')}…`;
        try {
            const formData = new FormData();
            formData.append('avatar_kind', draft.avatar_kind);
            formData.append('avatar_value', draft.avatar_value || '');
            if (draft.photo_file) formData.append('photo', draft.photo_file, draft.photo_file.name);
            const response = await fetch('api.php?action=update-profile', {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-CSRF-Token': state.csrf },
                body: formData,
            });
            const data = await response.json().catch(() => ({ error: t('common.invalidServerResponse') }));
            if (!response.ok) throw new Error(data.error || t('profile.saveFailed'));
            const currentPageId = state.page?.id || 0;
            closeProfileDialog();
            state.user = data.user;
            await refreshSummary();
            if (currentPageId && !pageById(currentPageId)) {
                state.page = null;
                state.view = 'home';
                history.replaceState(null, '', '#home');
            }
            renderApp();
            toast(t('profile.updated'));
        } catch (err) {
            if (error) error.textContent = err.message;
            button.disabled = false;
            button.textContent = t('common.save');
        }
    }

    function aiProviderFormValue(id, fallback = '') {
        const element = document.getElementById(id);
        return element ? element.value : fallback;
    }

    function readAiProviderForm() {
        const current = state.aiProviderDraft || state.aiProviderSettings?.settings || {};
        return {
            provider_id: aiProviderFormValue('aiProviderType', current.provider_id || 'openai'),
            display_name: aiProviderFormValue('aiProviderName', current.display_name || 'OpenAI'),
            base_url: aiProviderFormValue('aiProviderBaseUrl', current.base_url || 'https://api.openai.com/v1'),
            endpoint_mode: aiProviderFormValue('aiProviderEndpointMode', current.endpoint_mode || 'responses'),
            structured_output_mode: aiProviderFormValue('aiProviderStructuredMode', current.structured_output_mode || 'json_schema'),
            auth_mode: aiProviderFormValue('aiProviderAuthMode', current.auth_mode || 'bearer'),
            api_key: aiProviderFormValue('aiProviderApiKey', ''),
            clear_api_key: Boolean(document.getElementById('aiProviderClearKey')?.checked),
            text_model: aiProviderFormValue('aiProviderTextModel', current.text_model || ''),
            transcription_model: aiProviderFormValue('aiProviderTranscriptionModel', current.transcription_model || ''),
            // Translation has a dedicated provider profile. Preserve this
            // legacy slot only so the shared connection record remains
            // backward-compatible with older installations.
            translation_model: current.translation_model || aiProviderFormValue('aiProviderTextModel', current.text_model || ''),
            vision_model: aiProviderFormValue('aiProviderVisionModel', current.vision_model || ''),
            timeout: Number(aiProviderFormValue('aiProviderTimeout', current.timeout || 120)),
            verify_tls: Boolean(document.getElementById('aiProviderVerifyTls')?.checked),
            allow_private_network: Boolean(document.getElementById('aiProviderAllowPrivate')?.checked),
            allow_http: Boolean(document.getElementById('aiProviderAllowHttp')?.checked),
        };
    }

    function renderAiProviderSettings() {
        if (!state.capabilities.can_manage_system_settings) return '';
        if (state.aiProviderLoading && !state.aiProviderSettings) {
            return `<section class="ai-provider-settings" id="aiProviderSettingsCard"><div class="plugin-status">${esc(t('settings.aiProvider.loading'))}</div></section>`;
        }
        if (!state.aiProviderSettings) {
            return `<section class="ai-provider-settings" id="aiProviderSettingsCard"><div class="plugin-status error">${esc(state.aiProviderError || t('settings.aiProvider.loadFailed'))}</div></section>`;
        }
        const savedSettings = state.aiProviderSettings.settings || {};
        const settings = state.aiProviderDraft || savedSettings;
        const secret = state.aiProviderSettings.secret || {};
        const isOpenAi = settings.provider_id === 'openai';
        const noAuth = settings.auth_mode === 'none';
        const busy = state.aiProviderSaving || state.aiProviderTesting;
        const sourceLabel = t(`settings.aiProvider.secretSource.${secret.source || 'none'}`);
        const credentialReady = noAuth || secret.available;
        const secretStatus = noAuth
            ? t('settings.aiProvider.authNotRequired')
            : secret.available
            ? t('settings.aiProvider.secretAvailable', { source: sourceLabel, hint: secret.hint || '' })
            : t('settings.aiProvider.secretMissing');
        const customDisabled = isOpenAi ? 'disabled' : '';
        const providerLabel = settings.provider_id === 'openai' ? 'OpenAI' : (settings.display_name || t('settings.aiProvider.compatible'));
        const authenticationLabel = settings.auth_mode === 'none'
            ? t('settings.aiProvider.authNone')
            : String(settings.auth_mode || '').toUpperCase();
        return `<section class="ai-provider-settings" id="aiProviderSettingsCard">
            <div class="ai-provider-heading"><div><strong>${esc(t('settings.aiProvider.title'))}</strong><span>${esc(t('settings.aiProvider.help'))}</span></div><span class="ai-provider-status ${credentialReady ? 'ready' : 'missing'}">${esc(secretStatus)}</span></div>
            <div class="settings-current-summary" aria-label="${esc(t('settings.current.title'))}">
                <div class="settings-current-heading"><strong>${esc(t('settings.current.title'))}</strong><span>${esc(state.aiProviderDraft ? t('settings.current.unsaved') : t('settings.current.saved'))}</span></div>
                <dl class="settings-summary-grid">
                    <div><dt>${esc(t('settings.current.provider'))}</dt><dd>${esc(providerLabel)}</dd></div>
                    <div><dt>${esc(t('settings.current.endpoint'))}</dt><dd title="${esc(settings.base_url || '')}">${esc(settings.base_url || t('common.unspecified'))}</dd></div>
                    <div><dt>${esc(t('settings.current.model'))}</dt><dd>${esc(settings.text_model || t('common.unspecified'))}</dd></div>
                    <div><dt>${esc(t('settings.current.authentication'))}</dt><dd>${esc(authenticationLabel)}</dd></div>
                </dl>
            </div>
            <div class="settings-explanation" role="note">${svg('info', 'small')}<div><strong>${esc(t('settings.aiProvider.connectionTitle'))}</strong><span>${esc(t('settings.aiProvider.connectionHelp'))}</span></div></div>
            ${state.aiProviderNotice ? `<div class="settings-persistent-notice" role="status">${svg('check', 'small')}<span>${esc(state.aiProviderNotice)}</span></div>` : ''}
            <details class="ai-provider-details" id="aiProviderDetails"${state.aiProviderExpanded ? ' open' : ''}>
                <summary class="ai-provider-details-summary"><span class="ai-provider-details-copy"><strong>${esc(t('settings.aiProvider.detailsTitle'))}</strong><small>${esc(t('settings.aiProvider.detailsHelp'))}</small></span><span class="ai-provider-details-chevron" aria-hidden="true">${svg('chevron', 'small')}</span></summary>
                <form id="aiProviderForm">
                <div class="ai-provider-grid">
                    <div class="form-field"><label for="aiProviderType">${esc(t('settings.aiProvider.provider'))}</label><select class="input" id="aiProviderType"><option value="openai" ${isOpenAi ? 'selected' : ''}>OpenAI</option><option value="openai-compatible" ${!isOpenAi ? 'selected' : ''}>${esc(t('settings.aiProvider.compatible'))}</option></select></div>
                    <div class="form-field"><label for="aiProviderName">${esc(t('settings.aiProvider.name'))}</label><input class="input" id="aiProviderName" maxlength="80" value="${esc(settings.display_name || '')}" ${customDisabled} required></div>
                    <div class="form-field ai-provider-wide"><label for="aiProviderBaseUrl">${esc(t('settings.aiProvider.baseUrl'))}</label><input class="input" id="aiProviderBaseUrl" type="url" maxlength="2048" value="${esc(settings.base_url || '')}" ${customDisabled} required><small>${esc(t('settings.aiProvider.baseUrlHelp'))}</small></div>
                    <div class="form-field"><label for="aiProviderEndpointMode">${esc(t('settings.aiProvider.endpointMode'))}</label><select class="input" id="aiProviderEndpointMode" ${customDisabled}><option value="responses" ${settings.endpoint_mode === 'responses' ? 'selected' : ''}>Responses API</option><option value="chat_completions" ${settings.endpoint_mode === 'chat_completions' ? 'selected' : ''}>Chat Completions</option></select></div>
                    <div class="form-field"><label for="aiProviderStructuredMode">${esc(t('settings.aiProvider.structuredMode'))}</label><select class="input" id="aiProviderStructuredMode" ${customDisabled}><option value="json_schema" ${settings.structured_output_mode === 'json_schema' ? 'selected' : ''}>JSON Schema</option><option value="json_object" ${settings.structured_output_mode === 'json_object' ? 'selected' : ''}>JSON Object</option><option value="prompt" ${settings.structured_output_mode === 'prompt' ? 'selected' : ''}>Prompt only</option></select></div>
                    <div class="form-field"><label for="aiProviderAuthMode">${esc(t('settings.aiProvider.authMode'))}</label><select class="input" id="aiProviderAuthMode" ${customDisabled}><option value="bearer" ${settings.auth_mode === 'bearer' ? 'selected' : ''}>Bearer</option><option value="x-api-key" ${settings.auth_mode === 'x-api-key' ? 'selected' : ''}>X-API-Key</option><option value="none" ${settings.auth_mode === 'none' ? 'selected' : ''}>${esc(t('settings.aiProvider.authNone'))}</option></select></div>
                    <div class="form-field"><label for="aiProviderApiKey">${esc(t('settings.aiProvider.apiKey'))}</label><input class="input" id="aiProviderApiKey" type="password" maxlength="4096" autocomplete="new-password" placeholder="${esc(t('settings.aiProvider.apiKeyPlaceholder'))}" ${noAuth ? 'disabled' : ''}><small>${esc(t('settings.aiProvider.apiKeyHelp'))}</small></div>
                    <div class="form-field"><label for="aiProviderTextModel">${esc(t('settings.aiProvider.textModel'))}</label><input class="input" id="aiProviderTextModel" maxlength="190" value="${esc(settings.text_model || '')}" required></div>
                    <div class="form-field"><label for="aiProviderTranscriptionModel">${esc(t('settings.aiProvider.transcriptionModel'))}</label><input class="input" id="aiProviderTranscriptionModel" maxlength="190" value="${esc(settings.transcription_model || '')}" required></div>
                    <div class="form-field"><label for="aiProviderVisionModel">${esc(t('settings.aiProvider.visionModel'))}</label><input class="input" id="aiProviderVisionModel" maxlength="190" value="${esc(settings.vision_model || '')}" required></div>
                    <div class="form-field"><label for="aiProviderTimeout">${esc(t('settings.aiProvider.timeout'))}</label><input class="input" id="aiProviderTimeout" type="number" min="15" max="300" value="${esc(settings.timeout || 120)}" required></div>
                </div>
                <div class="ai-provider-checks">
                    <label><input type="checkbox" id="aiProviderVerifyTls" ${settings.verify_tls ? 'checked' : ''} ${customDisabled}> ${esc(t('settings.aiProvider.verifyTls'))}</label>
                    <label><input type="checkbox" id="aiProviderAllowPrivate" ${settings.allow_private_network ? 'checked' : ''} ${customDisabled}> ${esc(t('settings.aiProvider.allowPrivate'))}</label>
                    <label><input type="checkbox" id="aiProviderAllowHttp" ${settings.allow_http ? 'checked' : ''} ${customDisabled}> ${esc(t('settings.aiProvider.allowHttp'))}</label>
                    <label><input type="checkbox" id="aiProviderClearKey" ${noAuth ? 'disabled' : ''}> ${esc(t('settings.aiProvider.clearKey'))}</label>
                </div>
                <div class="ai-provider-actions"><span class="login-error">${esc(state.aiProviderError || '')}</span><button type="button" class="btn secondary compact" data-action="test-ai-provider" ${busy ? 'disabled' : ''}>${esc(state.aiProviderTesting ? t('settings.aiProvider.testing') : t('settings.aiProvider.test'))}</button><button type="submit" class="btn primary compact" ${busy ? 'disabled' : ''}>${esc(state.aiProviderSaving ? t('settings.aiProvider.saving') : t('common.save'))}</button></div>
                </form>
            </details>
        </section>`;
    }

    async function loadAiProviderSettings(renderView = true) {
        if (!state.capabilities.can_manage_system_settings || state.aiProviderLoading) return;
        state.aiProviderLoading = true;
        state.aiProviderError = '';
        if (renderView && state.view === 'settings') renderApp();
        try {
            state.aiProviderSettings = await api('ai-provider-settings');
            state.aiProviderDraft = null;
        } catch (err) {
            state.aiProviderError = err.message;
        } finally {
            state.aiProviderLoading = false;
            if (renderView && state.view === 'settings') renderApp();
        }
    }

    function openOwnPasswordChangeDialog() {
        cleanupProfileDraft(true);
        state.dialog = 'password-change';
        state.dialogData = null;
        renderApp();
        requestAnimationFrame(() => document.getElementById('currentPassword')?.focus());
    }

    async function submitOwnPasswordChange() {
        const form = document.getElementById('ownPasswordChangeForm');
        const button = root.querySelector('[data-action="submit-password-change"]');
        const error = document.getElementById('ownPasswordChangeError');
        if (!form || !button) return;
        if (error) error.textContent = '';
        if (!form.reportValidity()) return;
        button.disabled = true;
        button.textContent = t('profile.password.updating');
        try {
            const data = await api('change-password', { method: 'POST', body: {
                current_password: form.elements.current_password.value,
                password: form.elements.password.value,
                password_confirmation: form.elements.password_confirmation.value,
            }});
            state.csrf = data.csrf;
            state.user.must_change_password = false;
            state.dialog = null;
            state.dialogData = null;
            renderApp();
            toast(t('profile.password.updated'));
        } catch (err) {
            if (error) error.textContent = err.message;
            button.disabled = false;
            button.textContent = t('profile.password.submit');
        }
    }

    async function saveAiProviderSettings() {
        if (state.aiProviderSaving) return;
        const body = readAiProviderForm();
        state.aiProviderDraft = body;
        state.aiProviderNotice = '';
        state.aiProviderSaving = true;
        state.aiProviderError = '';
        renderApp();
        try {
            const data = await api('ai-provider-settings', { method: 'POST', body });
            state.aiProviderSettings = { settings: data.settings, secret: data.secret };
            state.aiProviderDraft = null;
            state.aiProviderNotice = t('settings.aiProvider.saved');
            toast(t('settings.aiProvider.saved'));
        } catch (err) {
            state.aiProviderError = err.message;
        } finally {
            state.aiProviderSaving = false;
            renderApp();
        }
    }

    async function testAiProvider() {
        if (state.aiProviderTesting) return;
        state.aiProviderDraft = readAiProviderForm();
        state.aiProviderTesting = true;
        state.aiProviderError = '';
        renderApp();
        try {
            const data = await api('ai-provider-test', { method: 'POST', body: {} });
            state.aiProviderNotice = t('settings.aiProvider.testPassed', { provider: data.result?.provider || '', status: data.result?.status || 200 });
            toast(state.aiProviderNotice);
        } catch (err) {
            state.aiProviderError = err.message;
        } finally {
            state.aiProviderTesting = false;
            renderApp();
        }
    }

    function translationProviderFormValue(id, fallback = '') {
        const element = document.getElementById(id);
        return element ? element.value : fallback;
    }

    function readTranslationProviderForm() {
        const current = state.translationProviderDraft || state.translationProviderSettings?.settings || {};
        return {
            default_provider_id: translationProviderFormValue('translationDefaultProvider', state.translationProviderSettings?.default_provider_id || 'openai'),
            provider_id: translationProviderFormValue('translationProviderType', current.provider_id || 'openai'),
            display_name: translationProviderFormValue('translationProviderName', current.display_name || 'OpenAI'),
            base_url: translationProviderFormValue('translationProviderBaseUrl', current.base_url || 'https://api.openai.com/v1'),
            endpoint_mode: translationProviderFormValue('translationProviderEndpointMode', current.endpoint_mode || 'responses'),
            structured_output_mode: translationProviderFormValue('translationProviderStructuredMode', current.structured_output_mode || 'json_schema'),
            auth_mode: translationProviderFormValue('translationProviderAuthMode', current.auth_mode || 'bearer'),
            api_key: translationProviderFormValue('translationProviderApiKey', ''),
            clear_api_key: Boolean(document.getElementById('translationProviderClearKey')?.checked),
            translation_model: translationProviderFormValue('translationProviderModel', current.translation_model || ''),
            timeout: Number(translationProviderFormValue('translationProviderTimeout', current.timeout || 120)),
            verify_tls: Boolean(document.getElementById('translationProviderVerifyTls')?.checked),
            allow_private_network: Boolean(document.getElementById('translationProviderAllowPrivate')?.checked),
            allow_http: Boolean(document.getElementById('translationProviderAllowHttp')?.checked),
        };
    }

    function renderTranslationProviderSettings() {
        if (!state.capabilities.can_manage_system_settings) return '';
        if (state.translationProviderLoading && !state.translationProviderSettings) {
            return `<section class="ai-provider-settings"><div class="plugin-status">${esc(t('settings.translationProvider.loading'))}</div></section>`;
        }
        if (!state.translationProviderSettings) {
            return `<section class="ai-provider-settings"><div class="plugin-status error">${esc(state.translationProviderError || t('settings.translationProvider.loadFailed'))}</div></section>`;
        }
        const stateValue = state.translationProviderSettings;
        const settings = state.translationProviderDraft || stateValue.settings || {};
        const secret = stateValue.secret || {};
        const isOpenAi = settings.provider_id === 'openai';
        const noAuth = settings.auth_mode === 'none';
        const busy = state.translationProviderSaving || state.translationProviderTesting;
        const sourceLabel = t(`settings.aiProvider.secretSource.${secret.source || 'none'}`);
        const credentialReady = noAuth || secret.available;
        const secretStatus = noAuth
            ? t('settings.aiProvider.authNotRequired')
            : secret.available
            ? t('settings.aiProvider.secretAvailable', { source: sourceLabel, hint: secret.hint || '' })
            : t('settings.aiProvider.secretMissing');
        const registered = Array.isArray(stateValue.providers) ? stateValue.providers : [];
        const providerOptions = registered.length
            ? registered
            : [{ id: stateValue.default_provider_id || 'openai', label: settings.display_name || t('settings.translationProvider.title') }];
        const customDisabled = isOpenAi ? 'disabled' : '';
        const pluginNotice = stateValue.plugin_enabled
            ? ''
            : `<div class="plugin-status">${esc(t('settings.translationProvider.pluginDisabled'))}</div>`;
        const inheritedNotice = stateValue.inherited
            ? `<div class="plugin-status">${esc(t('settings.translationProvider.inherited'))}</div>`
            : '';
        const providerLabel = settings.provider_id === 'openai' ? 'OpenAI' : (settings.display_name || t('settings.aiProvider.compatible'));
        const authenticationLabel = settings.auth_mode === 'none'
            ? t('settings.aiProvider.authNone')
            : String(settings.auth_mode || '').toUpperCase();
        return `<section class="ai-provider-settings">
            <div class="ai-provider-heading"><div><strong>${esc(t('settings.translationProvider.title'))}</strong><span>${esc(t('settings.translationProvider.help'))}</span></div><span class="ai-provider-status ${credentialReady ? 'ready' : 'missing'}">${esc(secretStatus)}</span></div>
            <div class="settings-current-summary" aria-label="${esc(t('settings.current.title'))}">
                <div class="settings-current-heading"><strong>${esc(t('settings.current.title'))}</strong><span>${esc(state.translationProviderDraft ? t('settings.current.unsaved') : t('settings.current.saved'))}</span></div>
                <dl class="settings-summary-grid">
                    <div><dt>${esc(t('settings.current.provider'))}</dt><dd>${esc(providerLabel)}</dd></div>
                    <div><dt>${esc(t('settings.current.endpoint'))}</dt><dd title="${esc(settings.base_url || '')}">${esc(settings.base_url || t('common.unspecified'))}</dd></div>
                    <div><dt>${esc(t('settings.current.model'))}</dt><dd>${esc(settings.translation_model || t('common.unspecified'))}</dd></div>
                    <div><dt>${esc(t('settings.current.authentication'))}</dt><dd>${esc(authenticationLabel)}</dd></div>
                </dl>
            </div>
            ${pluginNotice}${inheritedNotice}
            ${state.translationProviderNotice ? `<div class="settings-persistent-notice" role="status">${svg('check', 'small')}<span>${esc(state.translationProviderNotice)}</span></div>` : ''}
            <form id="translationProviderForm">
                <div class="ai-provider-grid">
                    <div class="form-field"><label for="translationDefaultProvider">${esc(t('settings.translationProvider.defaultProvider'))}</label><select class="input" id="translationDefaultProvider">${providerOptions.map(provider => `<option value="${esc(provider.id)}" ${provider.id === stateValue.default_provider_id ? 'selected' : ''}>${esc(provider.label)}</option>`).join('')}</select></div>
                    <div class="form-field"><label for="translationProviderType">${esc(t('settings.aiProvider.provider'))}</label><select class="input" id="translationProviderType"><option value="openai" ${isOpenAi ? 'selected' : ''}>OpenAI</option><option value="openai-compatible" ${!isOpenAi ? 'selected' : ''}>${esc(t('settings.aiProvider.compatible'))}</option></select></div>
                    <div class="form-field"><label for="translationProviderName">${esc(t('settings.aiProvider.name'))}</label><input class="input" id="translationProviderName" maxlength="80" value="${esc(settings.display_name || '')}" ${customDisabled} required></div>
                    <div class="form-field ai-provider-wide"><label for="translationProviderBaseUrl">${esc(t('settings.aiProvider.baseUrl'))}</label><input class="input" id="translationProviderBaseUrl" type="url" maxlength="2048" value="${esc(settings.base_url || '')}" ${customDisabled} required><small>${esc(t('settings.aiProvider.baseUrlHelp'))}</small></div>
                    <div class="form-field"><label for="translationProviderEndpointMode">${esc(t('settings.aiProvider.endpointMode'))}</label><select class="input" id="translationProviderEndpointMode" ${customDisabled}><option value="responses" ${settings.endpoint_mode === 'responses' ? 'selected' : ''}>Responses API</option><option value="chat_completions" ${settings.endpoint_mode === 'chat_completions' ? 'selected' : ''}>Chat Completions</option></select></div>
                    <div class="form-field"><label for="translationProviderStructuredMode">${esc(t('settings.aiProvider.structuredMode'))}</label><select class="input" id="translationProviderStructuredMode" ${customDisabled}><option value="json_schema" ${settings.structured_output_mode === 'json_schema' ? 'selected' : ''}>JSON Schema</option><option value="json_object" ${settings.structured_output_mode === 'json_object' ? 'selected' : ''}>JSON Object</option><option value="prompt" ${settings.structured_output_mode === 'prompt' ? 'selected' : ''}>Prompt only</option></select></div>
                    <div class="form-field"><label for="translationProviderAuthMode">${esc(t('settings.aiProvider.authMode'))}</label><select class="input" id="translationProviderAuthMode" ${customDisabled}><option value="bearer" ${settings.auth_mode === 'bearer' ? 'selected' : ''}>Bearer</option><option value="x-api-key" ${settings.auth_mode === 'x-api-key' ? 'selected' : ''}>X-API-Key</option><option value="none" ${settings.auth_mode === 'none' ? 'selected' : ''}>${esc(t('settings.aiProvider.authNone'))}</option></select></div>
                    <div class="form-field"><label for="translationProviderApiKey">${esc(t('settings.aiProvider.apiKey'))}</label><input class="input" id="translationProviderApiKey" type="password" maxlength="4096" autocomplete="new-password" placeholder="${esc(t('settings.aiProvider.apiKeyPlaceholder'))}" ${noAuth ? 'disabled' : ''}><small>${esc(t('settings.aiProvider.apiKeyHelp'))}</small></div>
                    <div class="form-field"><label for="translationProviderModel">${esc(t('settings.translationProvider.model'))}</label><input class="input" id="translationProviderModel" maxlength="190" value="${esc(settings.translation_model || '')}" required></div>
                    <div class="form-field"><label for="translationProviderTimeout">${esc(t('settings.aiProvider.timeout'))}</label><input class="input" id="translationProviderTimeout" type="number" min="15" max="300" value="${esc(settings.timeout || 120)}" required></div>
                </div>
                <div class="ai-provider-checks">
                    <label><input type="checkbox" id="translationProviderVerifyTls" ${settings.verify_tls ? 'checked' : ''} ${customDisabled}> ${esc(t('settings.aiProvider.verifyTls'))}</label>
                    <label><input type="checkbox" id="translationProviderAllowPrivate" ${settings.allow_private_network ? 'checked' : ''} ${customDisabled}> ${esc(t('settings.aiProvider.allowPrivate'))}</label>
                    <label><input type="checkbox" id="translationProviderAllowHttp" ${settings.allow_http ? 'checked' : ''} ${customDisabled}> ${esc(t('settings.aiProvider.allowHttp'))}</label>
                    <label><input type="checkbox" id="translationProviderClearKey" ${noAuth ? 'disabled' : ''}> ${esc(t('settings.aiProvider.clearKey'))}</label>
                </div>
                <div class="ai-provider-actions"><span class="login-error">${esc(state.translationProviderError || '')}</span><button type="button" class="btn secondary compact" data-action="test-translation-provider" ${busy || !stateValue.plugin_enabled ? 'disabled' : ''}>${esc(state.translationProviderTesting ? t('settings.aiProvider.testing') : t('settings.aiProvider.test'))}</button><button type="submit" class="btn primary compact" ${busy ? 'disabled' : ''}>${esc(state.translationProviderSaving ? t('settings.aiProvider.saving') : t('common.save'))}</button></div>
            </form>
        </section>`;
    }

    async function loadTranslationProviderSettings(renderView = true) {
        if (!state.capabilities.can_manage_system_settings || state.translationProviderLoading) return;
        state.translationProviderLoading = true;
        state.translationProviderError = '';
        if (renderView && state.view === 'settings') renderApp();
        try {
            state.translationProviderSettings = await api('translation-provider-settings');
            state.translationProviderDraft = null;
        } catch (err) {
            state.translationProviderError = err.message;
        } finally {
            state.translationProviderLoading = false;
            if (renderView && state.view === 'settings') renderApp();
        }
    }

    async function saveTranslationProviderSettings() {
        if (state.translationProviderSaving) return;
        const body = readTranslationProviderForm();
        state.translationProviderDraft = body;
        state.translationProviderNotice = '';
        state.translationProviderSaving = true;
        state.translationProviderError = '';
        renderApp();
        try {
            const data = await api('translation-provider-settings', { method: 'POST', body });
            state.translationProviderSettings = data;
            state.translationProviderDraft = null;
            state.translationProviderNotice = t('settings.translationProvider.saved');
            if (state.page?.translation) {
                state.page.translation = {
                    ...state.page.translation,
                    providers: Array.isArray(data.providers) ? data.providers : state.page.translation.providers,
                    default_provider_id: data.default_provider_id || state.page.translation.default_provider_id,
                };
            }
            toast(t('settings.translationProvider.saved'));
        } catch (err) {
            state.translationProviderError = err.message;
        } finally {
            state.translationProviderSaving = false;
            renderApp();
        }
    }

    async function testTranslationProvider() {
        if (state.translationProviderTesting) return;
        state.translationProviderDraft = readTranslationProviderForm();
        state.translationProviderTesting = true;
        state.translationProviderError = '';
        renderApp();
        try {
            const data = await api('translation-provider-test', { method: 'POST', body: {} });
            state.translationProviderNotice = t('settings.aiProvider.testPassed', { provider: data.result?.provider || '', status: data.result?.status || 200 });
            toast(state.translationProviderNotice);
        } catch (err) {
            state.translationProviderError = err.message;
        } finally {
            state.translationProviderTesting = false;
            renderApp();
        }
    }

    function renderPluginSettings() {
        if (!state.capabilities.can_manage_system_settings) return '';

        const installed = state.installedPlugins.map(plugin => {
            const sidebar = plugin.ui?.sidebar;
            const icon = sidebar?.icon || '🧩';
            const busy = state.pluginOperation === plugin.id;
            const pluginName = pluginT(plugin.id, 'plugin.name', {}, plugin.name);
            const pluginDescription = pluginT(plugin.id, 'plugin.description', {}, plugin.description || t('plugins.noDescription'));
            const adapterStatus = plugin.database_adapter;
            const manualConnection = Boolean(adapterStatus
                && adapterStatus.manual_connection_detected
                && adapterStatus.canonical_backend === adapterStatus.adapter);
            const retiredAfterMigration = Boolean(adapterStatus
                && adapterStatus.adapter === 'mysql'
                && adapterStatus.canonical_backend === 'postgresql'
                && adapterStatus.available === false);
            const runtimeRequirement = plugin.runtime_requirement;
            const runtimeBlocked = !plugin.enabled && runtimeRequirement && runtimeRequirement.ready !== true;
            const baseStatusLabel = retiredAfterMigration
                ? pluginT(plugin.id, 'ui.unavailableAfterPostgreSqlMigration', {}, 'MySQL is unavailable after migration to PostgreSQL')
                : manualConnection
                ? t('plugins.connection.cardStatus', { backend: adapterStatus.canonical_backend })
                : adapterStatus
                ? `${adapterStatus.lifecycle} · backend ${adapterStatus.canonical_backend}`
                : (plugin.enabled ? t('plugins.enabled') : t('plugins.disabled'));
            const statusLabel = runtimeBlocked && !manualConnection && !retiredAfterMigration ? `${baseStatusLabel} · ${runtimeRequirement.code}` : baseStatusLabel;
            const control = manualConnection
                ? `<button type="button" class="btn secondary compact" data-action="open-database-connection-status" data-plugin-id="${esc(plugin.id)}" ${busy ? 'disabled' : ''}>${esc(pluginT(plugin.id, 'ui.configure', {}, 'Configure'))}</button>`
                : adapterStatus && !plugin.enabled && adapterStatus.can_configure
                ? `<button type="button" class="toggle plugin-toggle" data-action="toggle-plugin" data-plugin-id="${esc(plugin.id)}" data-enabled="true" aria-label="${esc(t('plugins.enable', { name: pluginName }))}" aria-pressed="false" ${busy || runtimeBlocked ? 'disabled' : ''}></button>`
                : adapterStatus
                ? `<button type="button" class="btn secondary compact" data-action="open-plugin" data-plugin-id="${esc(plugin.id)}" ${!plugin.enabled || busy ? 'disabled' : ''}>${esc(pluginT(plugin.id, adapterStatus.lifecycle === 'ACTIVE' ? 'ui.viewStatus' : 'ui.configure', {}, adapterStatus.lifecycle === 'ACTIVE' ? 'Status' : 'Configure'))}</button>`
                : `<button type="button" class="toggle plugin-toggle ${plugin.enabled ? 'on' : ''}" data-action="toggle-plugin" data-plugin-id="${esc(plugin.id)}" data-enabled="${plugin.enabled ? 'false' : 'true'}" aria-label="${esc(t(plugin.enabled ? 'plugins.disable' : 'plugins.enable', { name: pluginName }))}" aria-pressed="${plugin.enabled ? 'true' : 'false'}" ${busy || runtimeBlocked ? 'disabled' : ''}></button>`;
            return `<article class="plugin-card" data-plugin-card-id="${esc(plugin.id)}">
                <span class="plugin-card-icon" aria-hidden="true">${esc(icon)}</span>
                <div class="plugin-card-copy"><strong>${esc(pluginName)}</strong><span>${esc(pluginDescription)}</span><small>version ${esc(plugin.version)} · ${esc(statusLabel)}</small></div>
                ${control}
            </article>`;
        }).join('');

        const available = state.availablePlugins.map(plugin => {
            const busy = state.pluginOperation === plugin.id;
            return `<article class="plugin-card available" data-plugin-card-id="${esc(plugin.id)}">
                <span class="plugin-card-icon" aria-hidden="true">${esc(plugin.icon || '🧩')}</span>
                <div class="plugin-card-copy"><strong>${esc(plugin.name)}</strong><span>${esc(plugin.description || t('plugins.noDescription'))}</span><small>version ${esc(plugin.version)}</small></div>
                <button type="button" class="btn secondary compact plugin-download" data-action="download-plugin" data-plugin-id="${esc(plugin.id)}" ${busy ? 'disabled' : ''}>${svg('download', 'small')} ${esc(busy ? t('plugins.downloading') : t('plugins.download'))}</button>
            </article>`;
        }).join('');

        let catalogStatus = '';
        if (state.pluginsLoading) {
            catalogStatus = `<div class="plugin-status">${esc(t('plugins.loading'))}</div>`;
        } else if (state.pluginCatalogError) {
            catalogStatus = `<div class="plugin-status error">${esc(t('plugins.catalogError', { error: state.pluginCatalogError }))}</div>`;
        } else if (!state.pluginCatalogConfigured) {
            catalogStatus = `<div class="plugin-status">${esc(t('plugins.catalogNotConfigured'))} <code>OPENCONCEPT_PLUGIN_CATALOG_URL</code></div>`;
        } else if (state.pluginsLoaded && !available) {
            catalogStatus = `<div class="plugin-status">${esc(t('plugins.noneAvailable'))}</div>`;
        }

        const runtime = state.runtimeEnvironment;
        const databaseIntegrityPolicy = `<div class="database-integrity-policy" role="note">${svg('info', 'small')}<span>${esc(t('plugins.connection.integrityPolicy'))}</span></div>`;
        const runtimeStatus = runtime
            ? `<div class="plugin-status"><strong>Runtime</strong>: ${esc(runtime.deployment_profile)} · ${esc(runtime.runtime)} · ${esc(runtime.os_family)} ${esc(runtime.architecture)} · PHP ${esc(runtime.php_version)} · DB ${esc(runtime.canonical_backend || 'unknown')}</div>`
            : '';

        return `<section class="plugin-settings">
            <div class="plugin-settings-heading"><div><strong>${esc(t('plugins.title'))}</strong><span>${esc(t('plugins.help'))}</span></div><button type="button" class="btn secondary compact" data-action="refresh-plugins" ${state.pluginsLoading ? 'disabled' : ''}>${esc(t('plugins.refresh'))}</button></div>
            ${databaseIntegrityPolicy}
            ${runtimeStatus}
            ${installed ? `<div class="plugin-group-label">${esc(t('plugins.installed'))}</div><div class="plugin-list">${installed}</div>` : `<div class="plugin-status">${esc(t('plugins.noneInstalled'))}</div>`}
            ${available ? `<div class="plugin-group-label">${esc(t('plugins.available'))}</div><div class="plugin-list">${available}</div>` : ''}
            ${catalogStatus}
        </section>`;
    }

    function renderRagCoreSettings() {
        if (!state.capabilities.can_manage_system_settings) return '';
        return `<section class="rag-core-settings-entry settings-section-card">
            <div class="settings-section-heading">
                <span class="rag-core-settings-mark" aria-hidden="true">RAG</span>
                <div class="rag-core-settings-copy"><strong>${esc(pluginT('rag-core', 'core.settings.title', {}, 'RAG retrieval'))}</strong><span>${esc(pluginT('rag-core', 'core.settings.help', {}, 'RAG Core is always available. Configure the retrieval and answer providers used by OpenConcept.'))}</span></div>
                <span class="rag-core-settings-badge">${esc(pluginT('rag-core', 'core.settings.badge', {}, 'OpenConcept Core'))}</span>
            </div>
            <div id="ragCoreSettingsMount" class="rag-core-settings-mount"><div class="plugin-status">${esc(t('settings.rag.loading'))}</div></div>
        </section>`;
    }

    function renderRagFlowOverview() {
        if (!state.capabilities.can_manage_system_settings) return '';
        return `<div class="rag-core-flow-overview" id="ragCoreFlowMount" data-flow-preview><div class="rag-core-loading">${esc(t('settings.rag.loading'))}</div></div>`;
    }

    function settingsTabDescription(tab) {
        const key = ['general', 'ai', 'translation', 'plugins'].includes(tab) ? tab : 'general';
        return t(`settings.tabs.${key}Help`);
    }

    function renderSettingsPage() {
        const organizationSettings = state.capabilities.can_manage_system_settings ? `<section class="organization-settings"><div class="organization-settings-copy"><strong>${esc(t('settings.organizationName'))}</strong><span>${esc(t('settings.organizationNameHelp'))}</span></div><form id="organizationForm" class="organization-settings-form"><label class="sr-only" for="organizationName">${esc(t('settings.organizationName'))}</label><input class="input" id="organizationName" maxlength="120" value="${esc(state.organizationName)}" placeholder="${esc(t('settings.organizationNamePlaceholder'))}" required><button class="btn primary compact" type="submit">${esc(t('common.save'))}</button></form></section>` : '';
        const localeSettings = `<section class="organization-settings"><div class="organization-settings-copy"><strong>${esc(t('settings.uiLanguage'))}</strong><span>${esc(t('settings.uiLanguageHelp'))}</span></div><select class="select" id="uiLocaleSelect">${availableContentLanguages().map(locale => `<option value="${esc(locale.code)}" ${locale.code === state.i18n.locale ? 'selected' : ''}>${esc(locale.name)}</option>`).join('')}</select></section>`;
        const availableTabs = state.capabilities.can_manage_system_settings ? ['general', 'ai', 'translation', 'plugins'] : ['general'];
        const activeTab = availableTabs.includes(state.settingsTab) ? state.settingsTab : 'general';
        const tabButton = (tab, label) => `<button type="button" class="settings-tab ${activeTab === tab ? 'active' : ''}" id="settingsTab-${tab}" role="tab" aria-selected="${activeTab === tab}" aria-controls="settingsPanel-${tab}" tabindex="${activeTab === tab ? '0' : '-1'}" data-action="settings-tab" data-settings-tab="${tab}"><strong>${esc(label)}</strong><small>${esc(settingsTabDescription(tab))}</small></button>`;
        const tabs = `<nav class="settings-tabs" role="tablist" aria-label="${esc(t('settings.tabs.label'))}">${tabButton('general', t('settings.tabs.general'))}${state.capabilities.can_manage_system_settings ? `${tabButton('ai', t('settings.tabs.ai'))}${tabButton('translation', t('settings.tabs.translation'))}${tabButton('plugins', t('settings.tabs.plugins'))}` : ''}</nav>`;
        const panels = `<div class="settings-panels">
            <section class="settings-panel" id="settingsPanel-general" role="tabpanel" aria-labelledby="settingsTab-general" data-settings-panel="general" ${activeTab === 'general' ? '' : 'hidden'}><section class="settings-section-card"><div class="settings-section-heading"><div><strong>${esc(t('settings.general.title'))}</strong><span>${esc(t('settings.general.help'))}</span></div></div>${localeSettings}${organizationSettings}</section></section>
            ${state.capabilities.can_manage_system_settings ? `<section class="settings-panel" id="settingsPanel-ai" role="tabpanel" aria-labelledby="settingsTab-ai" data-settings-panel="ai" ${activeTab === 'ai' ? '' : 'hidden'}>${renderRagFlowOverview()}${renderAiProviderSettings()}${renderRagCoreSettings()}</section><section class="settings-panel" id="settingsPanel-translation" role="tabpanel" aria-labelledby="settingsTab-translation" data-settings-panel="translation" ${activeTab === 'translation' ? '' : 'hidden'}>${renderTranslationProviderSettings()}</section><section class="settings-panel" id="settingsPanel-plugins" role="tabpanel" aria-labelledby="settingsTab-plugins" data-settings-panel="plugins" ${activeTab === 'plugins' ? '' : 'hidden'}>${renderPluginSettings()}</section>` : ''}
        </div>`;
        return `<div class="settings-page-scroller"><div class="settings-page">
            <header class="settings-page-header"><div><span class="settings-page-kicker">OpenConcept</span><h1>${esc(t('settings.title'))}</h1><p class="settings-page-description">${esc(t('settings.page.description'))}</p><p class="settings-page-context">${esc(settingsTabDescription(activeTab))}</p></div>${state.capabilities.can_manage_system_settings ? `<span class="settings-page-role">${esc(t('settings.systemAdministrator'))}</span>` : ''}</header>
            <div class="settings-page-layout">${tabs}${panels}</div>
        </div></div>`;
    }

    function activateSettingsTab(tab) {
        const allowedTabs = state.capabilities.can_manage_system_settings
            ? ['general', 'ai', 'translation', 'plugins']
            : ['general'];
        const activeTab = allowedTabs.includes(tab) ? tab : 'general';
        state.settingsTab = activeTab;
        root.querySelectorAll('[data-settings-tab]').forEach(button => {
            const active = button.dataset.settingsTab === activeTab;
            button.classList.toggle('active', active);
            button.setAttribute('aria-selected', String(active));
            button.tabIndex = active ? 0 : -1;
        });
        root.querySelectorAll('[data-settings-panel]').forEach(panel => {
            panel.hidden = panel.dataset.settingsPanel !== activeTab;
        });
        const description = root.querySelector('.settings-page-context');
        if (description) description.textContent = settingsTabDescription(activeTab);
        if (state.view === 'settings') {
            history.replaceState(null, '', activeTab === 'general' ? '#settings' : `#settings-${activeTab}`);
            if (activeTab === 'ai') mountRagCoreSettings();
            else window.OpenConceptRagSettings?.unmount?.();
        }
    }

    function mountRagCoreSettings() {
        const host = document.getElementById('ragCoreSettingsMount');
        if (host) window.OpenConceptRagSettings?.mount?.(host);
    }

    async function loadPlugins(renderView = true) {
        if (!state.capabilities.can_manage_system_settings || state.pluginsLoading) return;
        state.pluginsLoading = true;
        state.pluginCatalogError = '';
        if (renderView && state.view === 'settings') renderApp();
        try {
            const data = await api('plugins');
            state.installedPlugins = data.installed || [];
            state.availablePlugins = data.available || [];
            state.pluginCatalogConfigured = Boolean(data.catalog_configured);
            state.pluginCatalogError = data.catalog_error || '';
            state.runtimeEnvironment = data.runtime_environment || null;
            state.pluginsLoaded = true;
        } catch (err) {
            state.pluginCatalogError = err.message;
        } finally {
            state.pluginsLoading = false;
            if (renderView && state.view === 'settings') renderApp();
        }
    }

    async function downloadPlugin(pluginId) {
        const plugin = state.availablePlugins.find(item => item.id === pluginId);
        if (!plugin || state.pluginOperation) return;
        if (!window.confirm(t('plugins.confirmDownload', { name: plugin.name, version: plugin.version }))) return;

        state.pluginOperation = pluginId;
        renderApp();
        try {
            await api('download-plugin', { method: 'POST', body: { plugin_id: pluginId } });
            toast(t('plugins.downloaded', { name: plugin.name }));
            state.pluginsLoaded = false;
            state.pluginOperation = '';
            await loadPlugins();
        } catch (err) {
            state.pluginOperation = '';
            renderApp();
            toast(err.message, 'error');
        }
    }

    async function togglePlugin(pluginId, enabled) {
        const plugin = state.installedPlugins.find(item => item.id === pluginId);
        if (!plugin || state.pluginOperation) return;

        state.pluginOperation = pluginId;
        renderApp();
        try {
            await api('toggle-plugin', { method: 'POST', body: { plugin_id: pluginId, enabled } });
            plugin.enabled = enabled;
            renderApp();
            toast(t('plugins.stateChanged', { name: plugin.name, state: enabled ? t('plugins.enabled') : t('plugins.disabled') }));
            window.setTimeout(() => window.location.reload(), 350);
        } catch (err) {
            state.pluginOperation = '';
            renderApp();
            toast(err.message, 'error');
        }
    }

    function openPlugin(pluginId) {
        const plugin = state.pluginUi.find(item => item.id === pluginId);
        if (!plugin) return;
        state.mobileSidebar = false;
        renderApp();
        const detail = Object.freeze({ ...plugin });
        const event = new CustomEvent('openconcept:plugin-open', { detail, cancelable: true });
        window.dispatchEvent(event);
        const handler = pluginOpenHandlers.get(pluginId);
        if (handler) {
            try {
                handler(detail);
            } catch (error) {
                toast(error?.message || t('plugins.openFailed', { name: pluginT(plugin.id, 'plugin.sidebar.label', {}, plugin.label) }), 'error');
            }
        } else if (!event.defaultPrevented) {
            toast(t('plugins.active', { name: pluginT(plugin.id, 'plugin.sidebar.label', {}, plugin.label) }));
        }
    }

    function normalizePublicSharePageIds(value, rootId = Number(state.page?.id || 0)) {
        const ids = [...new Set((Array.isArray(value) ? value : [])
            .map(Number)
            .filter(pageId => Number.isInteger(pageId) && pageId > 0 && pageId !== rootId))];
        return ids.sort((left, right) => left - right);
    }

    function normalizePublicShare(value, rootId = Number(state.page?.id || 0)) {
        const source = value?.public_share && typeof value.public_share === 'object'
            ? value.public_share
            : (value && typeof value === 'object' ? value : {});
        const seen = new Set();
        const descendants = (Array.isArray(source.descendants) ? source.descendants : []).reduce((items, item) => {
            if (!item || typeof item !== 'object') return items;
            const id = Number(item.id || 0);
            if (!Number.isInteger(id) || id < 1 || id === rootId || seen.has(id)) return items;
            seen.add(id);
            const rawParentId = item.parent_id === null ? null : Number(item.parent_id || 0);
            items.push({
                id,
                parent_id: rawParentId && Number.isInteger(rawParentId) ? rawParentId : null,
                depth: Math.max(1, Math.min(20, Number(item.depth) || 1)),
                title: String(item.title || t('page.untitled')),
                icon: String(item.icon || '📄'),
                publishable: Boolean(item.publishable),
                can_edit: Boolean(item.can_edit),
            });
            return items;
        }, []);
        const revision = typeof source.revision === 'string' || typeof source.revision === 'number'
            ? source.revision
            : null;
        const allowedPublicationStates = new Set(['unpublished', 'current', 'stale', 'modified', 'missing', 'failed', 'publishing']);
        const publicationState = allowedPublicationStates.has(String(source.publication_state || ''))
            ? String(source.publication_state)
            : (source.enabled ? 'current' : 'unpublished');
        const rootPublishable = Boolean(source.root_publishable);
        return {
            enabled: Boolean(source.enabled),
            public_url: normalizePublicUrl(source.public_url),
            public_slug: String(source.public_slug || ''),
            slug_locked: Boolean(source.slug_locked),
            revision,
            selected_page_ids: normalizePublicSharePageIds(source.selected_page_ids, rootId),
            descendants,
            hidden_selected_count: Math.max(0, Number(source.hidden_selected_count) || 0),
            root_publishable: rootPublishable,
            publication_state: publicationState,
            recovery_pending: Boolean(source.recovery_pending),
            integrity_status: String(source.integrity_status || ''),
            can_update: typeof source.can_update === 'boolean' ? source.can_update : rootPublishable,
            can_stop: typeof source.can_stop === 'boolean' ? source.can_stop : Boolean(source.enabled),
            published_at: String(source.published_at || ''),
            published_page_count: Math.max(0, Math.floor(Number(source.published_page_count) || 0)),
        };
    }

    function applyPublicShareResponse(data, pageId) {
        const publicShare = normalizePublicShare(data, pageId);
        state.publicShare = publicShare;
        state.publicShareDraftPageIds = [...publicShare.selected_page_ids];
        state.publicShareDraftSlug = publicShare.public_slug;
        state.publicShareError = '';
    }

    async function loadPublicShare(pageId) {
        const requestedPageId = Number(pageId || 0);
        if (requestedPageId < 1) return false;
        state.publicShareLoading = true;
        state.publicShareError = '';
        renderApp();
        try {
            const data = await api('public-share', { query: `&id=${encodeURIComponent(requestedPageId)}` });
            if (state.dialog !== 'share' || Number(state.page?.id || 0) !== requestedPageId) return false;
            applyPublicShareResponse(data, requestedPageId);
            state.publicShareLoading = false;
            renderApp();
            return true;
        } catch (error) {
            if (state.dialog !== 'share' || Number(state.page?.id || 0) !== requestedPageId) return false;
            state.publicShareLoading = false;
            state.publicShareError = error.message || t('share.public.loadFailed');
            renderApp();
            return false;
        }
    }

    async function openShareDialog() {
        const pageId = Number(state.page?.id || 0);
        if (pageId < 1) return;
        state.publicShare = null;
        state.publicShareDraftPageIds = [];
        state.publicShareDraftSlug = '';
        state.publicShareLoading = false;
        state.publicShareSaving = false;
        state.publicShareError = '';
        state.dialog = 'share';
        renderApp();
        await loadPublicShare(pageId);
    }

    function publicShareDraftPageIds() {
        return normalizePublicSharePageIds(state.publicShareDraftPageIds);
    }

    function publicShareSelectionChanged() {
        if (!state.publicShare) return false;
        return JSON.stringify(publicShareDraftPageIds()) !== JSON.stringify(state.publicShare.selected_page_ids);
    }

    function publicShareHasUnavailableSelection() {
        if (!state.publicShare) return false;
        const selected = new Set(publicShareDraftPageIds());
        return state.publicShare.descendants.some(page => selected.has(page.id) && !page.publishable);
    }

    function publicShareIntegrityBlocked(publicShare = state.publicShare) {
        return Boolean(publicShare && (publicShare.recovery_pending || ['modified', 'missing'].includes(publicShare.publication_state)));
    }

    function publicShareBlockedMessage(publicShare = state.publicShare) {
        if (publicShare?.recovery_pending) return t('share.public.failedHelp');
        return t(publicShare?.publication_state === 'modified' ? 'share.public.errorModified' : 'share.public.errorMissing');
    }

    function publicShareSlugValid(value) {
        const slug = String(value || '').trim();
        return slug.length >= 3
            && slug.length <= 80
            && /^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(slug)
            && !['assets', 'api', 'app', 'public', 'storage', 'admin', 'index'].includes(slug);
    }

    function setAllPublicShareDescendants(selected) {
        if (!state.publicShare || state.publicShareSaving || publicShareIntegrityBlocked()) return;
        const pageIds = new Set(publicShareDraftPageIds());
        state.publicShare.descendants.forEach(page => {
            if (selected && page.can_edit && page.publishable) pageIds.add(page.id);
            else if (!selected) pageIds.delete(page.id);
        });
        state.publicShareDraftPageIds = normalizePublicSharePageIds([...pageIds]);
        renderApp();
    }

    async function persistPublicShare(enabled, successKey) {
        const pageId = Number(state.page?.id || 0);
        const publicShare = state.publicShare;
        if (pageId < 1 || !publicShare || state.publicShareLoading || state.publicShareSaving) return;
        if (enabled && (!publicShare.root_publishable || publicShareHasUnavailableSelection())) {
            toast(t('share.public.resolveUnavailable'), 'error');
            return;
        }
        if (publicShareIntegrityBlocked(publicShare)) {
            toast(publicShareBlockedMessage(publicShare), 'error');
            return;
        }
        if (enabled && publicShare.enabled && !publicShare.can_update) {
            toast(t(publicShare.publication_state === 'publishing' ? 'share.public.busy' : 'share.public.refreshFailed'), 'error');
            return;
        }
        const publicSlug = String(state.publicShareDraftSlug || '').trim();
        if (enabled && !publicShare.slug_locked && !publicSlug) {
            toast(t('share.public.slugRequired'), 'error');
            root.querySelector('#publicShareSlug')?.focus();
            return;
        }
        if (enabled && !publicShare.slug_locked && !publicShareSlugValid(publicSlug)) {
            toast(t('share.public.slugInvalid'), 'error');
            root.querySelector('#publicShareSlug')?.focus();
            return;
        }
        if (enabled && !(await flushPendingPageSave())) {
            toast(t('share.public.pageSaveFailed'), 'error');
            return;
        }
        if (Number(state.page?.id || 0) !== pageId || state.dialog !== 'share') return;
        state.publicShareSaving = true;
        state.publicShareError = '';
        renderApp();
        try {
            const data = await api('public-share', {
                method: 'POST',
                body: {
                    page_id: pageId,
                    enabled: Boolean(enabled),
                    public_slug: publicSlug,
                    selected_page_ids: publicShareDraftPageIds(),
                    expected_revision: publicShare.revision,
                },
            });
            if (Number(state.page?.id || 0) !== pageId || state.dialog !== 'share') return;
            applyPublicShareResponse(data, pageId);
            state.publicShareSaving = false;
            if (state.publicShare?.recovery_pending) {
                if (!state.publicShare.enabled) clearAffectedPublication(pageId, false);
                renderApp();
                toast(t('share.public.failedHelp'), 'error');
                return;
            }
            clearAffectedPublication(pageId, false);
            renderApp();
            toast(t(successKey));
        } catch (error) {
            if (Number(state.page?.id || 0) !== pageId || state.dialog !== 'share') return;
            state.publicShareSaving = false;
            await handlePublicShareError(error, pageId);
        }
    }

    function classifyPublicShareError(error) {
        const code = String(error?.code || '').toLowerCase();
        if (code === 'artifact_modified') return 'modified';
        if (code === 'artifact_missing') return 'missing';
        if (code === 'publication_busy') return 'busy';
        if (code === 'publication_conflict') return 'conflict';
        if (code === 'source_changed_during_build') return 'source-changed';
        if (code.includes('modified') || code.includes('integrity_mismatch')) return 'modified';
        if (code.includes('missing') || code.includes('not_found')) return 'missing';
        if (code.includes('busy') || code.includes('locked') || code.includes('in_progress')) return 'busy';
        if (code.includes('conflict') || code.includes('revision') || code.includes('stale')) return 'conflict';
        return 'other';
    }

    async function handlePublicShareError(error, pageId) {
        const kind = classifyPublicShareError(error);
        if (kind === 'conflict') {
            const priorSlug = state.publicShareDraftSlug;
            const priorSlugLocked = Boolean(state.publicShare?.slug_locked);
            const reloaded = await loadPublicShare(pageId);
            if (!priorSlugLocked && reloaded && !state.publicShare?.slug_locked) {
                state.publicShareDraftSlug = priorSlug;
                state.publicShareError = error?.message || t('share.public.concurrentUpdate');
                renderApp();
                toast(state.publicShareError, 'error');
            } else {
                toast(t('share.public.concurrentUpdate'), 'error');
            }
            return kind;
        }
        if (kind === 'source-changed') {
            const priorSlug = state.publicShareDraftSlug;
            await loadPublicShare(pageId);
            if (!state.publicShare?.slug_locked) state.publicShareDraftSlug = priorSlug;
            state.publicShareError = t('share.public.sourceChanged');
            renderApp();
            toast(state.publicShareError, 'error');
            return kind;
        }
        if (kind === 'modified' || kind === 'missing') {
            if (error?.data?.public_share) applyPublicShareResponse(error.data, pageId);
            else if (state.publicShare) {
                state.publicShare.publication_state = kind;
                state.publicShare.can_update = false;
                state.publicShare.can_stop = false;
            }
            clearAffectedPublication(pageId, false);
            state.publicShareError = t(kind === 'modified' ? 'share.public.errorModified' : 'share.public.errorMissing');
            renderApp();
            toast(state.publicShareError, 'error');
            return kind;
        }
        state.publicShareError = kind === 'busy'
            ? t('share.public.busy')
            : (error?.message || t('share.public.saveFailed'));
        renderApp();
        toast(state.publicShareError, 'error');
        return kind;
    }

    async function refreshPublicShare() {
        const pageId = Number(state.page?.id || 0);
        const publicShare = state.publicShare;
        if (pageId < 1 || !publicShare?.enabled || state.publicShareLoading || state.publicShareSaving) return;
        if (publicShareIntegrityBlocked(publicShare)) {
            toast(publicShareBlockedMessage(publicShare), 'error');
            return;
        }
        if (!publicShare.can_update) {
            toast(t('share.public.refreshFailed'), 'error');
            return;
        }
        if (!(await flushPendingPageSave())) {
            toast(t('share.public.pageSaveFailed'), 'error');
            return;
        }
        if (Number(state.page?.id || 0) !== pageId || state.dialog !== 'share') return;
        state.publicShareSaving = true;
        state.publicShareError = '';
        renderApp();
        try {
            const data = await api('public-share-refresh', {
                method: 'POST',
                body: { root_page_id: pageId, expected_revision: publicShare.revision },
            });
            if (Number(state.page?.id || 0) !== pageId || state.dialog !== 'share') return;
            applyPublicShareResponse(data, pageId);
            state.publicShareSaving = false;
            if (state.publicShare?.recovery_pending) {
                renderApp();
                toast(t('share.public.failedHelp'), 'error');
                return;
            }
            clearAffectedPublication(pageId, false);
            renderApp();
            toast(t('share.public.refreshed'));
        } catch (error) {
            if (Number(state.page?.id || 0) !== pageId || state.dialog !== 'share') return;
            state.publicShareSaving = false;
            await handlePublicShareError(error, pageId);
        }
    }

    async function refreshAffectedPublications() {
        if (state.affectedPublicationsRefreshing || !(state.affectedPublications instanceof Map) || !state.affectedPublications.size) return;
        state.affectedPublicationsRefreshing = true;
        syncAffectedPublicationsBanner();
        if (!(await flushPendingPageSave())) {
            state.affectedPublicationsRefreshing = false;
            syncAffectedPublicationsBanner();
            toast(t('share.public.pageSaveFailed'), 'error');
            return;
        }
        const publications = [...state.affectedPublications.values()];
        let updated = 0;
        let failed = 0;
        for (const publication of publications) {
            try {
                const data = await api('public-share-refresh', {
                    method: 'POST',
                    body: {
                        root_page_id: publication.root_page_id,
                        expected_revision: publication.revision,
                    },
                });
                const refreshedShare = normalizePublicShare(data, publication.root_page_id);
                if (refreshedShare.recovery_pending) {
                    state.affectedPublications.set(publication.root_page_id, {
                        ...publication,
                        public_url: refreshedShare.public_url || publication.public_url,
                        revision: refreshedShare.revision,
                    });
                    if (state.dialog === 'share' && Number(state.page?.id || 0) === publication.root_page_id) {
                        applyPublicShareResponse(data, publication.root_page_id);
                    }
                    failed += 1;
                    continue;
                }
                clearAffectedPublication(publication.root_page_id, false);
                updated += 1;
                if (state.dialog === 'share' && Number(state.page?.id || 0) === publication.root_page_id) {
                    applyPublicShareResponse(data, publication.root_page_id);
                }
            } catch (error) {
                const kind = classifyPublicShareError(error);
                if (['modified', 'missing'].includes(kind)) {
                    clearAffectedPublication(publication.root_page_id, false);
                    if (state.dialog === 'share' && Number(state.page?.id || 0) === publication.root_page_id) {
                        if (error?.data?.public_share) applyPublicShareResponse(error.data, publication.root_page_id);
                        else if (state.publicShare) {
                            state.publicShare.publication_state = kind;
                            state.publicShare.can_update = false;
                            state.publicShare.can_stop = false;
                        }
                    }
                } else if (['conflict', 'source-changed'].includes(kind)) {
                    try {
                        const latestData = await api('public-share', { query: `&id=${encodeURIComponent(publication.root_page_id)}` });
                        const latest = normalizePublicShare(latestData, publication.root_page_id);
                        if (state.dialog === 'share' && Number(state.page?.id || 0) === publication.root_page_id) {
                            applyPublicShareResponse(latestData, publication.root_page_id);
                        }
                        if (latest.enabled && ['stale', 'failed'].includes(latest.publication_state) && latest.can_update && !publicShareIntegrityBlocked(latest)) {
                            state.affectedPublications.set(publication.root_page_id, {
                                ...publication,
                                public_url: latest.public_url || publication.public_url,
                                revision: latest.revision,
                            });
                        } else {
                            clearAffectedPublication(publication.root_page_id, false);
                        }
                    } catch (_) {
                        // Keep the prior entry so the user can retry after a transient reload failure.
                    }
                }
                failed += 1;
            }
        }
        state.affectedPublicationsRefreshing = false;
        if (state.dialog === 'share') renderApp();
        else syncAffectedPublicationsBanner();
        if (updated && !failed) toast(t('share.public.refreshed'));
        else if (failed) toast(t('share.public.refreshFailed'), 'error');
    }

    async function stopPublicShare() {
        if (!state.publicShare?.enabled || !state.publicShare.can_stop || publicShareIntegrityBlocked()) return;
        if (!window.confirm(t('share.public.stopConfirm', { title: state.page?.title || t('page.untitled') }))) return;
        await persistPublicShare(false, 'share.public.stopped');
    }

    async function copyPublicShareLink() {
        const url = normalizePublicUrl(state.publicShare?.public_url);
        if (!state.publicShare?.enabled || !url) return;
        try {
            if (!navigator.clipboard?.writeText) throw new Error(t('share.public.copyFailed'));
            await navigator.clipboard.writeText(url);
            toast(t('share.public.linkCopied'));
        } catch (error) {
            toast(error.message || t('share.public.copyFailed'), 'error');
        }
    }

    function renderPublicSharePageRow(page, rootPage = false) {
        const selected = rootPage || publicShareDraftPageIds().includes(Number(page.id));
        const canToggle = !rootPage && !state.publicShareSaving && !publicShareIntegrityBlocked() && (selected || page.can_edit && page.publishable);
        const depth = rootPage ? 0 : Math.max(1, Math.min(6, Number(page.depth) || 1));
        const status = rootPage
            ? t('share.public.rootPage')
            : !page.publishable
                ? t('share.public.notPublishable')
                : !page.can_edit
                    ? t('share.public.noEditPermission')
                    : selected
                        ? t('share.public.selected')
                        : '';
        return `<label class="public-share-page-row depth-${depth} ${selected ? 'selected' : ''} ${canToggle ? '' : 'disabled'}"><input type="checkbox" data-change="public-share-page" value="${Number(page.id)}" ${selected ? 'checked' : ''} ${canToggle ? '' : 'disabled'} aria-label="${esc(t('share.public.pageSelectionFor', { title: page.title }))}"><span class="public-share-page-icon" aria-hidden="true">${esc(page.icon)}</span><span class="public-share-page-title">${esc(page.title)}</span>${status ? `<span class="public-share-page-status">${esc(status)}</span>` : ''}</label>`;
    }

    function renderPublicShareSetting() {
        if (state.publicShareLoading || !state.publicShare && !state.publicShareError) {
            return `<section class="public-share-section" aria-labelledby="publicShareHeading" aria-busy="true"><div class="public-share-heading"><span class="public-share-heading-icon">${svg('globe', 'small')}</span><div><h3 id="publicShareHeading">${esc(t('share.public.title'))}</h3><p>${esc(t('share.public.description'))}</p></div></div><div class="public-share-loading" role="status">${esc(t('share.public.loading'))}</div></section>`;
        }
        if (!state.publicShare) {
            return `<section class="public-share-section" aria-labelledby="publicShareHeading"><div class="public-share-heading"><span class="public-share-heading-icon">${svg('globe', 'small')}</span><div><h3 id="publicShareHeading">${esc(t('share.public.title'))}</h3><p>${esc(t('share.public.description'))}</p></div></div><div class="public-share-error" role="alert">${esc(state.publicShareError || t('share.public.loadFailed'))}</div><button type="button" class="btn secondary compact" data-action="retry-public-share">${esc(t('share.public.retry'))}</button></section>`;
        }

        const publicShare = state.publicShare;
        const integrityBlocked = publicShareIntegrityBlocked(publicShare);
        const rootPage = {
            id: Number(state.page?.id || 0),
            title: state.page?.title || t('page.untitled'),
            icon: state.page?.icon || '📄',
            publishable: publicShare.root_publishable,
            can_edit: true,
            depth: 0,
        };
        const selected = new Set(publicShareDraftPageIds());
        const selectedVisibleCount = 1 + publicShare.descendants.filter(page => selected.has(page.id)).length;
        const unavailableSelection = publicShareHasUnavailableSelection();
        const hasPublicSlug = publicShareSlugValid(state.publicShareDraftSlug);
        const canPersist = publicShare.root_publishable && !unavailableSelection && !integrityBlocked && !state.publicShareSaving && (publicShare.slug_locked || hasPublicSlug);
        const selectionChanged = publicShareSelectionChanged();
        const descendants = publicShare.descendants.length
            ? publicShare.descendants.map(page => renderPublicSharePageRow(page)).join('')
            : `<div class="public-share-empty">${esc(t('share.public.noDescendants'))}</div>`;
        const slug = `<div class="public-share-slug"><label for="publicShareSlug">${esc(t('share.public.slugLabel'))}</label><input id="publicShareSlug" type="text" value="${esc(state.publicShareDraftSlug)}" minlength="3" maxlength="80" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" autocomplete="off" autocapitalize="none" spellcheck="false" ${publicShare.slug_locked || state.publicShareSaving ? 'readonly' : ''} aria-describedby="publicShareSlugHelp"><small id="publicShareSlugHelp">${esc(t(publicShare.slug_locked ? 'share.public.slugLockedHelp' : 'share.public.slugHelp'))}</small></div>`;
        const url = publicShare.enabled && publicShare.public_url
            ? `<div class="public-share-url"><span>${esc(t('share.public.urlLabel'))}</span><a class="public-share-url-link" href="${esc(publicShare.public_url)}" target="_blank" rel="noopener noreferrer" title="${esc(publicShare.public_url)}">${esc(publicShare.public_url)}</a><div class="public-share-url-actions"><button type="button" class="btn secondary compact" data-action="copy-public-link" ${state.publicShareSaving ? 'disabled' : ''}>${svg('copy', 'small')} ${esc(t('share.public.copyUrl'))}</button><a class="btn secondary compact" href="${esc(publicShare.public_url)}" target="_blank" rel="noopener noreferrer">${svg('external', 'small')} ${esc(t('share.public.openUrl'))}</a></div></div>`
            : '';
        const statusKey = `share.public.status.${publicShare.publication_state}`;
        const publishedMeta = publicShare.enabled
            ? `<div class="public-share-published-meta">${publicShare.published_at ? `<span>${esc(t('share.public.lastPublished', { time: timeAgo(publicShare.published_at) }))}</span>` : ''}<span>${esc(t('share.public.publishedCount', { count: publicShare.published_page_count }))}</span></div>`
            : '';
        const hiddenWarning = publicShare.hidden_selected_count > 0
            ? `<p class="public-share-warning">${esc(t('share.public.hiddenSelection', { count: publicShare.hidden_selected_count }))}</p>`
            : '';
        const publishableWarning = !publicShare.root_publishable
            ? `<p class="public-share-error" role="alert">${esc(t('share.public.rootNotPublishable'))}</p>`
            : unavailableSelection
                ? `<p class="public-share-error" role="alert">${esc(t('share.public.selectionNotPublishable'))}</p>`
                : '';
        const integrityWarning = publicShare.publication_state === 'modified'
            ? `<div class="public-share-integrity-warning" role="alert"><strong>${esc(t('share.public.artifactModifiedTitle'))}</strong><p>${esc(t('share.public.artifactModifiedHelp'))}</p></div>`
            : publicShare.publication_state === 'missing'
                ? `<div class="public-share-integrity-warning" role="alert"><strong>${esc(t('share.public.artifactMissingTitle'))}</strong><p>${esc(t('share.public.artifactMissingHelp'))}</p></div>`
                : publicShare.publication_state === 'failed'
                    ? `<div class="public-share-warning" role="status">${esc(t('share.public.failedHelp'))}</div>`
                    : '';
        let actions;
        if (publicShare.recovery_pending) {
            actions = `<button type="button" class="btn secondary compact" data-action="retry-public-share" ${state.publicShareLoading || state.publicShareSaving ? 'disabled' : ''}>${esc(t('share.public.retry'))}</button>`;
        } else if (!publicShare.enabled) {
            actions = `<button type="button" class="btn primary compact" data-action="start-public-share" ${canPersist ? '' : 'disabled'}>${esc(state.publicShareSaving ? t('share.public.publishing') : t('share.public.publishStaticSite'))}</button>`;
        } else {
            const needsRefresh = ['stale', 'failed'].includes(publicShare.publication_state);
            const updateAction = selectionChanged ? 'save-public-share' : 'refresh-public-share';
            const updateEnabled = selectionChanged ? canPersist && publicShare.can_update : needsRefresh && canPersist && publicShare.can_update;
            const updateLabel = state.publicShareSaving
                ? t('share.public.publishing')
                : publicShare.publication_state === 'publishing'
                    ? t('share.public.publishing')
                    : (selectionChanged || needsRefresh ? t('share.public.updateSameUrl') : t('share.public.upToDate'));
            actions = `<button type="button" class="btn danger compact" data-action="stop-public-share" ${state.publicShareSaving || integrityBlocked || !publicShare.can_stop ? 'disabled' : ''}>${esc(t('share.public.stop'))}</button><button type="button" class="btn primary compact" data-action="${updateAction}" ${updateEnabled ? '' : 'disabled'}>${esc(updateLabel)}</button>`;
        }

        return `<section class="public-share-section ${publicShare.enabled ? 'enabled' : ''}" aria-labelledby="publicShareHeading" aria-busy="${state.publicShareSaving ? 'true' : 'false'}"><div class="public-share-heading"><span class="public-share-heading-icon">${svg('globe', 'small')}</span><div><h3 id="publicShareHeading">${esc(t('share.public.title'))}</h3><p>${esc(t('share.public.description'))}</p></div><span class="public-share-state ${esc(publicShare.publication_state)}" role="status">${esc(t(statusKey))}</span></div>${slug}${url}${publishedMeta}${integrityWarning}<fieldset class="public-share-pages" ${integrityBlocked ? 'disabled' : ''}><legend>${esc(t('share.public.pagesHeading'))}</legend><div class="public-share-selection-toolbar"><span>${esc(t('share.public.selectedCount', { count: selectedVisibleCount }))}</span><div><button type="button" class="btn secondary compact" data-action="select-all-public-pages" ${state.publicShareSaving || integrityBlocked ? 'disabled' : ''}>${esc(t('share.public.selectAll'))}</button><button type="button" class="btn secondary compact" data-action="clear-public-pages" ${state.publicShareSaving || integrityBlocked ? 'disabled' : ''}>${esc(t('share.public.clearAll'))}</button></div></div><div class="public-share-page-list">${renderPublicSharePageRow(rootPage, true)}${descendants}</div><p class="public-share-help">${esc(t('share.public.futurePagesHelp'))}</p></fieldset>${hiddenWarning}${publishableWarning}${state.publicShareError ? `<div class="public-share-error" role="alert">${esc(state.publicShareError)}</div>` : ''}<div class="public-share-actions">${actions}</div></section>`;
    }

    function renderShareDepartmentSetting() {
        const accessDepartments = normalizeAccessDepartments(state.page.access_departments);
        const selected = new Set(accessDepartments);
        const departments = availableDepartments(accessDepartments);
        const options = departments.length
            ? departments.map((department, index) => {
                const memberCount = state.members.filter(member => trimDepartmentName(member.department) === department).length;
                const inputId = `shareDepartment${index}`;
                return `<label class="share-department-option" for="${inputId}"><input id="${inputId}" type="checkbox" data-change="access-department" value="${esc(department)}" ${selected.has(department) ? 'checked' : ''} aria-describedby="shareDepartmentHelp"><span class="share-department-option-name">${esc(department)}</span><span class="share-department-option-count">${esc(t('share.memberCount', { count: memberCount }))}</span></label>`;
            }).join('')
            : `<p class="share-department-empty">${esc(t('share.noDepartments'))}</p>`;
        const selectedNames = accessDepartments.length ? accessDepartments.map(esc).join(', ') : esc(t('share.noneSelected'));
        return `<fieldset class="share-department-setting" aria-describedby="shareDepartmentSummary shareDepartmentHelp"><legend>${esc(t('share.targetDepartments'))}</legend><div class="share-department-list">${options}</div><div class="share-department-summary" id="shareDepartmentSummary" role="status" aria-live="polite"><strong>${esc(t('share.departmentsSelected', { count: accessDepartments.length }))}</strong><span>${selectedNames}</span></div><p class="share-department-help" id="shareDepartmentHelp">${esc(t('share.departmentHelp'))}</p></fieldset>`;
    }

    function renderShareDialog(close) {
        const departmentSetting = state.page.visibility === 'department' ? renderShareDepartmentSetting() : '';
        const names = visibilityNames();
        const help = visibilityHelpText();
        return `<div class="dialog-backdrop" data-action="backdrop"><section class="dialog wide share-dialog" role="dialog" aria-modal="true" aria-labelledby="shareDialogTitle"><header class="dialog-header"><div class="dialog-title" id="shareDialogTitle">${esc(t('share.title', { title: state.page.title }))}</div>${close}</header><div class="dialog-body"><section class="member-share-section" aria-labelledby="memberShareHeading"><div class="member-heading" id="memberShareHeading">${esc(t('share.internalHeading'))}</div><div class="share-access"><span class="share-access-icon">${svg(state.page.visibility === 'company' ? 'globe' : 'lock')}</span><div class="share-access-meta"><strong>${esc(names[state.page.visibility])}</strong><span>${esc(help[state.page.visibility])}</span></div><label class="sr-only" for="shareVisibility">${esc(t('share.accessScope'))}</label><select class="select" id="shareVisibility" data-change="visibility">${Object.entries(names).map(([value, label]) => `<option value="${value}" ${value === state.page.visibility ? 'selected' : ''}>${esc(label)}</option>`).join('')}</select></div>${departmentSetting}<div class="member-list"><div class="member-heading">${esc(t('share.memberAccess'))}</div>${state.members.map(renderShareMember).join('')}</div></section>${renderPublicShareSetting()}</div><footer class="dialog-footer"><button class="btn secondary" data-action="copy-link">${svg('copy', 'small')} ${esc(t('share.copyLink'))}</button><button class="btn primary" data-action="close-dialog">${esc(t('common.done'))}</button></footer></section></div>`;
    }

    function translationDescendants() {
        return Array.isArray(state.page?.translation?.descendants) ? state.page.translation.descendants : [];
    }

    function normalizedTranslationSelectedIds(ids = state.dialogData?.selected_page_ids) {
        const descendants = translationDescendants();
        const byId = new Map(descendants.map(page => [Number(page.page_id), page]));
        const selected = new Set();
        for (const value of Array.isArray(ids) ? ids : []) {
            let pageId = Number(value || 0);
            const visited = new Set();
            while (byId.has(pageId) && !visited.has(pageId)) {
                visited.add(pageId);
                selected.add(pageId);
                pageId = Number(byId.get(pageId)?.parent_page_id || 0);
            }
        }
        return descendants.map(page => Number(page.page_id)).filter(pageId => selected.has(pageId));
    }

    function updateTranslationPageSelection(pageId, checked) {
        const descendants = translationDescendants();
        const byId = new Map(descendants.map(page => [Number(page.page_id), page]));
        const selected = new Set(normalizedTranslationSelectedIds());
        if (checked) {
            let cursor = Number(pageId || 0);
            const visited = new Set();
            while (byId.has(cursor) && !visited.has(cursor)) {
                visited.add(cursor);
                selected.add(cursor);
                cursor = Number(byId.get(cursor)?.parent_page_id || 0);
            }
        } else {
            const remove = new Set([Number(pageId || 0)]);
            let changed = true;
            while (changed) {
                changed = false;
                for (const page of descendants) {
                    const id = Number(page.page_id);
                    if (!remove.has(id) && remove.has(Number(page.parent_page_id || 0))) {
                        remove.add(id);
                        changed = true;
                    }
                }
            }
            for (const id of remove) selected.delete(id);
        }
        state.dialogData = { ...(state.dialogData || {}), selected_page_ids: normalizedTranslationSelectedIds([...selected]) };
    }

    function renderTranslationDialog(close) {
        const overview = state.page?.translation || {};
        const versions = Array.isArray(overview.versions) ? overview.versions : [];
        const descendants = translationDescendants();
        const providers = Array.isArray(overview.providers) ? overview.providers : [];
        const availableProviders = providers.filter(provider => provider.available);
        const languages = availableContentLanguages(overview.source_language || state.page?.language_code || '');
        const targetOptions = languages.filter(language => language.code !== overview.source_language);
        const statusRows = languages.map(language => {
            const version = versions.find(item => item.language === language.code);
            const status = version?.role === 'original'
                ? t('page.translation.original')
                : version
                    ? (version.outdated ? `⚠ ${t('page.translation.outdated')}` : t('page.translation.translated'))
                    : t('page.translation.notTranslated');
            return `<div class="translation-status-row"><span>${esc(language.name)}</span><strong>${esc(status)}</strong>${version ? `<button class="btn secondary compact" data-action="open-page" data-id="${Number(version.page_id)}">${svg('external', 'tiny')}</button>` : ''}</div>`;
        }).join('');
        const selectedTarget = state.dialogData?.target_language || targetOptions[0]?.code || '';
        const defaultProvider = availableProviders.find(provider => provider.id === overview.default_provider_id);
        const selectedProvider = state.dialogData?.provider || defaultProvider?.id || availableProviders[0]?.id || '';
        const selectedPageIds = normalizedTranslationSelectedIds();
        const selectedPageIdSet = new Set(selectedPageIds);
        const existing = versions.find(version => version.language === selectedTarget && version.role === 'translation');
        const descendantHasExisting = descendants.some(page => selectedPageIdSet.has(Number(page.page_id))
            && Array.isArray(page.translations)
            && page.translations.some(version => version.language === selectedTarget));
        const hasExisting = Boolean(existing || descendantHasExisting);
        const actionLabel = selectedPageIds.length
            ? t(hasExisting ? 'page.translation.retranslateStructure' : 'page.translation.createStructure')
            : t(existing ? 'page.translation.retranslate' : 'page.translation.create');
        const unavailable = availableProviders.length ? '' : `<div class="translation-unavailable"><strong>${esc(t('page.translation.providerRequiredTitle'))}</strong><p>${esc(t('page.translation.unavailable'))}</p>${state.capabilities.can_manage_system_settings ? `<button type="button" class="btn secondary compact" data-action="open-translation-provider-settings">${esc(t('page.translation.openProviderSettings'))}</button>` : ''}</div>`;
        const progress = state.translationBusy ? `<div class="translation-progress" role="status" aria-live="polite"><span class="translation-progress-spinner" aria-hidden="true"></span><strong>${esc(t('page.translation.inProgress'))}</strong></div>` : '';
        const hierarchy = descendants.length ? `<section class="translation-tree" aria-labelledby="translationTreeHeading"><div class="translation-tree-heading"><div><strong id="translationTreeHeading">${esc(t('page.translation.structureTitle'))}</strong><span>${esc(t('page.translation.structureHelp'))}</span></div><div class="translation-tree-actions"><button type="button" class="btn secondary compact" data-action="translation-select-all" ${state.translationBusy ? 'disabled' : ''}>${esc(t('page.translation.selectAll'))}</button><button type="button" class="btn secondary compact" data-action="translation-root-only" ${state.translationBusy ? 'disabled' : ''}>${esc(t('page.translation.rootOnly'))}</button></div></div><div class="translation-tree-list"><label class="translation-tree-row root" style="--translation-depth:0"><input type="checkbox" checked disabled><span>${esc(state.page.icon || '📄')} ${esc(state.page.title)}</span><small>${esc(t('page.translation.rootPage'))}</small></label>${descendants.map(page => { const pageId = Number(page.page_id); const translated = Array.isArray(page.translations) && page.translations.some(version => version.language === selectedTarget); return `<label class="translation-tree-row" style="--translation-depth:${Math.max(1, Math.min(32, Number(page.depth || 1)))}"><input type="checkbox" class="translation-page-toggle" data-page-id="${pageId}" ${selectedPageIdSet.has(pageId) ? 'checked' : ''} ${state.translationBusy ? 'disabled' : ''}><span>${esc(page.icon || '📄')} ${esc(page.title || '')}</span>${translated ? `<small>${esc(t('page.translation.translated'))}</small>` : ''}</label>`; }).join('')}</div><div class="translation-tree-count">${esc(t('page.translation.selectedCount', { count: selectedPageIds.length + 1 }))}</div></section>` : '';
        return `<div class="dialog-backdrop" data-action="backdrop"><section class="dialog translation-dialog" role="dialog" aria-modal="true"><header class="dialog-header"><div class="dialog-title">${esc(t('page.translate'))}</div>${close}</header><div class="dialog-body">${progress}<div class="translation-status-list">${statusRows}</div><div class="setting-row"><span class="setting-icon">文</span><div class="setting-meta"><div class="setting-label">${esc(t('common.language'))}</div></div><select class="select" id="translationTarget" ${state.translationBusy ? 'disabled' : ''}>${targetOptions.map(language => `<option value="${esc(language.code)}" ${language.code === selectedTarget ? 'selected' : ''}>${esc(language.name)}</option>`).join('')}</select></div><div class="setting-row"><span class="setting-icon">AI</span><div class="setting-meta"><div class="setting-label">${esc(t('page.translation.provider'))}</div></div><select class="select" id="translationProvider" ${availableProviders.length && !state.translationBusy ? '' : 'disabled'}>${availableProviders.map(provider => `<option value="${esc(provider.id)}" ${provider.id === selectedProvider ? 'selected' : ''}>${esc(provider.label)}</option>`).join('')}</select></div>${unavailable}${hierarchy}</div><footer class="dialog-footer"><button class="btn secondary" data-action="close-dialog" ${state.translationBusy ? 'disabled' : ''}>${esc(t('common.cancel'))}</button><button class="btn primary" data-action="execute-translation" ${availableProviders.length && selectedTarget && selectedProvider && !state.translationBusy ? '' : 'disabled'}>${state.translationBusy ? '…' : esc(actionLabel)}</button></footer></section></div>`;
    }

    function renderInviteDepartmentFields() {
        return `<div class="form-field"><label for="inviteDepartment">${esc(t('settings.department'))}</label><select class="input" id="inviteDepartment" name="department_choice" required><option value="" selected disabled>${esc(t('settings.selectDepartment'))}</option>${departmentOptions()}<option value="__add_new_department__">＋ ${esc(t('settings.addDepartment'))}</option></select><small class="field-help">${esc(t('settings.departmentHelp'))}</small></div><div class="form-field invite-new-department" id="inviteNewDepartmentField" hidden><label for="inviteNewDepartment">${esc(t('settings.newDepartmentName'))}</label><input class="input" id="inviteNewDepartment" name="new_department" maxlength="120" placeholder="${esc(t('settings.newDepartmentPlaceholder'))}" disabled></div>`;
    }

    function renderDialog() {
        if (!state.dialog) return '';
        const close = `<button type="button" class="btn icon-only" data-action="close-dialog" aria-label="${esc(t('common.close'))}">${svg('close')}</button>`;
        if (state.dialog === 'ai-search') {
            return renderAiSearchDialog();
        }
        if (state.dialog === 'share') {
            return renderShareDialog(close);
        }
        if (state.dialog === 'translation') {
            return renderTranslationDialog(close);
        }
        if (state.dialog === 'history') {
            const revisions = state.page.revisions || [];
            const canRestore = ['admin', 'content_admin'].includes(state.user?.role);
            return `<div class="dialog-backdrop" data-action="backdrop"><section class="dialog"><header class="dialog-header"><div class="dialog-title">${esc(t('page.history'))}</div>${close}</header><div class="dialog-body"><p class="dialog-intro">${esc(t(canRestore ? 'page.historyIntroCanRestore' : 'page.historyIntroReadOnly'))}</p><div class="history-list">${revisions.length ? revisions.map(revision => `<div class="history-item">${commentAvatar(revision, 'sm')}<div class="history-info"><strong>${esc(revision.title)}</strong><span>${esc(revision.user_name)} · ${timeAgo(revision.created_at)}</span></div>${canRestore ? `<button class="btn secondary compact" data-action="restore-revision" data-id="${revision.id}">${esc(t('page.restoreVersion'))}</button>` : ''}</div>`).join('') : `<div class="history-empty">${esc(t('page.historyEmpty'))}</div>`}</div></div></section></div>`;
        }
        if (state.dialog === 'metadata') {
            const automaticTags = (state.page.generated_tags || []).filter(tag => !(state.page.manual_tags || []).some(manual => normalizePageTag(manual) === normalizePageTag(tag)));
            const pageLanguageOptions = availableContentLanguages(state.page.language_code);
            return `<div class="dialog-backdrop" data-action="backdrop"><section class="dialog"><header class="dialog-header"><div class="dialog-title">${esc(t('page.properties'))}</div>${close}</header><div class="dialog-body">
                <div class="setting-row"><span class="setting-icon">${svg('clock')}</span><div class="setting-meta"><div class="setting-label">${esc(t('common.status'))}</div><div class="setting-help">${esc(t('page.statusHelp'))}</div></div><select class="select" id="metaStatus">${Object.entries(statusNames()).filter(([value]) => value !== 'archived').map(([value, label]) => `<option value="${value}" ${value === state.page.status ? 'selected' : ''}>${esc(label)}</option>`).join('')}</select></div>
                <div class="setting-row"><span class="setting-icon">文</span><div class="setting-meta"><div class="setting-label">${esc(t('common.language'))}</div><div class="setting-help">${esc(t('page.contentLanguageHelp'))}</div></div><select class="select" id="metaLanguage" ${state.page.source_page_id ? 'disabled' : ''}><option value="und" ${state.page.language_code === 'und' ? 'selected' : ''}>${esc(t('common.unspecified'))}</option>${pageLanguageOptions.map(language => `<option value="${esc(language.code)}" ${language.code === state.page.language_code ? 'selected' : ''}>${esc(language.name)}</option>`).join('')}</select></div>
                <div class="setting-row"><span class="setting-icon">#</span><div class="setting-meta"><div class="setting-label">${esc(t('page.category'))}</div><div class="setting-help">${esc(t('page.categoryHelp'))}</div></div><input class="input" id="metaCategory" value="${esc(state.page.category)}" style="width:170px;height:32px"></div>
                <div class="setting-row"><span class="setting-icon">⌁</span><div class="setting-meta"><div class="setting-label">${esc(t('page.manualTags'))}</div><div class="setting-help">${esc(t('page.manualTagsHelp'))}</div></div><input class="input" id="metaTags" value="${esc((state.page.manual_tags || state.page.tags || []).join(', '))}" style="width:210px;height:32px"></div>
                ${automaticTags.length ? `<div class="setting-row"><span class="setting-icon">AI</span><div class="setting-meta"><div class="setting-label">${esc(t('page.aiTags'))}</div><div class="setting-help">${esc(t('page.aiTagsHelp'))}</div></div><div>${automaticTags.map(tag => `<span class="tag-pill">#${esc(tag)}</span>`).join(' ')}</div></div>` : ''}
                <div class="setting-row"><span class="setting-icon">${svg('comment')}</span><div class="setting-meta"><div class="setting-label">${esc(t('common.comments'))}</div><div class="setting-help">${esc(t('page.commentsHelp'))}</div></div><button class="toggle ${state.page.comments_enabled ? 'on' : ''}" data-action="toggle-comments-setting" aria-label="${esc(t('page.commentsSetting'))}"></button></div>
            </div><footer class="dialog-footer"><button class="btn secondary" data-action="close-dialog">${esc(t('common.cancel'))}</button><button class="btn primary" data-action="save-metadata">${esc(t('common.save'))}</button></footer></section></div>`;
        }
        if (state.dialog === 'cover') {
            return `<div class="dialog-backdrop" data-action="backdrop"><section class="dialog"><header class="dialog-header"><div class="dialog-title">${esc(t('page.cover.select'))}</div>${close}</header><div class="dialog-body"><div class="color-grid">${Object.entries(coverNames()).map(([value, label]) => `<button class="color-option ${value} ${value === state.page.cover ? 'active' : ''}" data-action="choose-cover" data-value="${value}" data-name="${esc(label)}"></button>`).join('')}</div></div></section></div>`;
        }
        if (state.dialog === 'icon') {
            const choices = ['✦','📄','◉','↗','◫','⌂','☕','◷','🌱','💡','🧭','🎯','🪴','📝','📚','🧩','🛠️','🔎','🎨','📌','✅','🤝','🚀','🔐'];
            return `<div class="dialog-backdrop" data-action="backdrop"><section class="dialog"><header class="dialog-header"><div class="dialog-title">${esc(t('page.icon.select'))}</div>${close}</header><div class="dialog-body"><div class="icon-grid">${choices.map(icon => `<button class="icon-option" data-action="choose-icon" data-value="${esc(icon)}">${esc(icon)}</button>`).join('')}</div></div></section></div>`;
        }
        if (state.dialog === 'inbox') {
            return `<div class="dialog-backdrop" data-action="backdrop"><section class="dialog inbox-dialog"><header class="dialog-header"><div class="dialog-title">${esc(t('navigation.inbox'))}${unreadCount() ? `<span class="dialog-count">${unreadCount()}</span>` : ''}</div>${close}</header><div class="dialog-body inbox-body">${renderInboxBody()}</div><footer class="dialog-footer inbox-footer"><button type="button" class="btn secondary" data-action="refresh-notifications">${esc(t('inbox.refresh'))}</button><button type="button" class="btn secondary" data-action="mark-read" ${unreadCount() ? '' : 'disabled'}>${esc(t('inbox.markAllRead'))}</button></footer></section></div>`;
        }
        if (state.dialog === 'about-openconcept') {
            return `<div class="dialog-backdrop" data-action="backdrop"><section class="dialog about-dialog" role="dialog" aria-modal="true" aria-labelledby="aboutOpenConceptTitle"><header class="dialog-header"><div class="dialog-title" id="aboutOpenConceptTitle">${esc(t('about.title'))}</div>${close}</header><div class="dialog-body about-body"><div class="about-brand">${brandLogo('about-logo', 'OpenConcept')}<strong>OpenConcept</strong></div><p>CopyRight 2026 : Shinna Co.,Ltd, in Ho Chi Minh City</p><div class="about-version">version ${esc(appVersion)}</div></div><footer class="dialog-footer"><button type="button" class="btn primary" data-action="close-dialog">${esc(t('common.close'))}</button></footer></section></div>`;
        }
        if (state.dialog === 'profile') {
            const draft = state.dialogData || {};
            const preview = draft.photo_preview
                ? `<span class="avatar profile avatar-photo"><img src="${esc(draft.photo_preview)}" alt="${esc(t('profile.selectedPhotoAlt'))}"></span>`
                : avatarMarkup({ id: state.user.id, name: state.user.name, color: state.user.avatar_color, kind: draft.avatar_kind, value: draft.avatar_value }, 'profile');
            const categoryNames = profileCategoryNames();
            const iconGroups = Object.entries(state.profileIconChoices).map(([category, choices]) => `<section class="profile-icon-group"><h3>${esc(categoryNames[category] || category)}</h3><div class="profile-icon-grid">${choices.map(icon => `<button type="button" class="profile-icon-option ${draft.avatar_kind === 'emoji' && draft.avatar_value === icon ? 'active' : ''}" data-action="choose-profile-icon" data-value="${esc(icon)}" aria-label="${esc(t('profile.selectIcon', { icon }))}" aria-pressed="${draft.avatar_kind === 'emoji' && draft.avatar_value === icon}">${esc(icon)}</button>`).join('')}</div></section>`).join('');
            const securitySettings = `<section class="profile-security-settings"><div><strong>${esc(t('profile.password.title'))}</strong><span>${esc(t('profile.password.help'))}</span></div><button type="button" class="btn secondary compact" data-action="open-password-change">${svg('lock', 'small')} ${esc(t('profile.password.open'))}</button></section>`;
            return `<div class="dialog-backdrop" data-action="backdrop"><section class="dialog wide profile-dialog" role="dialog" aria-modal="true" aria-labelledby="profileDialogTitle"><header class="dialog-header"><div class="dialog-title" id="profileDialogTitle">${esc(t('profile.title'))}</div>${close}</header><div class="dialog-body profile-body"><section class="profile-summary">${preview}<div class="profile-identity"><strong>${esc(state.user.name)}</strong><span>${esc(state.user.email)}</span><small>${esc(roleNames()[state.user.role] || state.user.role)}</small></div></section><div class="form-field profile-department-field"><label for="profileDepartment">${esc(t('profile.department'))}</label><input class="input" id="profileDepartment" value="${esc(draft.department)}" readonly aria-readonly="true"><small>${esc(t('profile.departmentHelp'))}</small></div><section class="profile-avatar-settings"><div class="profile-setting-heading"><div><strong>${esc(t('profile.icon'))}</strong><span>${esc(t('profile.iconHelp'))}</span></div><div class="profile-avatar-actions"><button type="button" class="btn secondary compact ${draft.avatar_kind === 'initials' ? 'active' : ''}" data-action="choose-profile-initials">${esc(t('profile.initialsIcon'))}</button><button type="button" class="btn secondary compact ${draft.avatar_kind === 'photo' ? 'active' : ''}" data-action="choose-profile-photo">${svg('image', 'small')} ${esc(t('profile.choosePhoto'))}</button><input id="profilePhotoInput" type="file" accept="image/png,image/jpeg,image/webp" hidden></div></div><p class="profile-photo-hint">${esc(t('profile.photoLimit', { size: state.profilePhotoLimitMb }))}</p>${iconGroups}</section>${securitySettings}<div id="profileError" class="login-error profile-error" role="alert"></div></div><footer class="dialog-footer"><button type="button" class="btn secondary profile-logout" data-action="logout">${svg('logout', 'small')} ${esc(t('common.logout'))}</button><button type="button" class="btn secondary" data-action="close-dialog">${esc(t('common.cancel'))}</button><button type="button" class="btn primary" data-action="save-profile">${esc(t('common.save'))}</button></footer></section></div>`;
        }
        if (state.dialog === 'password-change') {
            return `<div class="dialog-backdrop" data-action="backdrop"><section class="dialog" role="dialog" aria-modal="true" aria-labelledby="ownPasswordChangeTitle"><header class="dialog-header"><div class="dialog-title" id="ownPasswordChangeTitle">${esc(t('profile.password.title'))}</div>${close}</header><div class="dialog-body"><p class="dialog-intro">${esc(t('profile.password.description'))}</p><form id="ownPasswordChangeForm"><div class="form-field"><label for="currentPassword">${esc(t('profile.password.current'))}</label><input class="input" id="currentPassword" name="current_password" type="password" autocomplete="current-password" required></div><div class="form-field"><label for="ownNewPassword">${esc(t('profile.password.new'))}</label><input class="input" id="ownNewPassword" name="password" type="password" autocomplete="new-password" placeholder="${esc(t('setup.passwordMinimum'))}" required></div><div class="form-field"><label for="ownPasswordConfirmation">${esc(t('profile.password.confirmation'))}</label><input class="input" id="ownPasswordConfirmation" name="password_confirmation" type="password" autocomplete="new-password" required></div><div class="password-requirements">${esc(t('passwordChange.requirements'))}</div><div id="ownPasswordChangeError" class="login-error" role="alert"></div></form></div><footer class="dialog-footer"><button type="button" class="btn secondary" data-action="close-dialog">${esc(t('common.cancel'))}</button><button type="button" class="btn primary" data-action="submit-password-change">${esc(t('profile.password.submit'))}</button></footer></section></div>`;
        }
        if (state.dialog === 'database-connection-status') {
            const details = state.dialogData || {};
            const connection = details.connection_info && typeof details.connection_info === 'object'
                ? details.connection_info
                : {};
            const backend = connection.backend === 'mysql'
                ? 'MySQL'
                : (connection.backend === 'postgresql' ? 'PostgreSQL' : String(connection.backend || ''));
            const methodKey = ['environment', 'adapter_managed', 'native_sqlite'].includes(connection.method)
                ? connection.method
                : 'environment';
            const source = String(connection.source || '');
            const environmentVariable = String(connection.environment_variable || '');
            const sourceLabel = source === 'process_environment'
                ? t('plugins.connection.source.processEnvironment')
                : source === 'adapter_state'
                    ? t('plugins.connection.source.adapterState')
                    : source === 'native_sqlite'
                        ? t('plugins.connection.source.nativeSqlite')
                        : t('plugins.connection.source.file', { file: source, variable: environmentVariable });
            const rows = [
                [t('plugins.connection.backend'), backend],
                [t('plugins.connection.method'), t(`plugins.connection.method.${methodKey}`)],
                [t('plugins.connection.source'), sourceLabel],
                [t('plugins.connection.host'), connection.host],
                [t('plugins.connection.port'), connection.port],
                [t('plugins.connection.database'), connection.database],
                [t('plugins.connection.tablePrefix'), connection.table_prefix],
                [t('plugins.connection.sslMode'), connection.sslmode],
            ].filter(([, value]) => value !== '' && value !== null && value !== undefined);
            return `<div class="dialog-backdrop" data-action="backdrop"><section class="dialog database-connection-dialog" role="dialog" aria-modal="true" aria-labelledby="databaseConnectionDialogTitle"><header class="dialog-header"><div class="dialog-title" id="databaseConnectionDialogTitle">${esc(t('plugins.connection.title'))}</div>${close}</header><div class="dialog-body"><div class="database-connection-heading"><span class="database-connection-indicator" aria-hidden="true"></span><div><strong>${esc(t('plugins.connection.connected'))}</strong><span>${esc(details.plugin_name || backend)}</span></div></div><p class="dialog-intro database-connection-intro">${esc(t('plugins.connection.readOnlyHelp'))}</p><div class="database-integrity-policy" role="note">${svg('info', 'small')}<span>${esc(t('plugins.connection.integrityPolicy'))}</span></div><dl class="database-connection-grid">${rows.map(([label, value]) => `<div><dt>${esc(label)}</dt><dd>${esc(String(value))}</dd></div>`).join('')}</dl><p class="database-connection-security">${esc(t('plugins.connection.secretHelp'))}</p></div><footer class="dialog-footer"><button type="button" class="btn primary" data-action="back-to-plugin-settings">${esc(t('plugins.connection.backToSettings'))}</button></footer></section></div>`;
        }
        if (state.dialog === 'members') {
            const activeAdministratorCount = state.members.filter(member => member.role === 'admin').length;
            const administratorWarning = state.capabilities.can_manage_members && activeAdministratorCount < 2
                ? `<div class="admin-continuity-warning" role="status">${svg('info', 'small')}<span><strong>${esc(t('settings.adminWarningTitle'))}</strong>${esc(t('settings.adminWarningBody'))}</span></div>`
                : '';
            const suspendedAccounts = state.capabilities.can_manage_members && state.suspendedMembers.length
                ? `<section class="suspended-member-list"><div class="member-heading">${esc(t('settings.suspendedAccounts'))}</div><p class="suspended-member-help">${esc(t('settings.suspendedAccountsHelp'))}</p>${state.suspendedMembers.map(renderSuspendedMember).join('')}</section>`
                : '';
            return `<div class="dialog-backdrop" data-action="backdrop"><section class="dialog wide"><header class="dialog-header"><div class="dialog-title">${esc(t('common.members'))}</div>${state.capabilities.can_manage_members ? `<button class="btn primary compact" data-action="invite-member">${svg('plus', 'small')} ${esc(t('settings.addMember'))}</button>` : ''}${close}</header><div class="dialog-body"><p class="dialog-intro">${esc(t('settings.workspaceSummary', { workspace: state.workspaceName, count: state.members.length }))}</p>${administratorWarning}<div class="member-heading">${esc(t('common.members'))}</div>${state.members.map(renderSettingsMember).join('')}${suspendedAccounts}</div></section></div>`;
        }
        if (state.dialog === 'member-name') {
            const member = state.dialogData || {};
            return `<div class="dialog-backdrop" data-action="backdrop"><section class="dialog" role="dialog" aria-modal="true" aria-labelledby="memberNameDialogTitle"><header class="dialog-header"><div class="dialog-title" id="memberNameDialogTitle">${esc(t('settings.editMemberNameTitle'))}</div><button class="icon-btn" type="button" data-action="cancel-member-name" aria-label="${esc(t('common.close'))}">${svg('close')}</button></header><div class="dialog-body"><p class="dialog-intro">${esc(t('settings.editMemberNameHelp'))}</p><form id="memberNameForm"><div class="form-field"><label for="memberNameInput">${esc(t('settings.fullName'))}</label><input class="input" id="memberNameInput" name="name" maxlength="120" value="${esc(member.name || '')}" required></div><div class="member-email">${esc(member.email || '')}</div><div id="memberNameError" class="login-error" role="alert"></div></form></div><footer class="dialog-footer"><button type="button" class="btn secondary" data-action="cancel-member-name">${esc(t('common.cancel'))}</button><button type="button" class="btn primary" data-action="save-member-name">${esc(t('common.save'))}</button></footer></section></div>`;
        }
        if (state.dialog === 'invite-member') {
            return `<div class="dialog-backdrop" data-action="backdrop"><section class="dialog"><header class="dialog-header"><div class="dialog-title">${esc(t('settings.addMember'))}</div>${close}</header><div class="dialog-body"><p class="dialog-intro">${esc(t('settings.inviteDescription'))}</p><form id="inviteForm"><div class="form-field"><label for="inviteName">${esc(t('settings.fullName'))}</label><input class="input" id="inviteName" name="name" placeholder="${esc(t('settings.fullNamePlaceholder'))}" required></div><div class="form-field"><label for="inviteEmail">${esc(t('auth.email'))}</label><input class="input" id="inviteEmail" name="email" type="email" placeholder="member@company.jp" required></div>${renderInviteDepartmentFields()}<div class="form-field"><label for="inviteRole">${esc(t('settings.roleLabel'))}</label><select class="input" id="inviteRole" name="role" data-change="invite-role">${Object.entries(roleNames()).filter(([value]) => value !== 'suspended').map(([value, label]) => `<option value="${value}" ${value === 'editor' ? 'selected' : ''}>${esc(label)}</option>`).join('')}</select></div><div class="form-field" id="inviteAdministratorPasswordField" hidden><label for="inviteAdministratorPassword">${esc(t('settings.adminAction.currentPassword'))}</label><input class="input" id="inviteAdministratorPassword" name="current_password" type="password" autocomplete="current-password"><small>${esc(t('settings.inviteAdminPasswordHelp'))}</small></div><div class="role-permission-guide">${Object.entries(roleNames()).filter(([value]) => value !== 'suspended').map(([value, label]) => `<div><strong>${esc(label)}</strong><span>${esc(roleHelpText()[value])}</span></div>`).join('')}</div><div id="inviteError" class="login-error"></div></form></div><footer class="dialog-footer"><button class="btn secondary" data-action="close-dialog">${esc(t('common.cancel'))}</button><button class="btn primary" data-action="submit-invite">${esc(t('settings.sendInvitation'))}</button></footer></section></div>`;
        }
        if (state.dialog === 'reactivate-confirmation') {
            const request = state.dialogData || {};
            return `<div class="dialog-backdrop" data-action="backdrop"><section class="dialog" role="alertdialog" aria-modal="true" aria-labelledby="reactivateConfirmationTitle"><header class="dialog-header"><div class="dialog-title" id="reactivateConfirmationTitle">${esc(t('settings.roleChangeTitle'))}</div></header><div class="dialog-body"><p class="dialog-intro result-copy">${esc(t('settings.roleChangeBody', { name: request.member_name, role: roleNames()[request.role] || request.role }))}</p><p class="dialog-intro result-copy">${esc(t('settings.roleChangeInviteHelp'))}</p></div><footer class="dialog-footer"><button class="btn secondary" data-action="cancel-reactivation">${esc(t('common.no'))}</button><button class="btn primary" data-action="confirm-reactivation">${esc(t('common.yes'))}</button></footer></section></div>`;
        }
        if (state.dialog === 'administrator-confirmation') {
            const request = state.dialogData || {};
            const role = roleNames()[request.role] || request.role || '';
            const bodyKey = `settings.adminAction.${request.kind || 'invalid'}`;
            return `<div class="dialog-backdrop" data-action="backdrop"><section class="dialog" role="alertdialog" aria-modal="true" aria-labelledby="administratorConfirmationTitle"><header class="dialog-header"><div class="dialog-title" id="administratorConfirmationTitle">${esc(t('settings.adminAction.title'))}</div>${close}</header><div class="dialog-body"><p class="dialog-intro result-copy">${esc(t(bodyKey, { name: request.member_name || '', role }))}</p><p class="security-note">${esc(t('settings.adminAction.securityHelp'))}</p><div class="form-field"><label for="administratorCurrentPassword">${esc(t('settings.adminAction.currentPassword'))}</label><input class="input" id="administratorCurrentPassword" type="password" autocomplete="current-password" required></div><div id="administratorActionError" class="login-error" role="alert"></div></div><footer class="dialog-footer"><button type="button" class="btn secondary" data-action="close-dialog">${esc(t('common.cancel'))}</button><button type="button" class="btn primary" data-action="confirm-administrator-action">${esc(t('settings.adminAction.confirm'))}</button></footer></section></div>`;
        }
        if (state.dialog === 'invitation-result') {
            const result = state.dialogData || {};
            const resent = result.mode === 'resend';
            const reactivated = result.mode === 'reactivate';
            const reset = result.mode === 'reset';
            const titleKey = result.mail_sent
                ? (reset ? 'settings.result.resetAndSent' : (reactivated ? 'settings.result.reactivatedAndSent' : (resent ? 'settings.result.resent' : 'settings.result.sent')))
                : (reset ? 'settings.result.passwordReset' : (reactivated ? 'settings.result.reactivated' : (resent ? 'settings.result.passwordUpdated' : 'settings.result.memberAdded')));
            const successKey = reactivated || resent || reset ? 'settings.result.newPasswordSent' : 'settings.result.passwordSent';
            return `<div class="dialog-backdrop" data-action="backdrop"><section class="dialog"><header class="dialog-header"><div class="dialog-title">${esc(t(titleKey))}</div>${close}</header><div class="dialog-body"><div class="invite-result-icon ${result.mail_sent ? 'success' : 'warning'}">${svg(result.mail_sent ? 'check' : 'bell')}</div><p class="dialog-intro result-copy">${esc(result.mail_sent ? t(successKey, { email: result.email }) : t('settings.result.mailFailed'))}</p>${result.temporary_password ? `<div class="temporary-password"><code>${esc(result.temporary_password)}</code><button class="btn secondary compact" data-action="copy-temporary-password">${svg('copy', 'small')} ${esc(t('common.copy'))}</button></div><p class="security-note">${esc(t('settings.result.passwordOneTime'))}</p>` : ''}${result.mail_error ? `<div class="mail-warning">${esc(result.mail_error)}</div>` : ''}</div><footer class="dialog-footer"><button class="btn primary" data-action="close-dialog">${esc(t('common.done'))}</button></footer></section></div>`;
        }
        return '';
    }

    function renderAiSearchError() {
        if (!state.aiSearchError) return '';
        const details = state.aiSearchErrorDetails && typeof state.aiSearchErrorDetails === 'object'
            ? state.aiSearchErrorDetails
            : null;
        if (!details || details.source !== 'external_llm') {
            return `<div class="ai-search-error" role="alert">${svg('bell', 'small')}<div class="ai-search-error-content"><strong>${esc(state.aiSearchError)}</strong></div></div>`;
        }
        const operationKeys = {
            openconcept_page_action_plan: 'ai.errorOperation.pageAction',
            openconcept_search_expansion: 'ai.errorOperation.searchExpansion',
            openconcept_search_answer: 'ai.errorOperation.searchAnswer',
            openconcept_rag_answer: 'ai.errorOperation.searchAnswer',
            openconcept_general_answer: 'ai.errorOperation.generalAnswer',
            openconcept_page_summary: 'ai.errorOperation.pageSummary',
        };
        const operation = String(details.operation || '');
        const endpointMode = details.endpoint_mode === 'chat_completions'
            ? 'Chat Completions'
            : (details.endpoint_mode === 'responses' ? 'Responses API' : String(details.endpoint_mode || ''));
        const rows = [
            [t('ai.errorProvider'), details.provider],
            [t('ai.errorModel'), details.model],
            [t('ai.errorEndpointMode'), endpointMode],
            [t('ai.errorOperation'), operationKeys[operation] ? t(operationKeys[operation]) : operation],
            [t('ai.errorHttpStatus'), Number(details.http_status) > 0 ? String(Number(details.http_status)) : ''],
            [t('ai.errorCode'), details.failure_code],
        ].filter(([, value]) => String(value || '').trim() !== '');
        const providerMessage = String(details.provider_message || '').trim();
        return `<div class="ai-search-error detailed" role="alert">${svg('bell', 'small')}<div class="ai-search-error-content">
            <strong>${esc(state.aiSearchError)}</strong>
            ${providerMessage ? `<div class="ai-search-provider-message"><span>${esc(t('ai.errorExternalMessage'))}</span><code>${esc(providerMessage)}</code></div>` : ''}
            ${rows.length ? `<dl class="ai-search-error-details">${rows.map(([label, value]) => `<div><dt>${esc(label)}</dt><dd>${esc(String(value))}</dd></div>`).join('')}</dl>` : ''}
            <p>${esc(t('ai.errorGuidance'))}</p>
        </div></div>`;
    }

    function renderAiSearchDialog() {
        const hashPageId = Number((location.hash.match(/^#page-(\d+)$/) || [])[1] || 0);
        const loadingSummary = state.view === 'page' && !state.page && hashPageId ? pageById(hashPageId) : null;
        const currentPage = state.view === 'page' ? (state.page || loadingSummary) : null;
        const currentViewName = state.view === 'home' ? t('navigation.home') : state.view === 'files' ? t('navigation.files') : state.view === 'trash' ? t('navigation.trash') : state.view === 'settings' ? t('navigation.settings') : t('ai.pageLoading');
        const currentTitle = currentPage?.title || currentViewName;
        const currentIcon = currentPage?.icon || (state.view === 'page' ? '📄' : state.view === 'home' ? '⌂' : state.view === 'files' ? '📁' : state.view === 'settings' ? '⚙' : '🗑');
        const canSummarize = Boolean(state.view === 'page' && state.page?.id && !state.aiSearching && !state.aiConversationsLoading);
        const pageContext = `<section class="ai-page-context" aria-label="${esc(t('ai.currentMainPage'))}">
            <div class="ai-page-context-row"><span class="ai-page-context-icon">${esc(currentIcon)}</span><span class="ai-page-context-copy"><small>${esc(state.view === 'page' ? t('ai.currentPageContext') : t('ai.currentView'))}</small><strong title="${esc(currentTitle)}">${esc(currentTitle)}</strong></span></div>
        </section>`;
        const conversationOptions = state.aiConversations.map(conversation => `<option value="${Number(conversation.id)}" ${Number(conversation.id) === Number(state.aiConversationId) ? 'selected' : ''}>${esc(conversation.title || t('ai.newChat'))}</option>`).join('');
        const conversationSwitcher = `<section class="ai-chat-toolbar" aria-label="${esc(t('ai.switchChat'))}">
            <label class="ai-chat-select"><span>${esc(t('ai.chatHistory'))}</span><select id="aiConversationSelect" ${state.aiSearching || state.aiConversationsLoading ? 'disabled' : ''}><option value="0" ${state.aiConversationId ? '' : 'selected'}>${esc(t('ai.newChat'))}</option>${conversationOptions}</select></label>
        </section>`;
        const messages = state.aiMessages.map(message => {
            const answerBasis = message.answer_basis || (message.general_knowledge_fallback ? 'general_knowledge' : (message.sufficient ? 'registered_data' : 'unavailable'));
            const basisLabel = answerBasis === 'general_knowledge'
                ? t('ai.basis.generalKnowledge')
                : answerBasis === 'current_page'
                    ? t('ai.basis.currentPage')
                    : answerBasis === 'registered_data'
                        ? t('ai.basis.registeredData')
                        : t('ai.basis.insufficient');
            const sources = (message.sources || []).map((source, index) => {
                const content = `<span class="ai-source-index">${index + 1}</span>
                    <span class="ai-source-main"><strong>${esc(source.title)}</strong><span>${esc(source.summary || t('ai.referencedChunk'))}</span><code>${esc(source.document_id)} · ${esc(source.chunk_id)}</code></span>
                    ${svg('chevron', 'tiny')}`;
                return Number(source.file_id) > 0
                    ? `<a class="ai-source" href="${esc(source.url || `api.php?action=file-content&id=${Number(source.file_id)}`)}" target="_blank" rel="noopener">${content}</a>`
                    : `<button class="ai-source" type="button" data-action="open-ai-source" data-id="${Number(source.page_id)}">${content}</button>`;
            }).join('');
            const pageActions = (message.actions || []).filter(action => action?.type === 'page_created' && Number(action.page_id) > 0).map(action =>
                `<button class="ai-page-action" type="button" data-action="open-ai-action-page" data-id="${Number(action.page_id)}">${svg('external', 'small')}<span>${esc(t('ai.openCreatedPage', { title: action.title || t('page.untitled') }))}</span></button>`
            ).join('');
            return `<article class="ai-turn">
                <div class="ai-user-message"><span>${esc(message.question)}</span>${['page-summary', 'page-context', 'page-action'].includes(message.mode) && message.page_title ? `<small class="ai-message-page">${svg('edit', 'tiny')}${esc(message.page_title)}</small>` : ''}</div>
                <div class="ai-answer ${answerBasis === 'general_knowledge' ? 'general-knowledge' : (message.sufficient ? '' : 'insufficient')}">
                    <div class="ai-answer-brand">${brandLogo('ai-answer-logo', '')}<span>OpenConcept AI</span></div>
                    <div class="ai-answer-text">${esc(message.answer)}</div>
                    ${pageActions ? `<div class="ai-page-actions">${pageActions}</div>` : ''}
                    ${sources ? `<div class="ai-sources"><div class="ai-sources-title">${esc(t('ai.sources'))}</div>${sources}</div>` : ''}
                    <div class="ai-answer-meta"><strong>${esc(basisLabel)}</strong> · ${esc(t('ai.candidatesChecked', { count: Number(message.candidate_count || 0) }))} · ${esc(message.model || 'GPT-5.6 Luna')} · ${esc(message.reasoning_effort || 'low')}</div>
                </div>
            </article>`;
        }).join('');
        const pageSummarySuggestion = canSummarize ? `<button class="primary-suggestion" type="button" data-action="ai-summarize-page">${svg('edit', 'small')}<span>${esc(t('ai.summarizePage'))}</span></button>` : '';
        const empty = !messages && !state.aiSearching && !state.aiConversationsLoading
            ? `<div class="ai-search-empty"><div class="ai-search-orbit">${brandLogo('ai-empty-logo', 'OpenConcept AI')}</div><h2>${esc(t('ai.newChat'))}</h2><p>${esc(t('ai.emptyHelp'))}</p><div class="ai-suggestions">${pageSummarySuggestion}<button type="button" data-action="ai-suggestion">${esc(t('ai.suggestionCreateSummary'))}</button><button type="button" data-action="ai-suggestion">${esc(t('ai.suggestionGeneralQuestion'))}</button></div></div>`
            : '';
        const loading = state.aiSearching || state.aiConversationsLoading
            ? `<div class="ai-loading"><span class="ai-loading-logo">${brandLogo('ai-answer-logo', '')}</span><span class="ai-loading-dots"><i></i><i></i><i></i></span><span>${esc(state.aiSearching ? t('ai.generatingAnswer') : t('ai.loadingChat'))}</span></div>`
            : '';
        const error = renderAiSearchError();
        return `<div class="ai-search-backdrop" data-action="backdrop">
            <aside class="ai-search-window" aria-labelledby="aiSearchTitle">
                <header class="ai-search-header">
                    <div class="ai-search-identity">${brandLogo('ai-window-logo', 'OpenConcept')}<div><strong id="aiSearchTitle">OpenConcept AI</strong><span><i></i> ${esc(t('ai.knowledgeAndGeneral'))}</span></div></div>
                    <button class="ai-new-chat" type="button" data-action="ai-new-chat" ${state.aiSearching ? 'disabled' : ''}>${svg('plus', 'small')}<span>${esc(t('ai.new'))}</span></button>
                    <button class="ai-search-close" type="button" data-action="close-dialog" aria-label="${esc(t('ai.closeSearch'))}">${svg('close', 'small')}<span>${esc(t('common.close'))}</span></button>
                </header>
                ${conversationSwitcher}
                ${pageContext}
                <div class="ai-search-content"><div class="ai-search-plugin-slot" id="aiSearchPluginSlot"></div><div class="ai-search-thread" id="aiSearchThread">${empty}${messages}${loading}${error}</div></div>
                <form class="ai-composer" id="aiSearchForm">
                    <label class="sr-only" for="aiSearchInput">${esc(t('ai.inputLabel'))}</label>
                    <textarea id="aiSearchInput" rows="3" maxlength="1000" placeholder="${esc(t('ai.inputPlaceholder'))}" ${state.aiSearching ? 'disabled' : ''}>${esc(state.aiQuestion)}</textarea>
                    <div class="ai-composer-actions"><span>${esc(t('ai.keyboardHelp'))}</span><button class="ai-search-submit" type="submit" ${state.aiSearching || state.aiQuestion.trim().length < 2 ? 'disabled' : ''}>${svg('send', 'small')}<span>${esc(t('ai.send'))}</span></button></div>
                </form>
                <footer class="ai-search-privacy">${svg('lock', 'tiny')} ${esc(t('ai.privacy'))}</footer>
            </aside>
        </div>`;
    }

    function shareDialogFocusableElements() {
        const dialog = root.querySelector('.dialog[aria-labelledby="shareDialogTitle"]');
        if (!dialog) return [];
        return [...dialog.querySelectorAll('button:not([disabled]), select:not([disabled]), input:not([disabled]), [href], [tabindex]:not([tabindex="-1"])')]
            .filter(element => !element.hidden && element.getAttribute('aria-hidden') !== 'true');
    }

    function syncModalAccessibility() {
        if (state.dialog !== 'share') return;
        const backdrop = root.querySelector('.dialog-backdrop');
        if (!backdrop) return;
        [...root.children].forEach(child => {
            if (child === backdrop) return;
            child.inert = true;
            child.setAttribute('aria-hidden', 'true');
        });
        requestAnimationFrame(() => {
            const dialog = root.querySelector('.dialog[aria-labelledby="shareDialogTitle"]');
            if (dialog && !dialog.contains(document.activeElement)) {
                (document.getElementById('shareVisibility') || shareDialogFocusableElements()[0])?.focus();
            }
        });
    }

    function restoreShareButtonFocus() {
        requestAnimationFrame(() => root.querySelector('[data-action="share"]')?.focus());
    }

    function afterRender() {
        if (state.view === 'settings' && state.settingsTab === 'ai' && !state.settingsLoading) mountRagCoreSettings();
        else window.OpenConceptRagSettings?.unmount?.();
        const aiProviderDetails = document.getElementById('aiProviderDetails');
        if (aiProviderDetails instanceof HTMLDetailsElement) {
            aiProviderDetails.ontoggle = () => { state.aiProviderExpanded = aiProviderDetails.open; };
        }
        const scroller = document.getElementById('pageScroller');
        if (scroller) scroller.addEventListener('scroll', () => root.querySelector('.topbar')?.classList.toggle('scrolled', scroller.scrollTop > 12), { passive: true });
        if (state.editorFind.open && state.view === 'page' && state.page) {
            setTimeout(() => refreshEditorFindMatches(false, false), 0);
        }
        const aiOpen = state.dialog === 'ai-search' && !aiPanelClosing;
        syncAiSearchExtensions();
        if (aiOpen && !document.body.classList.contains('ai-search-open')) {
            requestAnimationFrame(() => {
                if (state.dialog === 'ai-search' && !aiPanelClosing) document.body.classList.add('ai-search-open');
            });
        } else if (!aiOpen) {
            document.body.classList.remove('ai-search-open');
        }
        if (aiOpen) setTimeout(() => {
            if (!state.aiSearching && !state.aiConversationsLoading) document.getElementById('aiSearchInput')?.focus();
            const thread = document.getElementById('aiSearchThread');
            if (thread) thread.scrollTop = thread.scrollHeight;
        }, 20);
        if (state.replyTo && !state.dialog) setTimeout(() => document.getElementById('commentInput')?.focus(), 20);
        syncModalAccessibility();
    }

    function syncAiSearchExtensions() {
        const open = state.dialog === 'ai-search' && !aiPanelClosing;
        const host = open ? document.getElementById('aiSearchPluginSlot') : null;
        if (!host) {
            mountedAiSearchExtensions.forEach(id => {
                try { aiSearchExtensions.get(id)?.unmount?.(); } catch (error) { console.error(`[plugin:${id}] AI extension unmount failed`, error); }
            });
            mountedAiSearchExtensions.clear();
            return;
        }
        const context = Object.freeze({
            host,
            input: document.getElementById('aiSearchInput'),
            form: document.getElementById('aiSearchForm'),
            submitButton: document.querySelector('.ai-search-submit'),
            thread: document.getElementById('aiSearchThread'),
            window: document.querySelector('.ai-search-window'),
            conversationId: Number(state.aiConversationId || 0),
            pageId: state.view === 'page' && state.page?.id ? Number(state.page.id) : 0,
            searching: state.aiSearching,
            csrf: () => state.csrf,
            submit: (question, options = {}) => submitAiQuestion(String(question || ''), options),
        });
        aiSearchExtensions.forEach((extension, id) => {
            try {
                extension.mount(context);
                mountedAiSearchExtensions.add(id);
            } catch (error) {
                console.error(`[plugin:${id}] AI extension mount failed`, error);
            }
        });
    }

    function notifyAiSearchExtensions(eventName, payload) {
        aiSearchExtensions.forEach((extension, id) => {
            try { extension[eventName]?.(payload); } catch (error) { console.error(`[plugin:${id}] AI extension ${eventName} failed`, error); }
        });
    }

    function upsertAiConversation(conversation) {
        if (!conversation?.id) return;
        state.aiConversations = [conversation, ...state.aiConversations.filter(item => Number(item.id) !== Number(conversation.id))];
    }

    function startNewAiConversation() {
        state.aiConversationRequest += 1;
        state.aiConversationId = 0;
        state.aiMessages = [];
        state.aiQuestion = '';
        state.aiSearchError = '';
        state.aiSearchErrorDetails = null;
        state.aiConversationsLoading = false;
        renderApp();
    }

    async function loadAiConversations() {
        if (state.aiConversationsLoading) return;
        state.aiConversationsLoading = true;
        state.aiSearchError = '';
        state.aiSearchErrorDetails = null;
        renderApp();
        try {
            const data = await api('ai-conversations');
            state.aiConversations = Array.isArray(data.conversations) ? data.conversations : [];
            state.aiConversationsLoaded = true;
            const selected = state.aiConversationId && state.aiConversations.some(item => Number(item.id) === Number(state.aiConversationId))
                ? Number(state.aiConversationId)
                : Number(state.aiConversations[0]?.id || 0);
            state.aiConversationsLoading = false;
            if (selected) await selectAiConversation(selected);
            else renderApp();
        } catch (error) {
            state.aiConversationsLoading = false;
            state.aiSearchError = error.message || t('ai.loadHistoryError');
            state.aiSearchErrorDetails = null;
            renderApp();
        }
    }

    async function selectAiConversation(conversationId) {
        conversationId = Number(conversationId || 0);
        if (!conversationId) return startNewAiConversation();
        const requestId = ++state.aiConversationRequest;
        state.aiConversationsLoading = true;
        state.aiSearchError = '';
        state.aiSearchErrorDetails = null;
        renderApp();
        try {
            const data = await api('ai-conversation', { query: `&id=${conversationId}` });
            if (requestId !== state.aiConversationRequest) return;
            state.aiConversationId = Number(data.conversation.id);
            state.aiMessages = Array.isArray(data.turns) ? data.turns : [];
            upsertAiConversation(data.conversation);
        } catch (error) {
            if (requestId !== state.aiConversationRequest) return;
            state.aiSearchError = error.message || t('ai.openChatError');
            state.aiSearchErrorDetails = null;
        } finally {
            if (requestId === state.aiConversationRequest) {
                state.aiConversationsLoading = false;
                renderApp();
            }
        }
    }

    async function submitAiSearch() {
        if (state.aiSearching) return;
        const input = document.getElementById('aiSearchInput');
        const question = String(input?.value || state.aiQuestion).trim();
        try {
            await submitAiQuestion(question);
        } catch (_) {
            // submitAiQuestion already presents the error in the AI panel.
        }
    }

    async function submitAiQuestion(question, options = {}) {
        if (state.aiSearching) throw new Error(t('ai.answerInProgress'));
        question = String(question || '').trim();
        if (question.length < 2) {
            state.aiSearchError = t('ai.minimumQuestion');
            state.aiSearchErrorDetails = null;
            renderApp();
            throw new Error(state.aiSearchError);
        }
        const currentPageId = state.view === 'page' && state.page?.id ? Number(state.page.id) : 0;
        if (currentPageId && state.dirty) {
            await savePage();
            if (state.dirty || !state.page || Number(state.page.id) !== currentPageId) {
                state.aiSearchError = t('ai.saveBeforeQuestion');
                state.aiSearchErrorDetails = null;
                renderApp();
                throw new Error(state.aiSearchError);
            }
        }
        state.aiQuestion = question;
        state.aiSearching = true;
        state.aiSearchError = '';
        state.aiSearchErrorDetails = null;
        renderApp();
        notifyAiSearchExtensions('onSubmitStart', { question, source: options.source || 'text' });
        try {
            const data = await api('ai-search', {
                method: 'POST',
                body: {
                    question,
                    page_id: currentPageId,
                    page_updated_at: currentPageId ? String(state.page?.updated_at || '') : '',
                    editor_context: currentPageId && Number(state.aiEditorContext?.page_id) === currentPageId ? state.aiEditorContext : null,
                    conversation_id: Number(state.aiConversationId || 0),
                },
                signal: options.signal,
            });
            state.aiMessages.push(data.result);
            state.aiConversationId = Number(data.conversation?.id || state.aiConversationId || 0);
            upsertAiConversation(data.conversation);
            state.aiQuestion = '';
            const confirmation = (data.result?.actions || []).find(action => action?.type === 'confirm_page_action' && action.token);
            if (confirmation) {
                const resolution = await confirmAiPageAction(confirmation);
                data.result = resolution.result;
                data.conversation = resolution.conversation;
                const messageIndex = state.aiMessages.findIndex(message => Number(message.id) === Number(data.result?.id));
                if (messageIndex >= 0) state.aiMessages[messageIndex] = data.result;
                else state.aiMessages.push(data.result);
                state.aiConversationId = Number(data.conversation?.id || state.aiConversationId || 0);
                upsertAiConversation(data.conversation);
            }
            await reconcileAiPageActions(data.result?.actions);
            notifyAiSearchExtensions('onResult', { result: data.result, conversation: data.conversation, source: options.source || 'text' });
            return data;
        } catch (error) {
            state.aiSearchError = error.message || t('ai.searchFailed');
            state.aiSearchErrorDetails = error?.data?.details?.source === 'external_llm'
                ? error.data.details
                : null;
            notifyAiSearchExtensions('onError', { error, source: options.source || 'text' });
            throw error;
        } finally {
            state.aiSearching = false;
            renderApp();
            applyPendingAiFocus();
        }
    }

    async function confirmAiPageAction(action) {
        const preview = action?.preview && typeof action.preview === 'object' ? action.preview : {};
        const lines = [
            String(preview.heading || t('ai.changeWarningHeading')),
            String(preview.summary || ''),
        ];
        if (preview.before) lines.push(`${t('ai.beforeChange')}\n${String(preview.before)}`);
        if (preview.after) lines.push(`${t('ai.afterChange')}\n${String(preview.after)}`);
        lines.push(t('ai.confirmChange'));
        const confirmed = window.confirm(lines.filter(Boolean).join('\n\n'));
        return api('ai-page-action-confirm', {
            method: 'POST',
            body: { token: String(action.token || ''), confirm: confirmed },
        });
    }

    async function reconcileAiPageActions(actions) {
        actions = Array.isArray(actions) ? actions : [];
        if (!actions.length) return;
        let refreshPages = false;
        let reloadCurrentPage = false;
        for (const action of actions) {
            if (action?.type === 'page_created') {
                refreshPages = true;
                if (Number(action.parent_id) > 0) state.expanded.add(Number(action.parent_id));
                toast(t('ai.pageCreated', { title: action.title || t('page.new') }));
            } else if (action?.type === 'page_updated') {
                refreshPages = true;
                reloadCurrentPage ||= state.view === 'page' && Number(state.page?.id) === Number(action.page_id);
                toast(t('ai.pageUpdated'));
            } else if (action?.type === 'focus') {
                state.aiPendingFocus = action;
            }
        }
        if (refreshPages) await refreshSummary();
        if (reloadCurrentPage && state.page?.id) {
            const pageId = Number(state.page.id);
            const data = await api('page', { query: `&id=${pageId}` });
            state.page = normalizePageAccessDepartments(data.page);
            syncPageTags(state.page);
            resetUndoHistory(data.page.id);
        }
    }

    function applyPendingAiFocus() {
        const action = state.aiPendingFocus;
        if (!action || Number(action.page_id) !== Number(state.page?.id || 0)) return;
        state.aiPendingFocus = null;
        clearTimeout(aiPanelCloseTimer);
        aiPanelCloseTimer = null;
        aiPanelClosing = false;
        state.dialog = null;
        document.body.classList.remove('ai-search-open');
        renderApp();
        setTimeout(() => {
            if (action.target === 'title') {
                const title = document.getElementById('pageTitle');
                title?.focus({ preventScroll: false });
                if (title) {
                    const range = document.createRange();
                    range.selectNodeContents(title);
                    range.collapse(false);
                    const selection = getSelection();
                    selection.removeAllRanges();
                    selection.addRange(range);
                }
            } else if (action.target === 'table_cell') {
                focusTableCell(String(action.block_id || ''), Number(action.row || 0), Number(action.column || 0));
            } else {
                focusBlock(String(action.block_id || ''));
            }
        }, 40);
    }

    async function summarizeCurrentPage() {
        if (state.aiSearching || state.view !== 'page' || !state.page?.id) return;
        const pageId = Number(state.page.id);
        if (state.dirty) {
            await savePage();
            if (state.dirty || !state.page || Number(state.page.id) !== pageId) {
                state.aiSearchError = t('ai.saveBeforeSummary');
                state.aiSearchErrorDetails = null;
                renderApp();
                return;
            }
        }
        const pageTitle = state.page.title;
        state.aiSearching = true;
        state.aiSearchError = '';
        state.aiSearchErrorDetails = null;
        renderApp();
        try {
            const data = await api('ai-search', { method: 'POST', body: { mode: 'page-summary', page_id: pageId, conversation_id: Number(state.aiConversationId || 0) } });
            state.aiMessages.push({ ...data.result, page_id: Number(data.result.page_id || pageId), page_title: data.result.page_title || pageTitle });
            state.aiConversationId = Number(data.conversation?.id || state.aiConversationId || 0);
            upsertAiConversation(data.conversation);
        } catch (error) {
            state.aiSearchError = error.message || t('ai.summaryFailed');
            state.aiSearchErrorDetails = error?.data?.details?.source === 'external_llm'
                ? error.data.details
                : null;
        } finally {
            state.aiSearching = false;
            renderApp();
        }
    }

    async function openPage(id, updateHash = true) {
        const currentPageId = Number(state.page?.id || 0);
        if (!(await flushPendingPageSave())) {
            if (!updateHash && currentPageId) history.replaceState(null, '', `#page-${currentPageId}`);
            toast(t('page.moveSaveFailed'), 'error');
            return false;
        }
        closeTransient();
        resetUndoHistory();
        state.view = 'page';
        state.panel = null;
        state.page = null;
        if (updateHash) history.pushState(null, '', `#page-${id}`);
        renderApp();
        try {
            const data = await api('page', { query: `&id=${id}` });
            state.page = normalizePageAccessDepartments(data.page);
            syncPageTags(state.page);
            resetUndoHistory(data.page.id);
            const summary = pageById(id);
            if (summary) Object.assign(summary, { title: data.page.title, icon: data.page.icon, updated_at: data.page.updated_at });
            renderApp();
            return true;
        } catch (err) { toast(err.message, 'error'); await showHome(); return false; }
    }

    async function showHome(updateHash = true) {
        const currentPageId = Number(state.page?.id || 0);
        if (!(await flushPendingPageSave())) {
            if (!updateHash && currentPageId) history.replaceState(null, '', `#page-${currentPageId}`);
            toast(t('page.moveSaveFailed'), 'error');
            return false;
        }
        closeTransient();
        resetUndoHistory();
        state.view = 'home'; state.page = null; state.panel = null;
        if (updateHash) history.pushState(null, '', location.pathname);
        renderApp();
        return true;
    }

    async function showTrash(updateHash = true) {
        const currentPageId = Number(state.page?.id || 0);
        if (!(await flushPendingPageSave())) {
            if (!updateHash && currentPageId) history.replaceState(null, '', `#page-${currentPageId}`);
            toast(t('page.moveSaveFailed'), 'error');
            return false;
        }
        closeTransient();
        resetUndoHistory();
        state.view = 'trash'; state.page = null; state.panel = null; state.mobileSidebar = false;
        if (updateHash) history.pushState(null, '', '#trash');
        renderApp();
        return true;
    }

    async function showFiles(updateHash = true) {
        const currentPageId = Number(state.page?.id || 0);
        if (!(await flushPendingPageSave())) {
            if (!updateHash && currentPageId) history.replaceState(null, '', `#page-${currentPageId}`);
            toast(t('page.moveSaveFailed'), 'error');
            return false;
        }
        closeTransient();
        resetUndoHistory();
        state.view = 'files'; state.page = null; state.panel = null; state.mobileSidebar = false;
        if (updateHash) history.pushState(null, '', '#files');
        renderApp();
        return true;
    }

    async function showSettings(tab = 'general', updateHash = true) {
        const currentPageId = Number(state.page?.id || 0);
        if (!(await flushPendingPageSave())) {
            if (!updateHash && currentPageId) history.replaceState(null, '', `#page-${currentPageId}`);
            toast(t('page.moveSaveFailed'), 'error');
            return false;
        }
        closeTransient();
        resetUndoHistory();
        const allowedTabs = state.capabilities.can_manage_system_settings
            ? ['general', 'ai', 'translation', 'plugins']
            : ['general'];
        state.settingsTab = allowedTabs.includes(tab) ? tab : 'general';
        state.view = 'settings';
        state.page = null;
        state.panel = null;
        state.mobileSidebar = false;
        state.settingsLoading = state.capabilities.can_manage_system_settings;
        if (updateHash) {
            history.pushState(null, '', state.settingsTab === 'general' ? '#settings' : `#settings-${state.settingsTab}`);
        }
        renderApp();
        if (state.capabilities.can_manage_system_settings) {
            await Promise.all([loadPlugins(false), loadAiProviderSettings(false), loadTranslationProviderSettings(false)]);
            state.settingsLoading = false;
            if (state.view === 'settings') renderApp();
        }
        return true;
    }

    function closeTransient() {
        closeEditorFind(true);
        if (state.dialog !== 'ai-search') state.dialog = null;
        state.slash = null; state.context = null; state.bubble = null; state.replyTo = null; state.preservedTextSelection = null;
    }

    async function createPage(parentId = null) {
        try {
            const data = await api('create-page', { method: 'POST', body: { parent_id: parentId || null } });
            await refreshSummary();
            if (parentId) state.expanded.add(Number(parentId));
            await openPage(data.id);
            setTimeout(() => { const title = document.getElementById('pageTitle'); title?.focus(); document.execCommand('selectAll', false); }, 60);
            toast(t('page.created'));
        } catch (err) { toast(err.message, 'error'); }
    }

    async function refreshSummary() {
        const data = await api('bootstrap');
        state.user = data.user;
        state.pages = (Array.isArray(data.pages) ? data.pages : []).map(normalizePageAccessDepartments);
        state.trash = (Array.isArray(data.trash) ? data.trash : []).map(normalizePageAccessDepartments);
        state.files = data.files || [];
        state.uploadLimitMb = data.upload_limit_mb || state.uploadLimitMb;
        state.profilePhotoLimitMb = data.profile_photo_limit_mb || state.profilePhotoLimitMb;
        state.profileIconChoices = data.profile_icon_choices || state.profileIconChoices;
        state.notifications = data.notifications;
        state.members = data.members;
        state.departments = Array.isArray(data.departments) ? data.departments : [];
        state.suspendedMembers = data.suspended_members || [];
        state.capabilities = data.capabilities || state.capabilities;
        applyI18n(data.i18n);
        state.workspaceName = data.settings?.workspace_name || state.workspaceName;
        state.organizationName = data.settings?.organization_name ?? '';
        state.csrf = data.csrf;
    }

    function updateNotificationChrome() {
        const count = unreadCount();
        root.querySelectorAll('.side-item[data-action="inbox"]').forEach(button => {
            let badge = button.querySelector('.count-badge.unread');
            if (!count) {
                badge?.remove();
                return;
            }
            if (!badge) {
                badge = document.createElement('span');
                badge.className = 'count-badge unread';
                button.appendChild(badge);
            }
            badge.textContent = String(count);
        });
        if (state.dialog !== 'inbox') return;
        const body = root.querySelector('.inbox-body');
        if (body) body.innerHTML = renderInboxBody();
        const title = root.querySelector('.inbox-dialog .dialog-title');
        if (title) title.innerHTML = `${esc(t('navigation.inbox'))}${count ? `<span class="dialog-count">${count}</span>` : ''}`;
        const markAll = root.querySelector('.inbox-footer [data-action="mark-read"]');
        if (markAll) markAll.disabled = count === 0;
    }

    async function refreshNotifications(showError = false) {
        if (!state.user) return;
        try {
            const data = await api('notifications');
            state.notifications = data.notifications || [];
            updateNotificationChrome();
        } catch (error) {
            if (showError) toast(error.message, 'error');
        }
    }

    function startNotificationPolling() {
        clearInterval(notificationPollTimer);
        if (databaseMigrationPollingPaused) {
            notificationPollTimer = null;
            return;
        }
        notificationPollTimer = setInterval(() => {
            if (!databaseMigrationPollingPaused && !document.hidden && state.user) refreshNotifications();
        }, 30000);
    }

    async function setNotificationRead(id, isRead) {
        const notification = state.notifications.find(item => Number(item.id) === Number(id));
        if (!notification) return;
        const previous = notification.is_read;
        notification.is_read = isRead ? 1 : 0;
        updateNotificationChrome();
        try {
            await api('notification-read', { method: 'POST', body: { id: Number(id), is_read: Boolean(isRead) } });
        } catch (error) {
            notification.is_read = previous;
            updateNotificationChrome();
            toast(error.message, 'error');
        }
    }

    const undoSnapshotKeys = ['title','icon','cover','status','category','tags','manual_tags','generated_tags','blocks','visibility','access_departments','access_member_ids','comments_enabled','language_code'];

    function serializeUndoSnapshot() {
        if (!state.page) return null;
        return JSON.stringify(undoSnapshotKeys.reduce((snapshot, key) => {
            snapshot[key] = state.page[key];
            return snapshot;
        }, {}));
    }

    function updateUndoButton() {
        const button = root.querySelector('[data-action="undo"]');
        if (!button) return;
        button.disabled = state.undoHistory.length === 0;
        button.title = state.undoHistory.length ? t('common.undoRemaining', { count: state.undoHistory.length }) : t('common.undo');
    }

    function resetUndoHistory(pageId = null) {
        state.undoHistory = [];
        state.undoPageId = pageId === null ? null : Number(pageId);
        state.undoPresent = state.page && Number(state.page.id) === state.undoPageId ? serializeUndoSnapshot() : null;
        state.undoGroup = null;
        state.undoApplying = false;
        updateUndoButton();
    }

    function recordUndoChange(groupKey = null) {
        if (!state.page || !canEditCurrentPage() || state.undoApplying) return;
        const pageId = Number(state.page.id);
        const current = serializeUndoSnapshot();
        if (state.undoPageId !== pageId || state.undoPresent === null) {
            state.undoPageId = pageId;
            state.undoPresent = current;
            state.undoHistory = [];
            state.undoGroup = null;
            updateUndoButton();
            return;
        }
        if (current === state.undoPresent) return;
        const now = Date.now();
        const coalesced = groupKey && state.undoGroup?.key === groupKey && now - state.undoGroup.time < 1200;
        if (!coalesced) {
            state.undoHistory.push({ pageId, snapshot: state.undoPresent });
            if (state.undoHistory.length > 20) state.undoHistory.splice(0, state.undoHistory.length - 20);
        }
        state.undoPresent = current;
        state.undoGroup = groupKey ? { key: groupKey, time: now } : null;
        updateUndoButton();
    }

    function inputUndoGroup(event) {
        const type = String(event.inputType || '');
        if (!['insertText', 'insertCompositionText', 'deleteContentBackward', 'deleteContentForward'].includes(type)) return null;
        const target = event.target;
        const targetKey = target.id === 'pageTitle'
            ? 'title'
            : target.dataset.id
                ? `block:${target.dataset.id}`
                : `cell:${target.dataset.blockId}:${target.dataset.row}:${target.dataset.col}`;
        return `${state.page?.id || 0}:${targetKey}`;
    }

    function undoEdit() {
        if (!state.page || state.undoPageId !== Number(state.page.id) || !state.undoHistory.length) return;
        syncEditorFromDom();
        const current = serializeUndoSnapshot();
        let previous = state.undoHistory.pop();
        while (previous && previous.snapshot === current) previous = state.undoHistory.pop();
        if (!previous) {
            state.undoPresent = current;
            state.undoGroup = null;
            updateUndoButton();
            return;
        }
        state.undoApplying = true;
        Object.assign(state.page, JSON.parse(previous.snapshot));
        state.undoPresent = previous.snapshot;
        state.undoGroup = null;
        state.tableSelection = null;
        closeTransient();
        const summary = pageById(state.page.id);
        if (summary) Object.assign(summary, { title: state.page.title, icon: state.page.icon, cover: state.page.cover, status: state.page.status, category: state.page.category, tags: state.page.tags, visibility: state.page.visibility, access_departments: [...state.page.access_departments] });
        renderApp();
        scheduleSave(true);
        state.undoApplying = false;
        updateUndoButton();
        toast(t('page.undoDone'));
    }

    function scheduleSave(immediate = false, undoGroupKey = null) {
        if (!state.page || !canEditCurrentPage()) return;
        recordUndoChange(undoGroupKey);
        state.saveRetryBlocked = false;
        state.saveFailure = null;
        state.dirty = true;
        clearTimeout(state.saveTimer);
        state.saveTimer = setTimeout(savePage, immediate ? 20 : 1000);
        updateSaveIndicator('saving');
    }

    function syncEditorFromDom() {
        if (!state.page) return;
        const title = document.getElementById('pageTitle');
        if (title) state.page.title = title.textContent.trim() || t('page.untitled');
        root.querySelectorAll('.block-content[data-id]').forEach(el => {
            const block = state.page.blocks.find(item => item.id === el.dataset.id);
            if (block) block.content = el.innerHTML;
        });
        root.querySelectorAll('.table-cell-content[data-block-id]').forEach(el => {
            const block = state.page.blocks.find(item => item.id === el.dataset.blockId);
            const cell = block?.table_rows?.[Number(el.dataset.row)]?.[Number(el.dataset.col)];
            if (cell) cell.content = el.innerHTML;
        });
    }

    function syncCurrentPageFileReferences() {
        if (!state.page) return;
        const pageId = Number(state.page.id);
        const usedFileIds = new Set((state.page.blocks || []).map(block => Number(block.file_id || 0)).filter(Boolean));
        state.files = state.files.map(file => {
            const references = (Array.isArray(file.references) ? file.references : [])
                .filter(reference => Number(reference.page_id) !== pageId);
            if (usedFileIds.has(Number(file.id))) {
                references.push({ page_id: pageId, page_title: state.page.title || t('page.untitled') });
            }
            return { ...file, references, reference_count: references.length, referenced: references.length > 0 };
        });
    }

    function queueAutomaticSaveRetry() {
        if (!state.page || !state.dirty || state.saveRetryBlocked) return;
        clearTimeout(state.saveTimer);
        state.saveTimer = setTimeout(savePage, 1000);
        updateSaveIndicator('saving');
    }

    async function flushPendingPageSave() {
        const page = state.page;
        if (!page) return true;
        const deadline = Date.now() + 10000;
        while (state.saving && Date.now() < deadline) {
            await new Promise(resolve => setTimeout(resolve, 50));
        }
        if (state.page !== page || state.saving || state.saveFailure || state.saveRetryBlocked) return false;
        clearTimeout(state.saveTimer);
        while (state.dirty && Date.now() < deadline) {
            clearTimeout(state.saveTimer);
            const result = await savePage();
            if (result === 'conflict') continue;
            if (result !== 'saved') return false;
            while (state.saving && Date.now() < deadline) {
                await new Promise(resolve => setTimeout(resolve, 50));
            }
            if (state.page !== page || state.saving || state.saveFailure || state.saveRetryBlocked) return false;
        }
        return state.page === page && !state.dirty && !state.saving;
    }

    async function savePage() {
        if (!state.page || !state.dirty) return 'saved';
        if (state.saving) return 'busy';
        syncEditorFromDom();
        normalizePageAccessDepartments(state.page, false);
        state.saving = true; state.dirty = false; updateSaveIndicator('saving');
        const pageAtRequest = state.page;
        const requestedAccessState = normalizedPageAccessState(pageAtRequest);
        const requestedAccessSignature = pageAccessStateSignature(requestedAccessState);
        const expectedAccessState = normalizedPageAccessState(pageAtRequest._persisted_access_state, pageAtRequest);
        try {
            const payload = ['id','title','icon','cover','status','category','tags','manual_tags','blocks','visibility','access_departments','access_member_ids','comments_enabled','language_code'].reduce((result, key) => ({ ...result, [key]: pageAtRequest[key] }), {});
            payload.expected_access_state = expectedAccessState;
            const data = await api('save-page', { method: 'POST', body: payload });
            if (state.page !== pageAtRequest) return 'stale';
            state.page.updated_at = data.updated_at;
            state.page.language_code = data.language_code || state.page.language_code || 'und';
            state.page.content_revision = Number(data.content_revision || state.page.content_revision || 1);
            state.page.translation_status = data.translation_status || state.page.translation_status || 'original';
            if (state.page.translation) state.page.translation.source_revision = state.page.source_page_id ? state.page.translation.source_revision : state.page.content_revision;
            const persistedAccessState = normalizedPageAccessState(data, requestedAccessState);
            state.page._persisted_access_state = persistedAccessState;
            if (pageAccessStateSignature(state.page) === requestedAccessSignature) {
                Object.assign(state.page, {
                    status: persistedAccessState.status,
                    visibility: persistedAccessState.visibility,
                    access_departments: [...persistedAccessState.access_departments],
                    access_member_ids: [...persistedAccessState.access_member_ids],
                });
            }
            if (Array.isArray(data.manual_tags)) state.page.manual_tags = data.manual_tags;
            if (Array.isArray(data.generated_tags)) state.page.generated_tags = data.generated_tags;
            if (Array.isArray(data.tags)) state.page.tags = data.tags;
            else syncPageTags(state.page);
            state.lastSaved = new Date();
            state.saveRetryBlocked = false;
            state.saveFailure = null;
            let summaryRefreshFailed = false;
            if (Number(data.inherited_pages || 0) > 0) {
                try {
                    await refreshSummary();
                } catch (refreshError) {
                    summaryRefreshFailed = true;
                    console.error('Page saved, but summary refresh failed.', refreshError);
                }
            }
            if (state.page === pageAtRequest) {
                syncCurrentPageFileReferences();
                const summary = pageById(state.page.id);
                if (summary) Object.assign(summary, { title: state.page.title, icon: state.page.icon, cover: state.page.cover, status: state.page.status, category: state.page.category, tags: state.page.tags, visibility: state.page.visibility, access_departments: [...state.page.access_departments], _persisted_access_state: normalizedPageAccessState(persistedAccessState), updated_at: data.updated_at });
            }
            updateSaveIndicator('saved');
            if (data.notice) toast(data.notice, 'error');
            if (summaryRefreshFailed) toast(t('page.listRefreshFailed'), 'error');
            return 'saved';
        } catch (err) {
            const hasNewerEdits = state.page === pageAtRequest && state.dirty;
            const conflictPayload = err.data?.current_access_state;
            if (state.page === pageAtRequest && err.status === 409 && err.code === 'page_access_conflict'
                && conflictPayload && typeof conflictPayload === 'object') {
                const serverAccessState = normalizedPageAccessState(conflictPayload, pageAtRequest._persisted_access_state);
                Object.assign(state.page, {
                    status: serverAccessState.status,
                    visibility: serverAccessState.visibility,
                    access_departments: [...serverAccessState.access_departments],
                    access_member_ids: [...serverAccessState.access_member_ids],
                    _persisted_access_state: normalizedPageAccessState(serverAccessState),
                });
                const summary = pageById(state.page.id);
                if (summary) Object.assign(summary, { status: serverAccessState.status, visibility: serverAccessState.visibility, access_departments: [...serverAccessState.access_departments], _persisted_access_state: normalizedPageAccessState(serverAccessState) });
                state.dirty = true;
                state.saveRetryBlocked = false;
                state.saveFailure = null;
                if (state.dialog === 'share') renderApp();
                toast(t('share.concurrentUpdate'), 'error');
                updateSaveIndicator('saving');
                return 'conflict';
            }
            if (state.page !== pageAtRequest) return 'stale';
            if (state.page === pageAtRequest) state.dirty = true;
            const isValidationFailure = Number(err.status || 0) >= 400 && Number(err.status || 0) < 500;
            state.saveRetryBlocked = isValidationFailure && !hasNewerEdits;
            state.saveFailure = state.saveRetryBlocked ? 'validation' : (isValidationFailure ? null : 'retryable');
            toast(err.message, 'error');
            updateSaveIndicator('error');
            return 'failed';
        } finally {
            state.saving = false;
            if (state.dirty && !state.saveRetryBlocked) queueAutomaticSaveRetry();
        }
    }

    async function exportCurrentPage(format) {
        if (!state.page || !['print', 'pdf', 'md'].includes(format)) return;
        const pageId = Number(state.page.id);
        const preview = format === 'md' ? null : window.open('about:blank', '_blank');
        if (format !== 'md' && !preview) {
            toast(t('export.popupBlocked'), 'error');
            return;
        }
        if (preview) {
            preview.document.title = `OpenConcept - ${t('export.preparing')}`;
            preview.document.body.textContent = t('export.checkingSave');
        }
        state.context = null;
        removeFloatingElements();
        clearTimeout(state.saveTimer);
        if (state.dirty) await savePage();
        const startedAt = Date.now();
        while (state.saving && Date.now() - startedAt < 10000) {
            await new Promise(resolve => setTimeout(resolve, 80));
        }
        if (state.dirty || state.saving) {
            preview?.close();
            toast(t('export.saveFailed'), 'error');
            return;
        }
        const url = `export.php?id=${pageId}&format=${encodeURIComponent(format)}`;
        if (preview) {
            preview.opener = null;
            preview.location.replace(url);
        } else {
            const link = document.createElement('a');
            link.href = url;
            link.download = '';
            link.hidden = true;
            document.body.appendChild(link);
            link.click();
            link.remove();
            toast(t('export.markdownSaved'));
        }
    }

    function updateSaveIndicator(mode) {
        const el = root.querySelector('.save-state');
        if (!el) return;
        el.classList.toggle('saving', mode === 'saving');
        const label = el.querySelector('span:last-child');
        if (label) label.textContent = mode === 'saving' ? t('common.saving') : mode === 'error' ? t('common.unsaved') : t('common.saved');
    }

    function insertBlock(referenceId = null, type = 'paragraph', position = 'after') {
        const block = { id: uid(), type, content: '', ...(type === 'todo' ? { checked: false } : {}), ...(type === 'callout' ? { emoji: '💡' } : {}), ...(type === 'table' ? createTableFields() : {}) };
        let insertAt = state.page.blocks.length;
        if (referenceId) {
            const referenceIndex = state.page.blocks.findIndex(item => item.id === referenceId);
            if (referenceIndex >= 0) insertAt = referenceIndex + (position === 'before' ? 0 : 1);
        }
        state.page.blocks.splice(insertAt, 0, block);
        renderEditorOnly(); scheduleSave();
        if (type === 'table') focusTableCell(block.id, 0, 0); else focusBlock(block.id);
    }

    function renderEditorOnly() {
        const editor = document.getElementById('editor');
        if (editor) editor.innerHTML = renderEditorTopInsert() + state.page.blocks.map(renderBlock).join('') + `<div class="editor-bottom" data-action="append-block">${esc(t('page.continueEditing'))}</div>` + renderChildPageToc(state.page.id);
        removeFloatingElements();
    }

    function focusBlock(id, end = true) {
        setTimeout(() => {
            const el = root.querySelector(`.block-content[data-id="${CSS.escape(id)}"]`);
            if (!el) return;
            el.focus();
            if (end) {
                const range = document.createRange(); range.selectNodeContents(el); range.collapse(false);
                const selection = getSelection(); selection.removeAllRanges(); selection.addRange(range);
            }
        }, 20);
    }

    function focusTableCell(blockId, row = 0, col = 0, extend = false) {
        const current = state.tableSelection;
        const keepAnchor = extend && current?.blockId === blockId;
        state.tableSelection = {
            blockId,
            anchorRow: keepAnchor ? Number(current.anchorRow ?? current.row ?? row) : row,
            anchorCol: keepAnchor ? Number(current.anchorCol ?? current.col ?? col) : col,
            focusRow: row,
            focusCol: col,
            row,
            col,
        };
        updateTableSelectionVisuals(blockId);
        setTimeout(() => root.querySelector(`.table-cell-content[data-block-id="${CSS.escape(blockId)}"][data-row="${row}"][data-col="${col}"]`)?.focus({ preventScroll: true }), 20);
    }

    function chooseBlock(type) {
        if (!state.slash) return;
        const menu = state.slash;
        const id = menu.id;
        if (menu.mode === 'insert' || menu.mode === 'insert-before') {
            state.slash = null;
            insertBlock(id, type, menu.mode === 'insert-before' ? 'before' : 'after');
            return;
        }
        const block = state.page.blocks.find(item => item.id === id);
        if (!block) return;
        block.type = type;
        if (!menu.forced) block.content = '';
        if (type === 'todo') block.checked = false;
        if (type === 'callout') block.emoji = '💡';
        if (['image', 'file'].includes(type)) Object.assign(block, { content: '', file_id: 0, file_name: '', file_category: '', file_size: 0 });
        if (type === 'table') Object.assign(block, { content: '', ...createTableFields() });
        state.slash = null;
        renderEditorOnly(); scheduleSave();
        if (type === 'table') focusTableCell(id, 0, 0);
        else if (!['divider', 'image', 'file'].includes(type)) focusBlock(id);
        else insertBlock(id);
    }

    function showSlashFor(el, forced = false, query = '', mode = 'convert') {
        if (!state.page) return;
        const rect = el.getBoundingClientRect();
        state.slash = { id: el.dataset.id || el.dataset.blockId, x: rect.left, y: rect.bottom + 5, forced, query, mode, selectedIndex: 0 };
        removeFloatingElements();
        root.insertAdjacentHTML('beforeend', renderFloating());
    }

    function showBlockMenu(id, button, preservedSelection = null) {
        const rect = button.getBoundingClientRect();
        const currentSelection = preservedSelection || captureEditorTextSelection();
        const textSelection = selectionIncludesBlock(currentSelection, id) ? currentSelection : null;
        state.context = { id, x: rect.left, y: rect.bottom + 4, textSelection };
        state.preservedTextSelection = null;
        removeFloatingElements(); root.insertAdjacentHTML('beforeend', renderFloating());
    }

    function showPageMenu(pageId, button) {
        const rect = button.getBoundingClientRect();
        state.context = { pageId, x: rect.left, y: rect.bottom + 4 };
        removeFloatingElements(); root.insertAdjacentHTML('beforeend', renderFloating());
    }

    function removeFloatingElements() { root.querySelectorAll('.slash-menu,.context-menu,.bubble-menu').forEach(el => el.remove()); }

    function showBubbleMenu() {
        if (!canEditCurrentPage()) { hideBubble(); return; }
        const selection = getSelection();
        if (!selection || selection.isCollapsed || !selection.rangeCount) { hideBubble(); return; }
        if (hasCrossBlockTextSelection(selection)) { hideBubble(); return; }
        const anchor = blockContentForNode(selection.anchorNode);
        if (!anchor) { hideBubble(); return; }
        const rect = selection.getRangeAt(0).getBoundingClientRect();
        state.bubble = { x: rect.left + rect.width / 2 - 125, y: rect.top - 40 };
        root.querySelector('.bubble-menu')?.remove();
        root.insertAdjacentHTML('beforeend', renderBubbleMenu());
    }

    function hideBubble() { state.bubble = null; root.querySelector('.bubble-menu')?.remove(); }

    function formatCurrentSelection(command, value = null) {
        if (!canEditCurrentPage()) { hideBubble(); return false; }
        document.execCommand(command, false, value);
        syncEditorFromDom();
        scheduleSave();
        return true;
    }

    function blockContentForNode(node) {
        const element = node?.nodeType === Node.ELEMENT_NODE ? node : node?.parentElement;
        return element?.closest?.('.block-content') || null;
    }

    function hasCrossBlockTextSelection(selection = getSelection()) {
        if (!selection || selection.isCollapsed || !selection.rangeCount) return false;
        const anchor = blockContentForNode(selection.anchorNode);
        const focus = blockContentForNode(selection.focusNode);
        return Boolean(anchor && focus && anchor !== focus);
    }

    function captureEditorTextSelection(selection = getSelection()) {
        if (!selection || selection.isCollapsed || !selection.rangeCount) return null;
        const range = selection.getRangeAt(0).cloneRange();
        const startBlock = blockContentForNode(range.startContainer);
        const endBlock = blockContentForNode(range.endContainer);
        const editor = document.getElementById('editor');
        if (!startBlock || !endBlock || !editor?.contains(startBlock) || !editor.contains(endBlock)) return null;
        const startIndex = state.page?.blocks.findIndex(block => block.id === startBlock.dataset.id) ?? -1;
        const endIndex = state.page?.blocks.findIndex(block => block.id === endBlock.dataset.id) ?? -1;
        if (startIndex < 0 || endIndex < startIndex) return null;
        return {
            range,
            startBlock,
            endBlock,
            startId: startBlock.dataset.id,
            endId: endBlock.dataset.id,
            startIndex,
            endIndex,
        };
    }

    function selectionIncludesBlock(selection, blockId) {
        if (!selection || !state.page) return false;
        const blockIndex = state.page.blocks.findIndex(block => block.id === blockId);
        return blockIndex >= selection.startIndex && blockIndex <= selection.endIndex;
    }

    function blockFragmentHtml(block, range, beforeSelection) {
        const fragmentRange = document.createRange();
        fragmentRange.selectNodeContents(block);
        if (beforeSelection) fragmentRange.setEnd(range.startContainer, range.startOffset);
        else fragmentRange.setStart(range.endContainer, range.endOffset);
        return fragmentHtml(fragmentRange.cloneContents());
    }

    function htmlHasVisibleContent(html) {
        const container = document.createElement('div');
        container.innerHTML = html;
        return (container.textContent || '').replace(/[\u200B-\u200D\uFEFF]/g, '').length > 0;
    }

    function deleteEditorTextSelection(capturedSelection = captureEditorTextSelection()) {
        if (!capturedSelection || !state.page || !canEditCurrentPage()) return false;
        const { range, startBlock, endBlock, startId, endId } = capturedSelection;
        if (!startBlock.isConnected || !endBlock.isConnected) return false;
        syncEditorFromDom();
        state.context = null;
        state.preservedTextSelection = null;
        hideBubble();

        if (startBlock === endBlock) {
            range.deleteContents();
            startBlock.normalize();
            const block = state.page.blocks.find(item => item.id === startId);
            if (!block) return false;
            block.content = startBlock.innerHTML;
            const caret = range.cloneRange();
            caret.collapse(true);
            const selection = getSelection();
            selection?.removeAllRanges();
            selection?.addRange(caret);
            startBlock.focus({ preventScroll: true });
            removeFloatingElements();
            scheduleSave();
            return true;
        }

        const startIndex = state.page.blocks.findIndex(block => block.id === startId);
        const endIndex = state.page.blocks.findIndex(block => block.id === endId);
        if (startIndex < 0 || endIndex <= startIndex) return false;
        const prefixHtml = blockFragmentHtml(startBlock, range, true);
        const suffixHtml = blockFragmentHtml(endBlock, range, false);
        const contentBlocks = [...root.querySelectorAll('#editor .block-content[data-id]')];
        const firstContentIndex = contentBlocks.indexOf(startBlock);
        const lastContentIndex = contentBlocks.indexOf(endBlock);
        if (firstContentIndex < 0 || lastContentIndex <= firstContentIndex) return false;
        const selectedIds = new Set(contentBlocks.slice(firstContentIndex, lastContentIndex + 1).map(block => block.dataset.id));
        const selectedModelBlocks = state.page.blocks.slice(startIndex, endIndex + 1);
        const canMergeAcrossSelection = selectedModelBlocks.every(block => selectedIds.has(block.id) && !['divider', 'image', 'file', 'table'].includes(block.type));
        let focusId = startId;
        let focusOffset = nodePlainText(startBlock).slice(0, textOffsetWithinBlock(startBlock, range.startContainer, range.startOffset)).length;

        if (canMergeAcrossSelection) {
            const mergedHtml = prefixHtml + suffixHtml;
            state.page.blocks[startIndex].content = mergedHtml;
            state.page.blocks.splice(startIndex + 1, endIndex - startIndex);
            if (!htmlHasVisibleContent(mergedHtml)) {
                state.page.blocks.splice(startIndex, 1);
                focusId = state.page.blocks[startIndex]?.id || state.page.blocks[startIndex - 1]?.id || '';
                focusOffset = state.page.blocks[startIndex]?.id === focusId ? 0 : Number.MAX_SAFE_INTEGER;
            }
        } else {
            state.page.blocks[startIndex].content = prefixHtml;
            state.page.blocks[endIndex].content = suffixHtml;
            for (let index = endIndex - 1; index > startIndex; index--) {
                const block = state.page.blocks[index];
                if (selectedIds.has(block.id) && !['image', 'file', 'table'].includes(block.type)) state.page.blocks.splice(index, 1);
            }
            const endBlockIndex = state.page.blocks.findIndex(block => block.id === endId);
            if (endBlockIndex >= 0 && !['image', 'file', 'table'].includes(state.page.blocks[endBlockIndex].type) && !htmlHasVisibleContent(suffixHtml)) {
                state.page.blocks.splice(endBlockIndex, 1);
            }
            const currentStartIndex = state.page.blocks.findIndex(block => block.id === startId);
            if (currentStartIndex >= 0 && !['image', 'file', 'table'].includes(state.page.blocks[currentStartIndex].type) && !htmlHasVisibleContent(prefixHtml)) {
                state.page.blocks.splice(currentStartIndex, 1);
                focusId = state.page.blocks[currentStartIndex]?.id || state.page.blocks[currentStartIndex - 1]?.id || '';
                focusOffset = state.page.blocks[currentStartIndex]?.id === focusId ? 0 : Number.MAX_SAFE_INTEGER;
            }
        }

        if (!state.page.blocks.length) {
            const emptyBlock = { id: uid(), type: 'paragraph', content: '' };
            state.page.blocks.push(emptyBlock);
            focusId = emptyBlock.id;
            focusOffset = 0;
        }
        renderEditorOnly();
        scheduleSave();
        if (focusId) focusBlockAtTextOffset(focusId, focusOffset);
        return true;
    }

    function caretPointInBlock(block, x, y) {
        const caretPosition = document.caretPositionFromPoint?.(x, y);
        if (caretPosition && (caretPosition.offsetNode === block || block.contains(caretPosition.offsetNode))) {
            return { node: caretPosition.offsetNode, offset: caretPosition.offset };
        }
        const caretRange = document.caretRangeFromPoint?.(x, y);
        if (caretRange && (caretRange.startContainer === block || block.contains(caretRange.startContainer))) {
            return { node: caretRange.startContainer, offset: caretRange.startOffset };
        }
        const range = document.createRange();
        range.selectNodeContents(block);
        const rect = block.getBoundingClientRect();
        range.collapse(y < rect.top + rect.height / 2 || y <= rect.top && x <= rect.left);
        return { node: range.startContainer, offset: range.startOffset };
    }

    function blockContentAtPoint(x, y) {
        const direct = document.elementFromPoint(x, y)?.closest?.('.block-content');
        if (direct) return direct;
        const blocks = [...root.querySelectorAll('#editor .block-content')];
        if (!blocks.length) return null;
        return blocks.reduce((nearest, block) => {
            const rect = block.getBoundingClientRect();
            const distance = y < rect.top ? rect.top - y : y > rect.bottom ? y - rect.bottom : 0;
            return !nearest || distance < nearest.distance ? { block, distance } : nearest;
        }, null)?.block || null;
    }

    function setTextSelection(anchor, focus) {
        const selection = getSelection();
        if (!selection) return;
        try {
            selection.setBaseAndExtent(anchor.node, anchor.offset, focus.node, focus.offset);
        } catch {
            const anchorRange = document.createRange();
            anchorRange.setStart(anchor.node, anchor.offset);
            anchorRange.collapse(true);
            const focusRange = document.createRange();
            focusRange.setStart(focus.node, focus.offset);
            focusRange.collapse(true);
            const range = document.createRange();
            if (anchorRange.compareBoundaryPoints(Range.START_TO_START, focusRange) <= 0) {
                range.setStart(anchor.node, anchor.offset);
                range.setEnd(focus.node, focus.offset);
            } else {
                range.setStart(focus.node, focus.offset);
                range.setEnd(anchor.node, anchor.offset);
            }
            selection.removeAllRanges();
            selection.addRange(range);
        }
    }

    function startCrossBlockTextSelection(target, event) {
        if (event.button !== 0) return;
        const selection = getSelection();
        const existingAnchorBlock = blockContentForNode(selection?.anchorNode);
        if (event.shiftKey && selection?.rangeCount && existingAnchorBlock && existingAnchorBlock !== target) {
            const anchor = { node: selection.anchorNode, offset: selection.anchorOffset };
            const focus = caretPointInBlock(target, event.clientX, event.clientY);
            event.preventDefault();
            target.focus({ preventScroll: true });
            setTextSelection(anchor, focus);
            hideBubble();
            return;
        }
        const anchor = caretPointInBlock(target, event.clientX, event.clientY);
        let crossed = false;
        const onMove = moveEvent => {
            const focusBlock = blockContentAtPoint(moveEvent.clientX, moveEvent.clientY);
            if (!focusBlock || focusBlock === target && !crossed) return;
            const focus = caretPointInBlock(focusBlock, moveEvent.clientX, moveEvent.clientY);
            crossed = true;
            moveEvent.preventDefault();
            setTextSelection(anchor, focus);
            root.classList.add('cross-block-selecting');
            if (moveEvent.clientY < 54) window.scrollBy(0, -12);
            else if (moveEvent.clientY > window.innerHeight - 54) window.scrollBy(0, 12);
        };
        const onEnd = () => {
            document.removeEventListener('pointermove', onMove);
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('pointerup', onEnd);
            document.removeEventListener('mouseup', onEnd);
            document.removeEventListener('pointercancel', onEnd);
            root.classList.remove('cross-block-selecting');
            if (crossed) setTimeout(() => hasCrossBlockTextSelection() ? hideBubble() : showBubbleMenu(), 0);
        };
        document.addEventListener('pointermove', onMove);
        document.addEventListener('mousemove', onMove);
        document.addEventListener('pointerup', onEnd, { once: true });
        document.addEventListener('mouseup', onEnd, { once: true });
        document.addEventListener('pointercancel', onEnd, { once: true });
    }

    function nodePlainText(node) {
        if (!node) return '';
        if (node.nodeType === Node.TEXT_NODE) return node.textContent || '';
        if (node.nodeType === Node.ELEMENT_NODE && node.tagName === 'BR') return '\n';
        return [...node.childNodes].map(nodePlainText).join('');
    }

    function textOffsetWithinBlock(block, targetNode, targetOffset) {
        let total = 0;
        let found = false;
        const visit = node => {
            if (found) return;
            if (node === targetNode) {
                if (node.nodeType === Node.TEXT_NODE) total += Math.min(Number(targetOffset) || 0, (node.textContent || '').length);
                else [...node.childNodes].slice(0, Number(targetOffset) || 0).forEach(child => { total += nodePlainText(child).length; });
                found = true;
                return;
            }
            if (node.nodeType === Node.TEXT_NODE) {
                total += (node.textContent || '').length;
                return;
            }
            if (node.nodeType === Node.ELEMENT_NODE && node.tagName === 'BR') {
                total += 1;
                return;
            }
            [...node.childNodes].forEach(visit);
        };
        visit(block);
        return total;
    }

    function crossBlockSelectionParts(selection = getSelection()) {
        if (!hasCrossBlockTextSelection(selection)) return [];
        const selectionRange = selection.getRangeAt(0);
        const blocks = [...root.querySelectorAll('#editor .block-content')];
        const startBlock = blockContentForNode(selectionRange.startContainer);
        const endBlock = blockContentForNode(selectionRange.endContainer);
        const startIndex = blocks.indexOf(startBlock);
        const endIndex = blocks.indexOf(endBlock);
        if (startIndex < 0 || endIndex < startIndex) return [];
        return blocks.slice(startIndex, endIndex + 1).map(block => {
            const fullText = nodePlainText(block);
            const startOffset = block === startBlock
                ? textOffsetWithinBlock(block, selectionRange.startContainer, selectionRange.startOffset)
                : 0;
            const endOffset = block === endBlock
                ? textOffsetWithinBlock(block, selectionRange.endContainer, selectionRange.endOffset)
                : fullText.length;
            const text = fullText.slice(startOffset, endOffset);
            return { block, html: esc(text).replace(/\n/g, '<br>'), text };
        });
    }

    function copyCrossBlockTextSelection(event) {
        const parts = crossBlockSelectionParts();
        if (parts.length < 2 || !event.clipboardData) return false;
        event.preventDefault();
        event.clipboardData.setData('text/plain', parts.map(part => part.text).join('\n'));
        event.clipboardData.setData('text/html', parts.map(part => `<div data-openconcept-block-type="${esc(part.block.dataset.type)}">${part.html}</div>`).join(''));
        toast(t('editor.copiedBlocks', { count: parts.length }));
        return true;
    }

    function selectionCoversBlock(selection, block) {
        if (!selection || selection.isCollapsed || !selection.rangeCount || blockContentForNode(selection.anchorNode) !== block || blockContentForNode(selection.focusNode) !== block) return false;
        return selection.toString() === block.textContent;
    }

    function selectBlockText(block) {
        if (!block) return;
        block.focus({ preventScroll: true });
        const range = document.createRange();
        range.selectNodeContents(block);
        const selection = getSelection();
        selection.removeAllRanges();
        selection.addRange(range);
        hideBubble();
    }

    function selectAllEditorText() {
        const blocks = [...root.querySelectorAll('#editor .block-content')];
        if (!blocks.length) return;
        const first = blocks[0];
        const last = blocks.at(-1);
        first.focus({ preventScroll: true });
        setTextSelection({ node: first, offset: 0 }, { node: last, offset: last.childNodes.length });
        hideBubble();
    }

    const commentEmojis = ['😀','😊','👍','👏','🎉','❤️','💡','✅','👀','🙏','🤝','🚀','📌','⚠️','❓','✨','🙌','💬'];

    function availableMentionMembers(query = '') {
        const normalized = String(query).trim().toLocaleLowerCase('ja-JP');
        const mentionableIds = new Set((state.page?.mentionable_member_ids || []).map(Number));
        return state.members
            .filter(member => member.role !== 'system' && Number(member.id) !== Number(state.user?.id) && (mentionableIds.size ? mentionableIds.has(Number(member.id)) : memberCanViewCurrentPage(member)))
            .filter(member => !normalized || `${member.name} ${member.department || ''}`.toLocaleLowerCase('ja-JP').includes(normalized))
            .slice(0, 8);
    }

    function closeCommentPicker() {
        state.commentPicker = null;
        root.querySelector('.comment-picker')?.remove();
    }

    function commentPickerHtml() {
        const picker = state.commentPicker;
        if (!picker) return '';
        if (picker.type === 'emoji') {
            return `<div class="comment-picker emoji-picker" role="listbox" aria-label="${esc(t('comments.selectEmoji'))}">${commentEmojis.map((emoji, index) => `<button type="button" class="emoji-choice ${index === picker.selectedIndex ? 'selected' : ''}" data-action="choose-comment-emoji" data-value="${esc(emoji)}" role="option" aria-selected="${index === picker.selectedIndex}">${esc(emoji)}</button>`).join('')}</div>`;
        }
        const members = availableMentionMembers(picker.query);
        picker.items = members.map(member => Number(member.id));
        picker.selectedIndex = Math.max(0, Math.min(picker.selectedIndex, Math.max(0, members.length - 1)));
        return `<div class="comment-picker mention-picker" role="listbox" aria-label="${esc(t('comments.selectMember'))}">${members.length ? members.map((member, index) => `<button type="button" class="mention-choice ${index === picker.selectedIndex ? 'selected' : ''}" data-action="choose-comment-mention" data-id="${member.id}" role="option" aria-selected="${index === picker.selectedIndex}">${memberAvatar(member, 'sm')}<span><strong>${esc(member.name)}</strong><small>${esc(member.department || roleNames()[member.role] || '')}</small></span></button>`).join('') : `<div class="comment-picker-empty">${esc(t('comments.noMembers'))}</div>`}</div>`;
    }

    function renderCommentPicker() {
        root.querySelector('.comment-picker')?.remove();
        const composer = root.querySelector('.composer-box');
        if (!composer || !state.commentPicker) return;
        composer.insertAdjacentHTML('beforeend', commentPickerHtml());
        root.querySelector('.comment-picker .selected')?.scrollIntoView({ block: 'nearest' });
    }

    function insertCommentText(text, start = null, end = null) {
        const input = document.getElementById('commentInput');
        if (!input) return;
        const from = start ?? input.selectionStart ?? input.value.length;
        const to = end ?? input.selectionEnd ?? from;
        input.setRangeText(text, from, to, 'end');
        input.focus();
        input.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function openMentionPickerFromButton() {
        const input = document.getElementById('commentInput');
        if (!input) return;
        const start = input.selectionStart ?? input.value.length;
        insertCommentText('@', start, input.selectionEnd ?? start);
        const end = start + 1;
        state.commentPicker = { type: 'mention', query: '', start, end, selectedIndex: 0, items: [] };
        renderCommentPicker();
    }

    function updateMentionPicker(input) {
        if (!input || input.id !== 'commentInput') return;
        const caret = input.selectionStart ?? input.value.length;
        const beforeCaret = input.value.slice(0, caret);
        const activeStart = state.commentPicker?.type === 'mention' ? Number(state.commentPicker.start) : -1;
        const typedMatch = beforeCaret.match(/(?:^|[\s([{'"「『、。])@([^\s@]*)$/u);
        const at = activeStart >= 0 && beforeCaret[activeStart] === '@'
            ? activeStart
            : (typedMatch ? beforeCaret.length - typedMatch[1].length - 1 : -1);
        if (at < 0) {
            if (state.commentPicker?.type === 'mention') closeCommentPicker();
            return;
        }
        const query = beforeCaret.slice(at + 1);
        if (/\s/u.test(query) || query.length > 80) {
            if (state.commentPicker?.type === 'mention') closeCommentPicker();
            return;
        }
        state.commentPicker = { type: 'mention', query, start: at, end: caret, selectedIndex: state.commentPicker?.type === 'mention' ? state.commentPicker.selectedIndex : 0, items: [] };
        renderCommentPicker();
    }

    function chooseCommentMention(memberId) {
        const member = state.members.find(item => Number(item.id) === Number(memberId));
        const picker = state.commentPicker;
        if (!member || picker?.type !== 'mention') return;
        const start = picker.start;
        const end = picker.end;
        closeCommentPicker();
        insertCommentText(`@${member.name} `, start, end);
    }

    function chooseCommentEmoji(emoji) {
        closeCommentPicker();
        insertCommentText(emoji);
    }

    async function sendComment() {
        const input = document.getElementById('commentInput');
        const body = input?.value.trim();
        if (!body) return;
        const button = root.querySelector('[data-action="send-comment"]');
        if (button) button.disabled = true;
        closeCommentPicker();
        try {
            await api('add-comment', { method: 'POST', body: { page_id: state.page.id, parent_id: state.replyTo?.id || null, body } });
            const data = await api('page', { query: `&id=${state.page.id}` });
            state.page.comments = data.page.comments;
            state.replyTo = null;
            renderApp();
            toast(t('comments.posted'));
        } catch (err) {
            if (button) button.disabled = false;
            toast(err.message, 'error');
        }
    }

    async function updateUiLocale(locale) {
        try {
            const data = await api('update-ui-locale', { method: 'POST', body: { locale } });
            applyI18n(data.i18n);
            if (state.user) state.user.ui_locale = data.i18n?.locale || locale;
            renderApp();
            toast(t('settings.localeUpdated'));
        } catch (error) {
            toast(error.message, 'error');
            renderApp();
        }
    }

    async function executeTranslation() {
        if (!state.page || state.translationBusy) return;
        const targetLanguage = document.getElementById('translationTarget')?.value || '';
        const provider = document.getElementById('translationProvider')?.value || '';
        const versions = Array.isArray(state.page.translation?.versions) ? state.page.translation.versions : [];
        const descendants = translationDescendants();
        const selectedPageIds = normalizedTranslationSelectedIds();
        const selectedPageIdSet = new Set(selectedPageIds);
        const existing = versions.find(version => version.language === targetLanguage && version.role === 'translation');
        const descendantHasExisting = descendants.some(page => selectedPageIdSet.has(Number(page.page_id))
            && Array.isArray(page.translations)
            && page.translations.some(version => version.language === targetLanguage));
        const hasExisting = Boolean(existing || descendantHasExisting);
        if (!targetLanguage || !provider) return;
        if (hasExisting && !window.confirm(t(selectedPageIds.length ? 'page.translation.confirmReplaceStructure' : 'page.translation.confirmReplace'))) return;
        if (!(await flushPendingPageSave())) {
            toast(t('page.translation.saveFailed'), 'error');
            return;
        }
        state.translationBusy = true;
        renderApp();
        try {
            const data = await api('create-translation', {
                method: 'POST',
                body: {
                    page_id: Number(state.page.id),
                    target_language: targetLanguage,
                    provider,
                    replace_existing: hasExisting,
                    page_ids: selectedPageIds,
                },
            });
            state.dialog = null;
            state.dialogData = null;
            await refreshSummary();
            await openPage(Number(data.page_id));
            const pageCount = Number(data.page_count || 1);
            toast(pageCount > 1
                ? t('page.translation.structureCompleted', { count: pageCount })
                : t(data.created ? 'page.translation.created' : 'page.translation.updated'));
        } catch (error) {
            toast(error.message, 'error');
        } finally {
            state.translationBusy = false;
            if (state.dialog === 'translation') renderApp();
        }
    }

    async function saveOrganizationName() {
        const input = document.getElementById('organizationName');
        const form = document.getElementById('organizationForm');
        const button = form?.querySelector('button[type="submit"]');
        const organizationName = input?.value.trim() || '';
        if (!input || !button) return;
        if (!organizationName) {
            input.focus();
            input.reportValidity();
            return;
        }
        button.disabled = true;
        button.textContent = `${t('common.saving')}…`;
        try {
            const data = await api('update-organization-name', { method: 'POST', body: { organization_name: organizationName } });
            state.organizationName = data.organization_name;
            renderApp();
            toast(t('settings.organizationSaved'));
        } catch (err) {
            button.disabled = false;
            button.textContent = t('common.save');
            toast(err.message, 'error');
        }
    }

    async function submitInvitation() {
        const form = document.getElementById('inviteForm');
        if (!form) return;
        const button = root.querySelector('[data-action="submit-invite"]');
        const error = document.getElementById('inviteError');
        error.textContent = '';
        if (!form.reportValidity()) return;
        const departmentChoice = String(form.elements.department_choice.value || '');
        const department = departmentChoice === '__add_new_department__'
            ? trimDepartmentName(form.elements.new_department.value)
            : trimDepartmentName(departmentChoice);
        if (!department) {
            const departmentInput = departmentChoice === '__add_new_department__'
                ? form.elements.new_department
                : form.elements.department_choice;
            departmentInput.focus();
            departmentInput.setCustomValidity(t('settings.departmentRequired'));
            departmentInput.reportValidity();
            departmentInput.setCustomValidity('');
            return;
        }
        button.disabled = true;
        button.textContent = t('settings.sending');
        try {
            const data = await api('invite-member', { method: 'POST', body: {
                name: form.elements.name.value,
                email: form.elements.email.value,
                department,
                role: form.elements.role.value,
                current_password: form.elements.current_password?.value || '',
            }});
            state.members.push(data.member);
            syncDepartmentsFromMembers();
            state.dialogData = { ...data, mode: 'invite', email: data.member.email };
            state.dialog = 'invitation-result';
            renderApp();
        } catch (err) {
            error.textContent = err.message;
            button.disabled = false;
            button.textContent = t('settings.sendInvitation');
        }
    }

    async function resendInvitation(memberId) {
        const member = state.members.find(item => Number(item.id) === Number(memberId));
        if (!member || !memberInitialLoginPending(member)) return toast(t('settings.pendingMemberNotFound'), 'error');
        if (!window.confirm(t('settings.confirmResendInvitation', { email: member.email }))) return;
        const button = root.querySelector(`[data-action="resend-invitation"][data-member-id="${memberId}"]`);
        if (button) { button.disabled = true; button.textContent = t('settings.resending'); }
        try {
            const data = await api('resend-invitation', { method: 'POST', body: { member_id: Number(memberId) } });
            member.invited_at = data.invited_at;
            state.dialogData = { ...data, mode: 'resend', email: member.email };
            state.dialog = 'invitation-result';
            renderApp();
        } catch (err) {
            await refreshSummary();
            renderApp();
            toast(err.message, 'error');
        }
    }

    async function confirmAccountReactivation() {
        const request = state.dialogData || {};
        const memberId = Number(request.member_id);
        const role = String(request.role || '');
        const member = state.suspendedMembers.find(item => Number(item.id) === memberId);
        if (!member || role === 'suspended' || !roleValues.includes(role)) {
            state.dialog = 'members';
            state.dialogData = null;
            renderApp();
            return toast(t('settings.reviewSuspendedRole'), 'error');
        }
        const button = root.querySelector('[data-action="confirm-reactivation"]');
        if (button) { button.disabled = true; button.textContent = t('settings.sending'); }
        try {
            const data = await api('reactivate-member', { method: 'POST', body: { member_id: memberId, role } });
            state.suspendedMembers = state.suspendedMembers.filter(item => Number(item.id) !== memberId);
            state.members.push(data.member);
            state.members.sort((left, right) => String(left.name).localeCompare(String(right.name), 'ja'));
            syncDepartmentsFromMembers();
            state.dialogData = { ...data, mode: 'reactivate', email: data.member.email };
            state.dialog = 'invitation-result';
            renderApp();
        } catch (err) {
            await refreshSummary();
            state.dialog = 'members';
            state.dialogData = null;
            renderApp();
            toast(err.message, 'error');
        }
    }

    async function deletePendingInvitation(memberId) {
        const member = state.members.find(item => Number(item.id) === Number(memberId));
        if (!member || !memberInitialLoginPending(member)) return toast(t('settings.pendingMemberNotFound'), 'error');
        if (!window.confirm(t('settings.confirmDeleteInvitation', { name: member.name }))) return;
        const button = root.querySelector(`[data-action="delete-pending-invitation"][data-member-id="${memberId}"]`);
        if (button) { button.disabled = true; button.textContent = t('settings.deleting'); }
        try {
            await api('delete-pending-invitation', { method: 'POST', body: { member_id: Number(memberId) } });
            state.members = state.members.filter(item => Number(item.id) !== Number(memberId));
            syncDepartmentsFromMembers();
            renderApp();
            toast(t('settings.pendingInvitationDeleted'));
        } catch (err) {
            await refreshSummary();
            renderApp();
            toast(err.message, 'error');
        }
    }

    function openDatabaseConnectionStatus(pluginId) {
        const plugin = state.installedPlugins.find(item => item.id === pluginId);
        const status = plugin?.database_adapter;
        if (!status?.manual_connection_detected || status.canonical_backend !== status.adapter) return;
        state.dialogData = {
            plugin_name: pluginT(plugin.id, 'plugin.name', {}, plugin.name),
            connection_info: status.connection_info || {},
        };
        state.dialog = 'database-connection-status';
        renderApp();
    }

    function openMemberNameEditor(member) {
        if (!state.capabilities.can_manage_members || !member) return;
        state.dialogData = { member_id: Number(member.id), name: member.name, email: member.email };
        state.dialog = 'member-name';
        renderApp();
        requestAnimationFrame(() => {
            const input = document.getElementById('memberNameInput');
            input?.focus();
            input?.select();
        });
    }

    function applyMemberName(memberId, name) {
        if (Number(state.user?.id) === memberId) state.user.name = name;
        [...state.members, ...state.suspendedMembers].forEach(member => {
            if (Number(member.id) === memberId) member.name = name;
        });
        [...state.pages, ...state.trash].forEach(page => {
            if (Number(page.author_id) === memberId) page.author_name = name;
        });
        if (Number(state.page?.author_id) === memberId) state.page.author_name = name;
        const updateComment = comment => {
            if (Number(comment.user_id || comment.created_by) === memberId) comment.user_name = name;
            (comment.replies || []).forEach(updateComment);
        };
        (state.page?.comments || []).forEach(updateComment);
        state.members.sort((left, right) => String(left.name).localeCompare(String(right.name), 'ja'));
        state.suspendedMembers.sort((left, right) => String(left.name).localeCompare(String(right.name), 'ja'));
    }

    async function saveMemberName() {
        const request = state.dialogData || {};
        const memberId = Number(request.member_id || 0);
        const form = document.getElementById('memberNameForm');
        const input = document.getElementById('memberNameInput');
        const button = root.querySelector('[data-action="save-member-name"]');
        const error = document.getElementById('memberNameError');
        if (!memberId || !form || !input || !button || !form.reportValidity()) return;
        const name = input.value.trim();
        if (!name) {
            input.focus();
            input.reportValidity();
            return;
        }
        if (error) error.textContent = '';
        button.disabled = true;
        button.textContent = `${t('common.saving')}…`;
        try {
            const data = await api('update-member-name', { method: 'POST', body: { member_id: memberId, name } });
            applyMemberName(memberId, data.name);
            state.dialog = 'members';
            state.dialogData = null;
            renderApp();
            toast(t('settings.memberNameUpdated'));
        } catch (err) {
            if (error) error.textContent = err.message;
            button.disabled = false;
            button.textContent = t('common.save');
        }
    }

    function openAdministratorConfirmation(kind, member, detail = {}) {
        state.dialogData = {
            kind,
            member_id: Number(member.id),
            member_name: member.name,
            email: member.email,
            ...detail,
        };
        state.dialog = 'administrator-confirmation';
        renderApp();
        requestAnimationFrame(() => document.getElementById('administratorCurrentPassword')?.focus());
    }

    async function confirmAdministratorAction() {
        const request = state.dialogData || {};
        const memberId = Number(request.member_id || 0);
        const member = state.members.find(item => Number(item.id) === memberId);
        const suspendedMember = state.suspendedMembers.find(item => Number(item.id) === memberId);
        const password = document.getElementById('administratorCurrentPassword')?.value || '';
        const button = root.querySelector('[data-action="confirm-administrator-action"]');
        const error = document.getElementById('administratorActionError');
        if (!memberId || !password || !button) {
            document.getElementById('administratorCurrentPassword')?.reportValidity();
            return;
        }
        if (error) error.textContent = '';
        button.disabled = true;
        button.textContent = t('settings.adminAction.processing');
        try {
            if (request.kind === 'role') {
                const data = await api('update-member-role', { method: 'POST', body: {
                    member_id: memberId,
                    role: request.role,
                    current_password: password,
                }});
                if (member) member.role = data.role;
                state.dialog = 'members';
                state.dialogData = null;
                renderApp();
                toast(t('settings.roleUpdated'));
                return;
            }
            if (request.kind === 'suspend') {
                const data = await api('suspend-member', { method: 'POST', body: {
                    member_id: memberId,
                    current_password: password,
                }});
                state.members = state.members.filter(item => Number(item.id) !== memberId);
                state.suspendedMembers.push(data.member);
                syncDepartmentsFromMembers();
                state.dialog = 'members';
                state.dialogData = null;
                renderApp();
                toast(t('settings.accountSuspended'));
                return;
            }
            if (request.kind === 'reset') {
                const data = await api('reset-member-password', { method: 'POST', body: {
                    member_id: memberId,
                    current_password: password,
                }});
                if (member) Object.assign(member, data.member, { initial_login_pending: false });
                state.dialogData = { ...data, mode: 'reset', email: data.member.email };
                state.dialog = 'invitation-result';
                renderApp();
                return;
            }
            if (request.kind === 'resend') {
                const data = await api('resend-invitation', { method: 'POST', body: {
                    member_id: memberId,
                    current_password: password,
                }});
                if (member) member.invited_at = data.invited_at;
                state.dialogData = { ...data, mode: 'resend', email: request.email };
                state.dialog = 'invitation-result';
                renderApp();
                return;
            }
            if (request.kind === 'delete') {
                await api('delete-pending-invitation', { method: 'POST', body: {
                    member_id: memberId,
                    current_password: password,
                }});
                state.members = state.members.filter(item => Number(item.id) !== memberId);
                syncDepartmentsFromMembers();
                state.dialog = 'members';
                state.dialogData = null;
                renderApp();
                toast(t('settings.pendingInvitationDeleted'));
                return;
            }
            if (request.kind === 'reactivate') {
                const data = await api('reactivate-member', { method: 'POST', body: {
                    member_id: memberId,
                    role: 'admin',
                    current_password: password,
                }});
                state.suspendedMembers = state.suspendedMembers.filter(item => Number(item.id) !== memberId);
                state.members.push(data.member);
                state.members.sort((left, right) => String(left.name).localeCompare(String(right.name), 'ja'));
                syncDepartmentsFromMembers();
                state.dialogData = { ...data, mode: 'reactivate', email: data.member.email };
                state.dialog = 'invitation-result';
                renderApp();
                return;
            }
            throw new Error(t('settings.adminAction.invalid'));
        } catch (err) {
            if (error) error.textContent = err.message;
            button.disabled = false;
            button.textContent = t('settings.adminAction.confirm');
        }
    }

    async function toggleFavorite(id = state.page?.id) {
        if (!id) return;
        try {
            await api('favorite', { method: 'POST', body: { id } });
            const page = pageById(id); if (page) page.is_favorite = !page.is_favorite;
            if (state.page?.id === Number(id)) state.page.is_favorite = !state.page.is_favorite;
            state.context = null; renderApp();
        } catch (err) { toast(err.message, 'error'); }
    }

    async function archivePage(id) {
        if (!id) return;
        try {
            await api('archive-page', { method: 'POST', body: { id } });
            const currentPageWasArchived = state.page && pagePath(state.page.id).some(page => page.id === Number(id));
            await refreshSummary();
            state.context = null;
            if (currentPageWasArchived || state.page && !pageById(state.page.id)) await showTrash(); else renderApp();
            toast(t('page.movedToTrash'));
        } catch (err) { toast(err.message, 'error'); }
    }

    async function restorePage(id) {
        try {
            await api('restore-page', { method: 'POST', body: { id } });
            await refreshSummary();
            await openPage(id);
            toast(t('page.restored'));
        } catch (err) { toast(err.message, 'error'); }
    }

    async function deletePagePermanently(id, title) {
        if (!window.confirm(t('page.confirmPermanentDelete', { title }))) return;
        try {
            await api('delete-page-permanently', { method: 'POST', body: { id } });
            await refreshSummary();
            renderApp();
            toast(t('page.deletedPermanently'));
        } catch (err) { toast(err.message, 'error'); }
    }

    async function deleteFile(id, title) {
        const file = state.files.find(item => Number(item.id) === Number(id));
        const references = Array.isArray(file?.references) ? file.references : [];
        const usageWarning = references.length
            ? t('files.deleteInUseWarning', { count: references.length })
            : t('files.deleteUnusedWarning');
        if (!window.confirm(t('files.confirmDelete', { title, warning: usageWarning }))) return;
        try {
            await api('delete-file', { method: 'POST', body: { id } });
            state.files = state.files.filter(file => Number(file.id) !== Number(id));
            renderApp();
            toast(t('files.deleted'));
        } catch (err) {
            toast(err.message, 'error');
        }
    }

    function selectFileForBlock(blockId) {
        const block = state.page?.blocks.find(item => item.id === blockId);
        if (!block) return;
        const input = document.createElement('input');
        input.type = 'file';
        input.accept = block.type === 'image' ? 'image/png,image/jpeg,image/gif,image/webp' : '.pdf,.xls,.xlsx,.doc,.docx,.md,.markdown,.txt,.png,.jpg,.jpeg,.gif,.webp';
        input.hidden = true;
        document.body.appendChild(input);
        input.addEventListener('change', async () => {
            const file = input.files?.[0];
            input.remove();
            if (file) await uploadFileForBlock(blockId, file);
        }, { once: true });
        input.addEventListener('cancel', () => input.remove(), { once: true });
        input.click();
    }

    async function uploadFileForBlock(blockId, file) {
        const block = state.page?.blocks.find(item => item.id === blockId);
        if (!block || !state.page) return;
        if (block.type === 'image' && !['image/png', 'image/jpeg', 'image/gif', 'image/webp'].includes(file.type)) {
            toast(t('editor.imageTypeError'), 'error');
            return;
        }
        block.uploading = true;
        renderEditorOnly();
        try {
            const formData = new FormData();
            formData.append('file', file);
            formData.append('page_id', String(state.page.id));
            const response = await fetch('api.php?action=upload-file', {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-CSRF-Token': state.csrf },
                body: formData,
            });
            const data = await response.json().catch(() => ({ error: t('common.invalidServerResponse') }));
            if (!response.ok) throw new Error(data.error || t('editor.uploadFailed'));
            if (block.type === 'image' && data.file.category !== 'image') throw new Error(t('editor.invalidImageFile'));
            Object.assign(block, {
                uploading: false,
                file_id: data.file.id,
                file_name: data.file.original_name,
                file_category: data.file.category,
                file_size: data.file.size_bytes,
            });
            state.files = [data.file, ...state.files.filter(item => Number(item.id) !== Number(data.file.id))];
            renderEditorOnly();
            scheduleSave(true);
            toast(block.type === 'image' ? t('editor.imagePlaced') : t('editor.fileUploaded'));
        } catch (err) {
            block.uploading = false;
            renderEditorOnly();
            toast(err.message, 'error');
        }
    }

    function selectTableCell(target, extend = false) {
        const blockId = target.dataset.blockId;
        const row = Number(target.dataset.row);
        const col = Number(target.dataset.col);
        const current = state.tableSelection;
        const keepAnchor = extend && current?.blockId === blockId;
        state.tableSelection = {
            blockId,
            anchorRow: keepAnchor ? Number(current.anchorRow ?? current.row ?? row) : row,
            anchorCol: keepAnchor ? Number(current.anchorCol ?? current.col ?? col) : col,
            focusRow: row,
            focusCol: col,
            row,
            col,
        };
        updateTableSelectionVisuals(blockId);
    }

    function updateTableSelectionVisuals(blockId) {
        const selection = state.tableSelection?.blockId === blockId ? state.tableSelection : null;
        const block = state.page?.blocks.find(item => item.id === blockId);
        const wrapper = root.querySelector(`[data-block-id="${CSS.escape(blockId)}"] .table-block`);
        if (!selection || !block || !wrapper) return;
        root.querySelectorAll('.table-block').forEach(tableBlock => {
            if (tableBlock === wrapper) return;
            const toolbar = tableBlock.querySelector('.table-toolbar');
            toolbar?.classList.remove('visible');
            toolbar?.setAttribute('aria-hidden', 'true');
            tableBlock.querySelectorAll('.table-cell').forEach(cell => {
                cell.classList.remove('selected', 'selection-focus');
                cell.setAttribute('aria-selected', 'false');
            });
            const label = tableBlock.querySelector('.table-toolbar-label');
            if (label) label.textContent = t('editor.selectCells');
            tableBlock.querySelectorAll('[data-action="table-align"],[data-action="table-background"],[data-action="table-delete-row"],[data-action="table-delete-column"],[data-action="table-copy"],[data-action="table-paste"]').forEach(control => {
                control.disabled = true;
                control.classList.remove('active');
            });
        });
        const toolbar = wrapper.querySelector('.table-toolbar');
        toolbar?.classList.add('visible');
        toolbar?.setAttribute('aria-hidden', 'false');
        const bounds = tableSelectionBounds(selection);
        wrapper.querySelectorAll('.table-cell').forEach(cell => {
            const row = Number(cell.dataset.row);
            const col = Number(cell.dataset.col);
            cell.classList.toggle('selected', tableCellIsSelected(selection, row, col));
            cell.classList.toggle('selection-focus', row === selection.focusRow && col === selection.focusCol);
            cell.setAttribute('aria-selected', tableCellIsSelected(selection, row, col) ? 'true' : 'false');
        });
        const cells = selectedTableCells(block, selection);
        const alignments = new Set(cells.map(cell => cell.align));
        const selectedAlign = alignments.size === 1 ? [...alignments][0] : '';
        wrapper.querySelectorAll('[data-action="table-align"]').forEach(button => {
            button.disabled = false;
            button.classList.toggle('active', button.dataset.align === selectedAlign);
        });
        const backgrounds = new Set(cells.map(cell => normalizeTableBackground(cell.background)));
        const selectedBackground = backgrounds.size === 1 ? [...backgrounds][0] : '';
        const backgroundSelect = wrapper.querySelector('[data-action="table-background"]');
        const backgroundSwatch = wrapper.querySelector('.table-background-swatch');
        if (backgroundSelect) {
            backgroundSelect.disabled = false;
            backgroundSelect.value = selectedBackground;
        }
        if (backgroundSwatch) backgroundSwatch.className = `table-background-swatch background-${selectedBackground || 'mixed'}`;
        const selectedRows = bounds.endRow - bounds.startRow + 1;
        const selectedColumns = bounds.endCol - bounds.startCol + 1;
        const label = wrapper.querySelector('.table-toolbar-label');
        if (label) label.textContent = t('editor.cellsSelected', { count: selectedRows * selectedColumns });
        const deleteRow = wrapper.querySelector('[data-action="table-delete-row"]');
        const deleteColumn = wrapper.querySelector('[data-action="table-delete-column"]');
        const copy = wrapper.querySelector('[data-action="table-copy"]');
        const paste = wrapper.querySelector('[data-action="table-paste"]');
        if (deleteRow) deleteRow.disabled = selectedRows >= block.table_rows.length;
        if (deleteColumn) deleteColumn.disabled = selectedColumns >= block.table_widths.length;
        if (copy) copy.disabled = false;
        if (paste) paste.disabled = !state.tableClipboard;
    }

    function clearTableSelection() {
        state.tableSelection = null;
        root.querySelectorAll('.table-block').forEach(tableBlock => {
            const toolbar = tableBlock.querySelector('.table-toolbar');
            toolbar?.classList.remove('visible');
            toolbar?.setAttribute('aria-hidden', 'true');
            tableBlock.querySelectorAll('.table-cell').forEach(cell => {
                cell.classList.remove('selected', 'selection-focus');
                cell.setAttribute('aria-selected', 'false');
            });
        });
    }

    function setTableAlignment(blockId, align) {
        if (!['left', 'center', 'right'].includes(align) || state.tableSelection?.blockId !== blockId) return;
        const block = state.page?.blocks.find(item => item.id === blockId);
        if (!block) return;
        selectedTableCells(block).forEach(cell => { cell.align = align; });
        const wrapper = root.querySelector(`[data-block-id="${CSS.escape(blockId)}"]`);
        wrapper?.querySelectorAll('.table-cell.selected').forEach(cell => {
            cell.classList.remove('align-left', 'align-center', 'align-right');
            cell.classList.add(`align-${align}`);
        });
        updateTableSelectionVisuals(blockId);
        scheduleSave();
        const { focusRow, focusCol } = state.tableSelection;
        setTimeout(() => root.querySelector(`.table-cell-content[data-block-id="${CSS.escape(blockId)}"][data-row="${focusRow}"][data-col="${focusCol}"]`)?.focus({ preventScroll: true }), 20);
    }

    function setTableBackground(blockId, background) {
        if (normalizeTableBackground(background) !== background || state.tableSelection?.blockId !== blockId) return;
        const block = state.page?.blocks.find(item => item.id === blockId);
        if (!block) return;
        selectedTableCells(block).forEach(cell => { cell.background = background; });
        const { focusRow, focusCol } = state.tableSelection;
        renderEditorOnly();
        scheduleSave();
        focusTableCell(blockId, focusRow, focusCol, true);
    }

    function addTableRow(blockId, useSelectionPosition = true) {
        const block = state.page?.blocks.find(item => item.id === blockId);
        if (!block) return;
        normalizeTableBlock(block);
        if (block.table_rows.length >= 50) return toast(t('editor.tableMaxRows'), 'error');
        syncEditorFromDom();
        const bounds = useSelectionPosition && state.tableSelection?.blockId === blockId ? tableSelectionBounds() : null;
        const insertRow = bounds ? bounds.startRow : block.table_rows.length;
        const focusColumn = bounds ? bounds.startCol : 0;
        block.table_rows.splice(insertRow, 0, Array.from({ length: block.table_widths.length }, emptyTableCell));
        renderEditorOnly(); scheduleSave(); focusTableCell(blockId, insertRow, focusColumn);
        toast(bounds ? t('editor.rowAddedAbove') : t('editor.rowAddedBottom'));
    }

    function addTableColumn(blockId) {
        const block = state.page?.blocks.find(item => item.id === blockId);
        if (!block) return;
        normalizeTableBlock(block);
        const oldCount = block.table_widths.length;
        if (oldCount >= 12) return toast(t('editor.tableMaxColumns'), 'error');
        syncEditorFromDom();
        const bounds = state.tableSelection?.blockId === blockId ? tableSelectionBounds() : null;
        const insertColumn = bounds ? bounds.startCol : oldCount;
        const focusRow = bounds ? bounds.startRow : 0;
        const scale = oldCount / (oldCount + 1);
        block.table_widths = block.table_widths.map(width => width * scale);
        block.table_widths.splice(insertColumn, 0, 100 / (oldCount + 1));
        block.table_rows.forEach(row => row.splice(insertColumn, 0, emptyTableCell()));
        renderEditorOnly(); scheduleSave(); focusTableCell(blockId, focusRow, insertColumn);
        toast(bounds ? t('editor.columnAddedLeft') : t('editor.columnAddedRight'));
    }

    function deleteTableRows(blockId) {
        const block = state.page?.blocks.find(item => item.id === blockId);
        if (!block || state.tableSelection?.blockId !== blockId) return;
        normalizeTableBlock(block);
        const bounds = tableSelectionBounds();
        const deleteCount = bounds.endRow - bounds.startRow + 1;
        if (deleteCount >= block.table_rows.length) return toast(t('editor.tableMinRow'), 'error');
        syncEditorFromDom();
        block.table_rows.splice(bounds.startRow, deleteCount);
        const nextRow = Math.min(bounds.startRow, block.table_rows.length - 1);
        const nextCol = Math.min(bounds.startCol, block.table_widths.length - 1);
        renderEditorOnly();
        scheduleSave();
        focusTableCell(blockId, nextRow, nextCol);
        toast(t('editor.rowsDeleted', { count: deleteCount }));
    }

    function deleteTableColumns(blockId) {
        const block = state.page?.blocks.find(item => item.id === blockId);
        if (!block || state.tableSelection?.blockId !== blockId) return;
        normalizeTableBlock(block);
        const bounds = tableSelectionBounds();
        const deleteCount = bounds.endCol - bounds.startCol + 1;
        if (deleteCount >= block.table_widths.length) return toast(t('editor.tableMinColumn'), 'error');
        syncEditorFromDom();
        block.table_rows.forEach(row => row.splice(bounds.startCol, deleteCount));
        block.table_widths.splice(bounds.startCol, deleteCount);
        const total = block.table_widths.reduce((sum, width) => sum + Number(width || 0), 0) || 100;
        block.table_widths = block.table_widths.map(width => Number(width || 0) * 100 / total);
        const nextRow = Math.min(bounds.startRow, block.table_rows.length - 1);
        const nextCol = Math.min(bounds.startCol, block.table_widths.length - 1);
        renderEditorOnly();
        scheduleSave();
        focusTableCell(blockId, nextRow, nextCol);
        toast(t('editor.columnsDeleted', { count: deleteCount }));
    }

    function sanitizeTableCellHtml(html) {
        const template = document.createElement('template');
        template.innerHTML = String(html || '');
        const allowedTags = new Set(['STRONG', 'B', 'EM', 'I', 'U', 'S', 'DEL', 'CODE', 'A', 'MARK', 'BR']);
        const cleanNode = node => {
            [...node.childNodes].forEach(child => {
                if (child.nodeType !== Node.ELEMENT_NODE) return;
                if (!allowedTags.has(child.tagName)) {
                    cleanNode(child);
                    child.replaceWith(...child.childNodes);
                    return;
                }
                const href = child.tagName === 'A' ? child.getAttribute('href') || '' : '';
                [...child.attributes].forEach(attribute => child.removeAttribute(attribute.name));
                if (child.tagName === 'A') {
                    if (/^(https?:\/\/|mailto:)/i.test(href)) {
                        child.setAttribute('href', href);
                        child.setAttribute('target', '_blank');
                        child.setAttribute('rel', 'noopener noreferrer');
                    }
                }
                cleanNode(child);
            });
        };
        cleanNode(template.content);
        return template.innerHTML.slice(0, 20000);
    }

    function captureTableSelection(blockId) {
        const block = state.page?.blocks.find(item => item.id === blockId);
        if (!block || state.tableSelection?.blockId !== blockId) return null;
        syncEditorFromDom();
        const bounds = tableSelectionBounds();
        return {
            rows: block.table_rows.slice(bounds.startRow, bounds.endRow + 1).map(row =>
                row.slice(bounds.startCol, bounds.endCol + 1).map(cell => ({
                    content: sanitizeTableCellHtml(cell.content),
                    align: ['left', 'center', 'right'].includes(cell.align) ? cell.align : 'left',
                    background: normalizeTableBackground(cell.background),
                }))
            ),
            widths: block.table_widths.slice(bounds.startCol, bounds.endCol + 1).map(Number),
        };
    }

    function tableClipboardText(clipboard) {
        const template = document.createElement('template');
        return clipboard.rows.map(row => row.map(cell => {
            template.innerHTML = cell.content;
            return (template.content.textContent || '').replace(/\t/g, ' ').replace(/\r?\n/g, ' ');
        }).join('\t')).join('\n');
    }

    function tableClipboardHtml(clipboard) {
        const columns = clipboard.widths.map(width => `<col data-width="${Number(width)}">`).join('');
        const rows = clipboard.rows.map(row => `<tr>${row.map(cell => `<td data-align="${cell.align}" data-background="${normalizeTableBackground(cell.background)}" style="text-align:${cell.align}">${cell.content}</td>`).join('')}</tr>`).join('');
        return `<table data-openconcept-table="1"><colgroup>${columns}</colgroup><tbody>${rows}</tbody></table>`;
    }

    function enableTablePasteButtons() {
        root.querySelectorAll('[data-action="table-paste"]').forEach(button => {
            button.disabled = !state.tableClipboard || state.tableSelection?.blockId !== button.dataset.id;
        });
    }

    async function copyTableSelection(blockId, writeSystemClipboard = true) {
        const clipboard = captureTableSelection(blockId);
        if (!clipboard) return;
        state.tableClipboard = clipboard;
        enableTablePasteButtons();
        if (writeSystemClipboard && navigator.clipboard) {
            const plainText = tableClipboardText(clipboard);
            try {
                if (window.ClipboardItem && navigator.clipboard.write) {
                    await navigator.clipboard.write([new ClipboardItem({
                        'text/plain': new Blob([plainText], { type: 'text/plain' }),
                        'text/html': new Blob([tableClipboardHtml(clipboard)], { type: 'text/html' }),
                    })]);
                } else if (navigator.clipboard.writeText) {
                    await navigator.clipboard.writeText(plainText);
                }
            } catch {
                // The internal clipboard still enables the toolbar paste action.
            }
        }
        toast(t('editor.tableCopied', { rows: clipboard.rows.length, columns: clipboard.rows[0]?.length || 0 }));
    }

    function tableClipboardFromHtml(html) {
        if (!html) return null;
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const table = doc.querySelector('table[data-openconcept-table="1"]');
        if (!table) return null;
        const rows = [...table.rows].slice(0, 50).map(row => [...row.cells].slice(0, 12).map(cell => ({
            content: sanitizeTableCellHtml(cell.innerHTML),
            align: ['left', 'center', 'right'].includes(cell.dataset.align)
                ? cell.dataset.align
                : ['left', 'center', 'right'].includes(cell.style.textAlign) ? cell.style.textAlign : 'left',
            background: normalizeTableBackground(cell.dataset.background),
        })));
        if (!rows.length || !rows[0].length) return null;
        const columnCount = Math.min(12, Math.max(...rows.map(row => row.length)));
        rows.forEach(row => {
            while (row.length < columnCount) row.push(emptyTableCell());
        });
        let widths = [...table.querySelectorAll('col')].slice(0, columnCount).map(col => Number(col.dataset.width));
        if (widths.length !== columnCount || widths.some(width => !Number.isFinite(width) || width <= 0)) {
            widths = Array.from({ length: columnCount }, () => 100 / columnCount);
        }
        return { rows, widths };
    }

    function tableClipboardFromText(text) {
        if (!text || (!text.includes('\t') && !/\r?\n/.test(text))) return null;
        const rawRows = text.replace(/\r/g, '').split('\n').filter(row => row.replace(/\t/g, '').trim() !== '');
        const splitRows = rawRows.slice(0, 50).map(row => row.split('\t').slice(0, 12));
        if (!splitRows.length) return null;
        const columnCount = Math.max(1, ...splitRows.map(row => row.length));
        const rows = splitRows.map(row => Array.from({ length: columnCount }, (_, index) => ({
            content: esc(normalizePastedPlainText(row[index] || '')),
            align: 'left',
            background: 'default',
        })));
        return { rows, widths: Array.from({ length: columnCount }, () => 100 / columnCount) };
    }

    function tableClipboardFromExternalHtml(html) {
        if (!html) return null;
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const table = doc.querySelector('table:not([data-openconcept-table="1"])');
        if (!table) return null;
        const grid = [];
        [...table.rows].slice(0, 50).forEach((sourceRow, rowIndex) => {
            if (!grid[rowIndex]) grid[rowIndex] = [];
            let columnIndex = 0;
            [...sourceRow.cells].forEach(sourceCell => {
                while (grid[rowIndex][columnIndex] !== undefined) columnIndex++;
                if (columnIndex >= 12) return;
                const columnSpan = Math.max(1, Math.min(12 - columnIndex, Number(sourceCell.colSpan) || 1));
                const rowSpan = Math.max(1, Math.min(50 - rowIndex, Number(sourceCell.rowSpan) || 1));
                for (let rowOffset = 0; rowOffset < rowSpan; rowOffset++) {
                    if (!grid[rowIndex + rowOffset]) grid[rowIndex + rowOffset] = [];
                    for (let columnOffset = 0; columnOffset < columnSpan; columnOffset++) {
                        grid[rowIndex + rowOffset][columnIndex + columnOffset] = {
                            content: rowOffset === 0 && columnOffset === 0
                                ? esc(normalizePastedPlainText(sourceCell.textContent || ''))
                                : '',
                            align: 'left',
                            background: 'default',
                        };
                    }
                }
                columnIndex += columnSpan;
            });
        });
        const rows = grid.slice(0, 50).filter(row => Array.isArray(row));
        if (!rows.length) return null;
        const columnCount = Math.min(12, Math.max(1, ...rows.map(row => row.length)));
        rows.forEach(row => {
            for (let column = 0; column < columnCount; column++) {
                if (!row[column]) row[column] = emptyTableCell();
            }
            row.splice(columnCount);
        });
        return { rows, widths: Array.from({ length: columnCount }, () => 100 / columnCount) };
    }

    function isExcelClipboardHtml(html) {
        if (!html || /(?:Word\.Document|office:word|Microsoft\s+Word)/i.test(html)) return false;
        return /(?:xmlns:x\s*=\s*["'][^"']*office:excel|urn:schemas-microsoft-com:office:excel|<x:ExcelWorkbook\b)/i.test(html)
            || /<meta\b(?=[^>]*(?:ProgId|Generator))(?=[^>]*(?:Excel\.Sheet|Microsoft\s+Excel))[^>]*>/i.test(html);
    }

    function tableClipboardFromData(clipboardData) {
        const html = clipboardData?.getData('text/html') || '';
        const internalTable = tableClipboardFromHtml(html);
        if (internalTable) return internalTable;
        if (!isExcelClipboardHtml(html)) return null;
        const plainText = clipboardPlainText(clipboardData);
        if (plainText.includes('\t')) return tableClipboardFromText(plainText);
        return tableClipboardFromExternalHtml(html);
    }

    function insertTableFromClipboard(target, clipboard) {
        if (!state.page || !clipboard?.rows?.length || !clipboard.rows[0]?.length) return;
        syncEditorFromDom();
        const targetIndex = state.page.blocks.findIndex(block => block.id === target.dataset.id);
        if (targetIndex < 0) return;
        const replaceTarget = target.textContent.trim() === '';
        const block = replaceTarget ? state.page.blocks[targetIndex] : { id: uid() };
        const rowCount = Math.min(50, clipboard.rows.length);
        const columnCount = Math.min(12, Math.max(1, ...clipboard.rows.slice(0, rowCount).map(row => row.length)));
        const tableRows = clipboard.rows.slice(0, rowCount).map(row => Array.from({ length: columnCount }, (_, column) => {
            const cell = row[column] || emptyTableCell();
            return {
                content: sanitizeTableCellHtml(cell.content),
                align: ['left', 'center', 'right'].includes(cell.align) ? cell.align : 'left',
                background: normalizeTableBackground(cell.background),
            };
        }));
        let tableWidths = (clipboard.widths || []).slice(0, columnCount).map(Number);
        if (tableWidths.length !== columnCount || tableWidths.some(width => !Number.isFinite(width) || width <= 0)) {
            tableWidths = Array.from({ length: columnCount }, () => 100 / columnCount);
        }
        Object.assign(block, { type: 'table', content: '', table_rows: tableRows, table_widths: tableWidths });
        if (!replaceTarget) state.page.blocks.splice(targetIndex + 1, 0, block);
        state.tableSelection = {
            blockId: block.id,
            anchorRow: 0,
            anchorCol: 0,
            focusRow: rowCount - 1,
            focusCol: columnCount - 1,
            row: rowCount - 1,
            col: columnCount - 1,
        };
        renderEditorOnly();
        scheduleSave();
        setTimeout(() => {
            updateTableSelectionVisuals(block.id);
            root.querySelector(`.table-cell-content[data-block-id="${CSS.escape(block.id)}"][data-row="${rowCount - 1}"][data-col="${columnCount - 1}"]`)?.focus({ preventScroll: true });
        }, 20);
        toast(t('editor.tablePasted', { rows: rowCount, columns: columnCount }));
    }

    function appendTableColumnData(block) {
        const oldCount = block.table_widths.length;
        const scale = oldCount / (oldCount + 1);
        block.table_widths = [...block.table_widths.map(width => Number(width) * scale), 100 / (oldCount + 1)];
        block.table_rows.forEach(row => row.push(emptyTableCell()));
    }

    function pasteTableClipboard(blockId, clipboard = state.tableClipboard) {
        const block = state.page?.blocks.find(item => item.id === blockId);
        if (!block || !clipboard || state.tableSelection?.blockId !== blockId) return;
        normalizeTableBlock(block);
        syncEditorFromDom();
        const bounds = tableSelectionBounds();
        const startRow = bounds.startRow;
        const startCol = bounds.startCol;
        const rowCount = Math.min(clipboard.rows.length, 50 - startRow);
        const columnCount = Math.min(clipboard.rows[0]?.length || 0, 12 - startCol);
        if (!rowCount || !columnCount) return toast(t('editor.noPasteRange'), 'error');
        while (block.table_rows.length < startRow + rowCount) {
            block.table_rows.push(Array.from({ length: block.table_widths.length }, emptyTableCell));
        }
        while (block.table_widths.length < startCol + columnCount) appendTableColumnData(block);
        for (let row = 0; row < rowCount; row++) {
            for (let col = 0; col < columnCount; col++) {
                const source = clipboard.rows[row]?.[col] || emptyTableCell();
                block.table_rows[startRow + row][startCol + col] = {
                    content: sanitizeTableCellHtml(source.content),
                    align: ['left', 'center', 'right'].includes(source.align) ? source.align : 'left',
                    background: normalizeTableBackground(source.background),
                };
            }
        }
        const sourceWidths = clipboard.widths.slice(0, columnCount).map(Number);
        const sourceTotal = sourceWidths.reduce((sum, width) => sum + (Number.isFinite(width) && width > 0 ? width : 0), 0);
        if (sourceTotal > 0) {
            const targetTotal = block.table_widths.slice(startCol, startCol + columnCount).reduce((sum, width) => sum + Number(width || 0), 0);
            sourceWidths.forEach((width, index) => {
                block.table_widths[startCol + index] = targetTotal * (Number.isFinite(width) && width > 0 ? width : sourceTotal / columnCount) / sourceTotal;
            });
        }
        state.tableSelection = {
            blockId,
            anchorRow: startRow,
            anchorCol: startCol,
            focusRow: startRow + rowCount - 1,
            focusCol: startCol + columnCount - 1,
            row: startRow + rowCount - 1,
            col: startCol + columnCount - 1,
        };
        renderEditorOnly();
        scheduleSave();
        setTimeout(() => {
            updateTableSelectionVisuals(blockId);
            root.querySelector(`.table-cell-content[data-block-id="${CSS.escape(blockId)}"][data-row="${startRow + rowCount - 1}"][data-col="${startCol + columnCount - 1}"]`)?.focus({ preventScroll: true });
        }, 20);
        if (rowCount < clipboard.rows.length || columnCount < (clipboard.rows[0]?.length || 0)) {
            toast(t('editor.tablePasteTruncated'), 'error');
        } else {
            toast(t('editor.cellsPasted', { rows: rowCount, columns: columnCount }));
        }
    }

    function startTableRangeSelection(target, event) {
        if (event.button !== 0 || !canEditCurrentPage()) return;
        selectTableCell(target, event.shiftKey);
        const table = target.closest('table');
        const blockId = target.dataset.blockId;
        let dragging = false;
        const onMove = moveEvent => {
            if (!(moveEvent.buttons & 1)) return onEnd();
            const cell = document.elementFromPoint(moveEvent.clientX, moveEvent.clientY)?.closest('.table-cell');
            const content = cell?.querySelector('.table-cell-content');
            if (!content || content.dataset.blockId !== blockId) return;
            const row = Number(content.dataset.row);
            const col = Number(content.dataset.col);
            if (row === state.tableSelection?.focusRow && col === state.tableSelection?.focusCol) return;
            dragging = true;
            moveEvent.preventDefault();
            getSelection()?.removeAllRanges();
            table?.classList.add('range-selecting');
            Object.assign(state.tableSelection, { focusRow: row, focusCol: col, row, col });
            updateTableSelectionVisuals(blockId);
        };
        const onEnd = () => {
            document.removeEventListener('pointermove', onMove);
            document.removeEventListener('pointerup', onEnd);
            document.removeEventListener('pointercancel', onEnd);
            table?.classList.remove('range-selecting');
            if (dragging) getSelection()?.removeAllRanges();
        };
        document.addEventListener('pointermove', onMove);
        document.addEventListener('pointerup', onEnd, { once: true });
        document.addEventListener('pointercancel', onEnd, { once: true });
    }

    function hasTextSelectionInsideTableCell(target) {
        const selection = getSelection();
        if (!selection || selection.isCollapsed || selection.rangeCount === 0) return false;
        const isInside = node => {
            if (!node) return false;
            const element = node.nodeType === Node.ELEMENT_NODE ? node : node.parentElement;
            return element === target || target.contains(element);
        };
        return isInside(selection.anchorNode) && isInside(selection.focusNode);
    }

    function clipboardPlainText(clipboardData) {
        const plainText = clipboardData?.getData('text/plain') || '';
        if (plainText) return plainText;
        const html = clipboardData?.getData('text/html') || '';
        if (!html) return '';
        return new DOMParser().parseFromString(html, 'text/html').body.textContent || '';
    }

    function normalizePastedPlainText(value, preserveLineBreaks = false) {
        const normalized = String(value || '')
            .replace(/\r\n?/g, '\n')
            .replace(/[\u2028\u2029]/g, '\n')
            .replace(/\u00a0/g, ' ')
            .replace(/[\u200B-\u200D\uFEFF]/g, '');
        if (preserveLineBreaks) return normalized;
        return normalized
            .split('\n')
            .map(line => line.replace(/[ \t]+/g, ' ').trim())
            .filter(Boolean)
            .join(' ')
            .trim();
    }

    function insertPlainTextAtSelection(target, text) {
        target.focus({ preventScroll: true });
        document.execCommand(text ? 'insertText' : 'delete', false, text);
        syncEditorFromDom();
        scheduleSave();
    }

    function insertPlainTextWithLineBreaksAtSelection(target, text) {
        const normalized = normalizePastedPlainText(text, true);
        target.focus({ preventScroll: true });
        document.execCommand(normalized ? 'insertHTML' : 'delete', false, esc(normalized).replace(/\n/g, '<br>'));
        syncEditorFromDom();
        scheduleSave();
    }

    function pastedPlainTextLines(value) {
        return normalizePastedPlainText(value, true)
            .split('\n')
            .map(line => line.replace(/[ \t]+/g, ' ').trim())
            .filter(line => line !== '');
    }

    function fragmentHtml(fragment) {
        const container = document.createElement('div');
        container.append(fragment.cloneNode(true));
        return container.innerHTML;
    }

    function pastedTextBlock(sourceBlock, content) {
        return {
            id: uid(),
            type: sourceBlock.type,
            content,
            ...(sourceBlock.type === 'todo' ? { checked: false } : {}),
            ...(sourceBlock.type === 'callout' ? { emoji: sourceBlock.emoji || '💡' } : {}),
        };
    }

    function focusBlockAtTextOffset(id, offset) {
        setTimeout(() => {
            const target = root.querySelector(`.block-content[data-id="${CSS.escape(id)}"]`);
            if (!target) return;
            target.focus({ preventScroll: true });
            const range = document.createRange();
            const walker = document.createTreeWalker(target, NodeFilter.SHOW_TEXT);
            let remaining = Math.max(0, Number(offset) || 0);
            let node = walker.nextNode();
            while (node && remaining > node.textContent.length) {
                remaining -= node.textContent.length;
                node = walker.nextNode();
            }
            if (node) range.setStart(node, Math.min(remaining, node.textContent.length));
            else {
                range.selectNodeContents(target);
                range.collapse(false);
            }
            range.collapse(true);
            const selection = getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
        }, 20);
    }

    function insertPlainTextBlocks(target, value) {
        if (!state.page || !target?.dataset.id) return;
        const lines = pastedPlainTextLines(value);
        if (lines.length <= 1) {
            insertPlainTextAtSelection(target, lines[0] || '');
            return;
        }
        const blockIndex = state.page.blocks.findIndex(block => block.id === target.dataset.id);
        const sourceBlock = state.page.blocks[blockIndex];
        if (!sourceBlock || ['code', 'divider', 'file', 'table'].includes(sourceBlock.type)) {
            insertPlainTextWithLineBreaksAtSelection(target, value);
            return;
        }
        const maxLines = Math.max(1, 501 - state.page.blocks.length);
        const pasteLines = lines.slice(0, maxLines);
        syncEditorFromDom();
        const selection = getSelection();
        const selectionInside = selection?.rangeCount
            && target.contains(selection.anchorNode)
            && target.contains(selection.focusNode);
        const range = selectionInside ? selection.getRangeAt(0).cloneRange() : document.createRange();
        if (!selectionInside) {
            range.selectNodeContents(target);
            range.collapse(false);
        }
        range.deleteContents();
        const beforeRange = document.createRange();
        beforeRange.selectNodeContents(target);
        beforeRange.setEnd(range.startContainer, range.startOffset);
        const afterRange = document.createRange();
        afterRange.selectNodeContents(target);
        afterRange.setStart(range.startContainer, range.startOffset);
        const beforeHtml = fragmentHtml(beforeRange.cloneContents());
        const afterHtml = fragmentHtml(afterRange.cloneContents());
        sourceBlock.content = beforeHtml + esc(pasteLines[0]);
        const addedBlocks = pasteLines.slice(1).map((line, index) => pastedTextBlock(
            sourceBlock,
            esc(line) + (index === pasteLines.length - 2 ? afterHtml : '')
        ));
        state.page.blocks.splice(blockIndex + 1, 0, ...addedBlocks);
        renderEditorOnly();
        scheduleSave();
        const lastBlock = addedBlocks.at(-1);
        if (lastBlock) focusBlockAtTextOffset(lastBlock.id, pasteLines.at(-1).length);
        if (pasteLines.length < lines.length) toast(t('editor.blockPasteTruncated'), 'error');
    }

    function startTableColumnResize(handle, event) {
        const blockId = handle.dataset.id;
        const colIndex = Number(handle.dataset.col);
        const block = state.page?.blocks.find(item => item.id === blockId);
        const table = handle.closest('table');
        if (!block || !table || !block.table_widths[colIndex + 1]) return;
        event.preventDefault();
        event.stopPropagation();
        syncEditorFromDom();
        const tableWidth = table.getBoundingClientRect().width;
        const startX = event.clientX;
        const leftStart = block.table_widths[colIndex];
        const rightStart = block.table_widths[colIndex + 1];
        const pairTotal = leftStart + rightStart;
        const minPercent = Math.min(pairTotal / 2, Math.max(8, 80 / Math.max(tableWidth, 1) * 100));
        table.classList.add('resizing');
        const columns = table.querySelectorAll('col');
        const onMove = moveEvent => {
            const delta = (moveEvent.clientX - startX) / Math.max(tableWidth, 1) * 100;
            const left = Math.max(minPercent, Math.min(pairTotal - minPercent, leftStart + delta));
            const right = pairTotal - left;
            block.table_widths[colIndex] = left;
            block.table_widths[colIndex + 1] = right;
            if (columns[colIndex]) columns[colIndex].style.width = `${left}%`;
            if (columns[colIndex + 1]) columns[colIndex + 1].style.width = `${right}%`;
        };
        const onEnd = () => {
            document.removeEventListener('pointermove', onMove);
            document.removeEventListener('pointerup', onEnd);
            document.removeEventListener('pointercancel', onEnd);
            table.classList.remove('resizing');
            scheduleSave();
        };
        document.addEventListener('pointermove', onMove);
        document.addEventListener('pointerup', onEnd, { once: true });
        document.addEventListener('pointercancel', onEnd, { once: true });
    }

    async function movePage(pageId, targetId, position) {
        try {
            if (!(await flushPendingPageSave())) {
                toast(t('page.moveSaveFailed'), 'error');
                renderApp();
                return;
            }
            const openPageId = Number(state.page?.id || 0);
            const movingOpenPageAncestor = openPageId > 0
                && openPageId !== Number(pageId)
                && pageIsDescendantOf(openPageId, pageId);
            const data = await api('move-page', { method: 'POST', body: { id: pageId, target_id: targetId || null, position } });
            if (position === 'inside' && targetId) state.expanded.add(Number(targetId));
            if (data.parent_id) state.expanded.add(Number(data.parent_id));
            if (state.page?.id === Number(pageId)) {
                state.page.parent_id = data.parent_id === null ? null : Number(data.parent_id);
                state.page.sort_order = Number(data.sort_order || 0);
                const movedAccessState = normalizedPageAccessState(data, state.page);
                Object.assign(state.page, {
                    status: movedAccessState.status,
                    visibility: movedAccessState.visibility,
                    access_departments: [...movedAccessState.access_departments],
                    access_member_ids: [...movedAccessState.access_member_ids],
                    _persisted_access_state: normalizedPageAccessState(movedAccessState),
                });
                resetUndoHistory(pageId);
            }
            let summaryRefreshFailed = false;
            try {
                await refreshSummary();
            } catch (refreshError) {
                summaryRefreshFailed = true;
                console.error('Page moved, but summary refresh failed.', refreshError);
            }
            if (!state.user) return;
            let currentPageRefreshFailed = false;
            let currentPageAccessRemoved = false;
            if (movingOpenPageAncestor && state.page?.id === openPageId) {
                try {
                    const currentPageData = await api('page', { query: `&id=${openPageId}` });
                    if (state.page?.id === openPageId) {
                        state.page = normalizePageAccessDepartments(currentPageData.page);
                        syncPageTags(state.page);
                        resetUndoHistory(openPageId);
                    }
                } catch (refreshError) {
                    if (!state.user) return;
                    if ([403, 404].includes(Number(refreshError.status || 0))) {
                        currentPageAccessRemoved = true;
                        closeTransient();
                        resetUndoHistory();
                        state.view = 'home';
                        state.page = null;
                        state.panel = null;
                        history.replaceState(null, '', location.pathname);
                    } else {
                        currentPageRefreshFailed = true;
                        console.error('Ancestor moved, but the open descendant could not be refreshed.', refreshError);
                    }
                }
            }
            renderApp();
            if (currentPageAccessRemoved) {
                toast(t('page.moveAccessChanged'), 'error');
            } else if (summaryRefreshFailed || currentPageRefreshFailed) {
                toast(t('page.moveRefreshFailed'), 'error');
            } else {
                toast(position === 'inside' ? t('page.nested') : t('page.reordered'));
            }
        } catch (err) {
            renderApp();
            toast(err.message, 'error');
        }
    }

    function clearTreeDropState() {
        root.classList.remove('page-dragging');
        root.querySelectorAll('.drop-before,.drop-inside,.drop-after,.drop-root').forEach(element => element.classList.remove('drop-before', 'drop-inside', 'drop-after', 'drop-root'));
        root.querySelectorAll('.drag-source').forEach(element => element.classList.remove('drag-source'));
        state.dragPageId = null;
    }

    function updateBlockContent(target, undoGroupKey = null) {
        const block = state.page?.blocks.find(item => item.id === target.dataset.id);
        if (!block) return;
        block.content = target.innerHTML;
        const commandText = target.textContent.trim();
        if (commandText.startsWith('/') && !commandText.includes('\n')) showSlashFor(target, false, commandText.slice(1));
        else if (state.slash?.id === block.id && !state.slash.forced) { state.slash = null; root.querySelector('.slash-menu')?.remove(); }
        scheduleSave(false, undoGroupKey);
    }

    root.addEventListener('input', event => {
        if (event.target.closest?.('#aiProviderForm')) {
            state.aiProviderDraft = readAiProviderForm();
            state.aiProviderNotice = '';
        }
        if (event.target.closest?.('#translationProviderForm')) {
            state.translationProviderDraft = readTranslationProviderForm();
            state.translationProviderNotice = '';
        }
        if (event.target.id === 'editorFindInput') {
            state.editorFind.query = event.target.value;
            refreshEditorFindMatches(true, Boolean(event.target.value));
        }
        if (event.target.id === 'editorReplaceInput') state.editorFind.replacement = event.target.value;
        if (event.target.id === 'localSearchInput') {
            state.localSearchTag = '';
            updateLocalSearch(event.target.value);
        }
        if (event.target.id === 'publicShareSlug') {
            const slug = event.target.value.toLowerCase();
            if (event.target.value !== slug) event.target.value = slug;
            state.publicShareDraftSlug = slug;
            const publishButton = root.querySelector('[data-action="start-public-share"]');
            if (publishButton) {
                publishButton.disabled = !publicShareSlugValid(slug)
                    || state.publicShareSaving
                    || !state.publicShare?.root_publishable
                    || publicShareHasUnavailableSelection();
            }
        }
        if (event.target.id === 'pageTitle') {
            state.page.title = event.target.textContent.trim() || t('page.untitled');
            scheduleSave(false, inputUndoGroup(event));
        }
        if (event.target.matches('.block-content')) {
            updateBlockContent(event.target, inputUndoGroup(event));
            if (state.editorFind.open) refreshEditorFindMatches(false, false);
        }
        if (event.target.matches('.table-cell-content')) {
            const block = state.page?.blocks.find(item => item.id === event.target.dataset.blockId);
            const cell = block?.table_rows?.[Number(event.target.dataset.row)]?.[Number(event.target.dataset.col)];
            if (cell) {
                cell.content = event.target.innerHTML;
                scheduleSave(false, inputUndoGroup(event));
                if (state.editorFind.open) refreshEditorFindMatches(false, false);
            }
        }
        if (event.target.id === 'aiSearchInput') {
            state.aiQuestion = event.target.value;
            const submit = root.querySelector('.ai-search-submit');
            if (submit) submit.disabled = state.aiSearching || state.aiQuestion.trim().length < 2;
        }
        if (event.target.id === 'commentInput') updateMentionPicker(event.target);
    });

    root.addEventListener('focusin', event => {
        if (event.target.matches?.('#pageTitle,.block-content,.table-cell-content')) rememberAiEditorContext(event.target);
        if (event.target.matches('.table-cell-content')) {
            const current = state.tableSelection;
            const row = Number(event.target.dataset.row);
            const col = Number(event.target.dataset.col);
            if (!current || current.blockId !== event.target.dataset.blockId || current.focusRow !== row || current.focusCol !== col) {
                selectTableCell(event.target);
            }
        }
    });

    document.addEventListener('selectionchange', () => {
        const selection = getSelection();
        const node = selection?.anchorNode;
        const target = node?.nodeType === Node.ELEMENT_NODE ? node : node?.parentElement;
        if (target?.closest?.('#pageTitle,.block-content,.table-cell-content')) rememberAiEditorContext(target);
    });

    root.addEventListener('keydown', event => {
        const settingsTab = event.target.closest?.('[data-settings-tab]');
        if (settingsTab && ['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
            const tabs = [...root.querySelectorAll('[data-settings-tab]')];
            const currentIndex = tabs.indexOf(settingsTab);
            const nextIndex = event.key === 'Home'
                ? 0
                : event.key === 'End'
                ? tabs.length - 1
                : (currentIndex + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
            const nextTab = tabs[nextIndex];
            if (nextTab) {
                event.preventDefault();
                activateSettingsTab(String(nextTab.dataset.settingsTab || 'general'));
                nextTab.focus();
            }
            return;
        }
        if (event.target.id === 'editorFindInput' && !event.isComposing && event.key === 'Enter') {
            event.preventDefault();
            moveEditorFind(event.shiftKey ? -1 : 1);
            return;
        }
        if (event.target.id === 'editorReplaceInput' && !event.isComposing && event.key === 'Enter') {
            event.preventDefault();
            replaceCurrentEditorFind();
            return;
        }
        if (event.target.closest?.('.editor-find-bar') && event.key === 'Escape') {
            event.preventDefault();
            closeEditorFind();
            return;
        }
        if (!event.isComposing && ['Delete', 'Backspace'].includes(event.key)) {
            const textSelection = captureEditorTextSelection();
            if (textSelection && textSelection.startBlock !== textSelection.endBlock) {
                event.preventDefault();
                event.stopPropagation();
                deleteEditorTextSelection(textSelection);
                return;
            }
        }
        if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'a' && event.target.matches('.block-content')) {
            const selection = getSelection();
            event.preventDefault();
            if (hasCrossBlockTextSelection(selection) || selectionCoversBlock(selection, event.target)) {
                selectAllEditorText();
            } else {
                selectBlockText(event.target);
            }
            return;
        }
        if (event.target.matches('.table-cell-content') && event.key === 'Tab') {
            event.preventDefault();
            const blockId = event.target.dataset.blockId;
            const block = state.page?.blocks.find(item => item.id === blockId);
            if (!block) return;
            const columnCount = block.table_widths.length;
            const currentRow = Number(event.target.dataset.row);
            const currentCol = Number(event.target.dataset.col);
            let nextIndex = currentRow * columnCount + currentCol + (event.shiftKey ? -1 : 1);
            if (nextIndex < 0) nextIndex = 0;
            if (nextIndex >= block.table_rows.length * columnCount) {
                addTableRow(blockId, false);
                return;
            }
            focusTableCell(blockId, Math.floor(nextIndex / columnCount), nextIndex % columnCount);
            return;
        }
        if (event.target.matches('.block-content')) {
            const el = event.target;
            const id = el.dataset.id;
            const slashOpen = state.slash?.id === id;
            if (slashOpen && !event.isComposing && ['ArrowDown', 'ArrowUp'].includes(event.key)) {
                event.preventDefault();
                event.stopPropagation();
                const commands = matchingSlashCommands();
                if (!commands.length) return;
                const direction = event.key === 'ArrowDown' ? 1 : -1;
                state.slash.selectedIndex = (state.slash.selectedIndex + direction + commands.length) % commands.length;
                root.querySelector('.slash-menu')?.remove();
                root.insertAdjacentHTML('beforeend', renderSlashMenu());
                root.querySelector('.slash-item.selected')?.scrollIntoView({ block: 'nearest' });
                return;
            }
            if (slashOpen && !event.isComposing && ['Enter', 'Tab'].includes(event.key)) {
                const commands = matchingSlashCommands();
                if (commands.length) {
                    event.preventDefault();
                    event.stopPropagation();
                    chooseBlock(commands[state.slash.selectedIndex]?.[0] || commands[0][0]);
                    return;
                }
            }
            if (slashOpen && event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                state.slash = null;
                root.querySelector('.slash-menu')?.remove();
                return;
            }
            if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
                event.preventDefault(); syncEditorFromDom(); insertBlock(id, ['bullet','number','todo'].includes(el.dataset.type) ? el.dataset.type : 'paragraph');
            } else if (event.key === 'Backspace' && el.textContent === '') {
                const index = state.page.blocks.findIndex(block => block.id === id);
                if (index > 0) {
                    event.preventDefault(); const previous = state.page.blocks[index - 1]; state.page.blocks.splice(index, 1); renderEditorOnly(); scheduleSave(); focusBlock(previous.id);
                }
            } else if (event.key === '/' && el.textContent === '') {
                setTimeout(() => showSlashFor(el), 0);
            }
        }
        if (event.target.id === 'commentInput' && state.commentPicker && !event.isComposing) {
            const itemCount = state.commentPicker.type === 'emoji' ? commentEmojis.length : (state.commentPicker.items || []).length;
            if (['ArrowDown', 'ArrowUp'].includes(event.key) && itemCount) {
                event.preventDefault();
                const direction = event.key === 'ArrowDown' ? 1 : -1;
                state.commentPicker.selectedIndex = (state.commentPicker.selectedIndex + direction + itemCount) % itemCount;
                renderCommentPicker();
                return;
            }
            if (['Enter', 'Tab'].includes(event.key) && itemCount) {
                event.preventDefault();
                if (state.commentPicker.type === 'emoji') chooseCommentEmoji(commentEmojis[state.commentPicker.selectedIndex]);
                else chooseCommentMention(state.commentPicker.items[state.commentPicker.selectedIndex]);
                return;
            }
            if (event.key === 'Escape') {
                event.preventDefault();
                closeCommentPicker();
                return;
            }
        }
        if (event.target.id === 'commentInput' && (event.metaKey || event.ctrlKey) && event.key === 'Enter') {
            event.preventDefault();
            sendComment();
        }
        if (event.target.id === 'aiSearchInput' && event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
            event.preventDefault();
            submitAiSearch();
        }
    });

    root.addEventListener('mouseup', event => {
        if (canEditCurrentPage() && event.target.closest('.block-content')) setTimeout(showBubbleMenu, 0);
    });

    root.addEventListener('change', async event => {
        if (event.target.id === 'fileTypeFilter') {
            state.fileTypeFilter = event.target.value;
            state.fileListPage = 1;
            renderApp();
            document.getElementById('fileTypeFilter')?.focus();
            return;
        }
        if (event.target.id === 'aiProviderType' || event.target.id === 'aiProviderAuthMode') {
            const draft = readAiProviderForm();
            if (event.target.id === 'aiProviderType' && draft.provider_id === 'openai') {
                Object.assign(draft, {
                    display_name: 'OpenAI',
                    base_url: 'https://api.openai.com/v1',
                    endpoint_mode: 'responses',
                    structured_output_mode: 'json_schema',
                    auth_mode: 'bearer',
                    verify_tls: true,
                    allow_private_network: false,
                    allow_http: false,
                });
            }
            state.aiProviderDraft = draft;
            state.aiProviderNotice = '';
            renderApp();
            return;
        }
        if (event.target.id === 'translationProviderType' || event.target.id === 'translationProviderAuthMode') {
            const draft = readTranslationProviderForm();
            if (event.target.id === 'translationProviderType' && draft.provider_id === 'openai') {
                Object.assign(draft, {
                    display_name: 'OpenAI',
                    base_url: 'https://api.openai.com/v1',
                    endpoint_mode: 'responses',
                    structured_output_mode: 'json_schema',
                    auth_mode: 'bearer',
                    verify_tls: true,
                    allow_private_network: false,
                    allow_http: false,
                });
            }
            state.translationProviderDraft = draft;
            state.translationProviderNotice = '';
            renderApp();
            return;
        }
        if (event.target.id === 'uiLocaleSelect') {
            await updateUiLocale(event.target.value);
            return;
        }
        if (event.target.classList?.contains('translation-page-toggle')) {
            updateTranslationPageSelection(Number(event.target.dataset.pageId || 0), Boolean(event.target.checked));
            renderApp();
            return;
        }
        if (event.target.id === 'translationTarget' || event.target.id === 'translationProvider') {
            state.dialogData = {
                ...(state.dialogData || {}),
                target_language: document.getElementById('translationTarget')?.value || '',
                provider: document.getElementById('translationProvider')?.value || '',
            };
            renderApp();
            return;
        }
        if (event.target.id === 'profilePhotoInput') {
            useProfilePhoto(event.target.files?.[0]);
            return;
        }
        if (event.target.id === 'inviteDepartment') {
            const newDepartmentField = document.getElementById('inviteNewDepartmentField');
            const newDepartmentInput = document.getElementById('inviteNewDepartment');
            const addingNewDepartment = event.target.value === '__add_new_department__';
            if (newDepartmentField && newDepartmentInput) {
                newDepartmentField.hidden = !addingNewDepartment;
                newDepartmentInput.disabled = !addingNewDepartment;
                newDepartmentInput.required = addingNewDepartment;
                if (addingNewDepartment) newDepartmentInput.focus();
                else newDepartmentInput.value = '';
            }
            return;
        }
        if (event.target.id === 'aiConversationSelect') {
            await selectAiConversation(Number(event.target.value || 0));
            return;
        }
        if (event.target.matches('[data-action="table-background"]')) {
            setTableBackground(event.target.dataset.id, event.target.value);
            return;
        }
        if (event.target.matches('[data-action="toggle-todo"]')) {
            const block = state.page.blocks.find(item => item.id === event.target.dataset.id);
            if (block) { block.checked = event.target.checked; event.target.closest('.todo-block').classList.toggle('checked', block.checked); scheduleSave(); }
        }
        if (event.target.dataset.change === 'public-share-page') {
            const pageId = Number(event.target.value || 0);
            const publicPage = state.publicShare?.descendants.find(page => page.id === pageId);
            const selected = new Set(publicShareDraftPageIds());
            if (!publicPage || state.publicShareSaving || publicShareIntegrityBlocked() || event.target.checked && (!publicPage.can_edit || !publicPage.publishable)) return;
            event.target.checked ? selected.add(pageId) : selected.delete(pageId);
            state.publicShareDraftPageIds = normalizePublicSharePageIds([...selected]);
            renderApp();
            requestAnimationFrame(() => root.querySelector(`[data-change="public-share-page"][value="${pageId}"]`)?.focus());
            return;
        }
        if (event.target.dataset.change === 'visibility') {
            const nextVisibility = event.target.value;
            if (event.target.value !== 'private' && hasPrivateAncestor()) {
                event.target.value = 'private';
                state.page.status = 'private';
                state.page.visibility = 'private';
                renderApp();
                toast(privateParentMessage(), 'error');
                return;
            }
            if (nextVisibility === 'department') {
                const accessDepartments = defaultAccessDepartments(state.page.access_departments);
                if (!accessDepartments.length) {
                    event.target.value = state.page.visibility;
                    toast(t('share.registerDepartmentFirst'), 'error');
                    return;
                }
                state.page.access_departments = accessDepartments;
            }
            syncEditorFromDom();
            state.page.visibility = nextVisibility;
            state.page.access_member_ids ||= [];
            if (event.target.value === 'private') {
                state.page.status = 'private';
                state.page.access_member_ids = [];
            } else if (state.page.status === 'private') {
                state.page.status = 'draft';
            }
            if (event.target.value === 'group' && !['admin', 'content_admin'].includes(state.user.role) && Number(state.user.id) !== Number(state.page.author_id)) {
                state.page.access_member_ids = [...new Set([...state.page.access_member_ids.map(Number), Number(state.user.id)])];
            }
            scheduleSave(true);
            renderApp();
        }
        if (event.target.dataset.change === 'access-department') {
            const accessDepartment = trimDepartmentName(event.target.value);
            if (!accessDepartment) return;
            const selected = new Set(normalizeAccessDepartments(state.page.access_departments));
            if (event.target.checked) {
                if (!selected.has(accessDepartment) && selected.size >= 50) {
                    event.target.checked = false;
                    toast(t('share.departmentLimit'), 'error');
                    return;
                }
                selected.add(accessDepartment);
            } else {
                selected.delete(accessDepartment);
                if (!selected.size) {
                    event.target.checked = true;
                    toast(t('share.departmentRequired'), 'error');
                    return;
                }
            }
            syncEditorFromDom();
            state.page.access_departments = normalizeAccessDepartments([...selected]);
            scheduleSave(true);
            renderApp();
            requestAnimationFrame(() => {
                [...root.querySelectorAll('[data-change="access-department"]')]
                    .find(input => input.value === accessDepartment)?.focus();
            });
            return;
        }
        if (event.target.dataset.change === 'access-member') {
            syncEditorFromDom();
            const memberId = Number(event.target.value);
            const selected = new Set((state.page.access_member_ids || []).map(Number));
            event.target.checked ? selected.add(memberId) : selected.delete(memberId);
            state.page.access_member_ids = [...selected];
            scheduleSave(true);
        }
        if (event.target.dataset.change === 'invite-role') {
            const administrator = event.target.value === 'admin';
            const field = document.getElementById('inviteAdministratorPasswordField');
            const input = document.getElementById('inviteAdministratorPassword');
            if (field) field.hidden = !administrator;
            if (input) {
                input.required = administrator;
                if (!administrator) input.value = '';
            }
        }
        if (event.target.dataset.change === 'member-role') {
            const memberId = Number(event.target.dataset.memberId);
            const member = state.members.find(item => Number(item.id) === memberId);
            if (!member) {
                await refreshSummary();
                renderApp();
                return;
            }
            const requestedRole = event.target.value;
            if (member.role === 'admin' || requestedRole === 'admin') {
                event.target.value = member.role;
                openAdministratorConfirmation(
                    requestedRole === 'suspended' ? 'suspend' : 'role',
                    member,
                    { role: requestedRole }
                );
                return;
            }
            if (requestedRole === 'suspended') {
                event.target.value = member.role;
                const confirmed = window.confirm(t('settings.confirmSuspend', { name: member.name }));
                if (!confirmed) return;
                try {
                    const data = await api('suspend-member', { method: 'POST', body: { member_id: memberId } });
                    state.members = state.members.filter(item => Number(item.id) !== memberId);
                    state.suspendedMembers.push(data.member);
                    syncDepartmentsFromMembers();
                    renderApp();
                    toast(t('settings.accountSuspended'));
                } catch (err) {
                    toast(err.message, 'error');
                    await refreshSummary();
                    renderApp();
                }
                return;
            }
            try {
                const data = await api('update-member-role', { method: 'POST', body: { member_id: memberId, role: requestedRole } });
                if (member) member.role = data.role;
                renderApp();
                toast(t('settings.roleUpdated'));
            } catch (err) {
                toast(err.message, 'error');
                await refreshSummary();
                renderApp();
            }
        }
        if (event.target.dataset.change === 'suspended-member-role') {
            const memberId = Number(event.target.dataset.memberId);
            const member = state.suspendedMembers.find(item => Number(item.id) === memberId);
            const role = event.target.value;
            if (!member || role === 'suspended' || !roleValues.includes(role)) {
                event.target.value = 'suspended';
                return;
            }
            event.target.value = 'suspended';
            if (role === 'admin') {
                openAdministratorConfirmation('reactivate', member, { role });
                return;
            }
            state.dialogData = { member_id: memberId, member_name: member.name, email: member.email, role };
            state.dialog = 'reactivate-confirmation';
            renderApp();
        }
    });

    root.addEventListener('pointerdown', event => {
        const blockMenu = event.target.closest('[data-action="block-menu"]');
        state.preservedTextSelection = blockMenu ? captureEditorTextSelection() : null;
        if (blockMenu) {
            event.preventDefault();
            return;
        }
        const handle = event.target.closest('[data-action="resize-table-column"]');
        if (handle) {
            const cellContent = handle.closest('.table-cell')?.querySelector('.table-cell-content');
            if (cellContent) selectTableCell(cellContent);
            startTableColumnResize(handle, event);
            return;
        }
        const tableCell = event.target.closest('.table-cell-content');
        if (tableCell) {
            startTableRangeSelection(tableCell, event);
            return;
        }
        const textBlock = event.target.closest('.block-content');
        if (textBlock) startCrossBlockTextSelection(textBlock, event);
        if (!event.target.closest('.table-toolbar')) clearTableSelection();
    });

    root.addEventListener('copy', event => {
        if (copyCrossBlockTextSelection(event)) return;
        const target = event.target.closest?.('.table-cell-content');
        if (!target || state.tableSelection?.blockId !== target.dataset.blockId) return;
        if (hasTextSelectionInsideTableCell(target)) return;
        const clipboard = captureTableSelection(target.dataset.blockId);
        if (!clipboard || !event.clipboardData) return;
        event.preventDefault();
        state.tableClipboard = clipboard;
        event.clipboardData.setData('text/plain', tableClipboardText(clipboard));
        event.clipboardData.setData('text/html', tableClipboardHtml(clipboard));
        enableTablePasteButtons();
        toast(t('editor.tableCopied', { rows: clipboard.rows.length, columns: clipboard.rows[0]?.length || 0 }));
    });

    root.addEventListener('paste', event => {
        const tableTarget = event.target.closest?.('.table-cell-content');
        const blockTarget = event.target.closest?.('.block-content,#pageTitle');
        if (!tableTarget && !blockTarget) return;
        const plainText = clipboardPlainText(event.clipboardData);
        const tableClipboard = tableClipboardFromData(event.clipboardData);
        if (blockTarget) {
            event.preventDefault();
            if (blockTarget.id !== 'pageTitle' && tableClipboard) {
                state.tableClipboard = tableClipboard;
                insertTableFromClipboard(blockTarget, tableClipboard);
            } else if (blockTarget.id === 'pageTitle') {
                insertPlainTextAtSelection(blockTarget, normalizePastedPlainText(plainText));
            } else if (blockTarget.dataset.type === 'code') {
                insertPlainTextAtSelection(blockTarget, normalizePastedPlainText(plainText, true));
            } else {
                insertPlainTextBlocks(blockTarget, plainText);
            }
            return;
        }
        event.preventDefault();
        if (tableClipboard) {
            state.tableClipboard = tableClipboard;
            pasteTableClipboard(tableTarget.dataset.blockId, tableClipboard);
        } else {
            insertPlainTextWithLineBreaksAtSelection(tableTarget, plainText);
        }
    });

    root.addEventListener('dragstart', event => {
        const row = event.target.closest('.tree-row[data-tree-page-id][draggable="true"]');
        if (!row) return;
        state.dragPageId = Number(row.dataset.treePageId);
        row.classList.add('drag-source');
        root.classList.add('page-dragging');
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', String(state.dragPageId));
    });

    root.addEventListener('dragover', event => {
        if (!state.dragPageId) return;
        root.querySelectorAll('.drop-before,.drop-inside,.drop-after,.drop-root').forEach(element => element.classList.remove('drop-before', 'drop-inside', 'drop-after', 'drop-root'));
        const rootDrop = event.target.closest('[data-tree-root-drop]');
        if (rootDrop) {
            event.preventDefault();
            rootDrop.classList.add('drop-root');
            event.dataTransfer.dropEffect = 'move';
            return;
        }
        const targetRow = event.target.closest('.tree-row[data-tree-page-id]');
        if (!targetRow) return;
        const targetId = Number(targetRow.dataset.treePageId);
        if (targetId === state.dragPageId || pageIsDescendantOf(targetId, state.dragPageId) || !canEditPageSummary(targetId)) return;
        event.preventDefault();
        const rect = targetRow.getBoundingClientRect();
        const ratio = (event.clientY - rect.top) / Math.max(rect.height, 1);
        targetRow.classList.add(ratio < .28 ? 'drop-before' : ratio > .72 ? 'drop-after' : 'drop-inside');
        event.dataTransfer.dropEffect = 'move';
    });

    root.addEventListener('drop', event => {
        if (!state.dragPageId) return;
        const movingId = state.dragPageId;
        const rootDrop = event.target.closest('[data-tree-root-drop]');
        const targetRow = event.target.closest('.tree-row[data-tree-page-id]');
        let targetId = 0;
        let position = '';
        if (rootDrop?.classList.contains('drop-root')) position = 'root-end';
        else if (targetRow) {
            targetId = Number(targetRow.dataset.treePageId);
            position = targetRow.classList.contains('drop-before') ? 'before' : targetRow.classList.contains('drop-after') ? 'after' : targetRow.classList.contains('drop-inside') ? 'inside' : '';
        }
        if (!position) return clearTreeDropState();
        event.preventDefault();
        state.suppressTreeClickUntil = Date.now() + 500;
        clearTreeDropState();
        movePage(movingId, targetId, position);
    });

    root.addEventListener('dragend', () => clearTreeDropState());

    root.addEventListener('submit', event => {
        if (event.target.id === 'fileSearchForm') {
            event.preventDefault();
            state.fileSearchQuery = document.getElementById('fileSearchInput').value.trim();
            state.fileTypeFilter = '';
            state.fileListPage = 1;
            renderApp();
            document.getElementById('fileSearchInput')?.focus();
            return;
        }
        if (event.target.id === 'aiProviderForm') {
            event.preventDefault();
            saveAiProviderSettings();
        }
        if (event.target.id === 'translationProviderForm') {
            event.preventDefault();
            saveTranslationProviderSettings();
        }
        if (event.target.id === 'organizationForm') {
            event.preventDefault();
            saveOrganizationName();
        }
        if (event.target.id === 'inviteForm') {
            event.preventDefault();
            submitInvitation();
        }
        if (event.target.id === 'memberNameForm') {
            event.preventDefault();
            saveMemberName();
        }
        if (event.target.id === 'aiSearchForm') {
            event.preventDefault();
            submitAiSearch();
        }
    });

    root.addEventListener('click', async event => {
        const target = event.target.closest('[data-action]');
        if (!target) {
            if (!event.target.closest('.slash-menu,.context-menu,.bubble-menu')) {
                const workspaceMenuWasOpen = state.context?.kind === 'workspace';
                state.slash = null; state.context = null; removeFloatingElements();
                if (workspaceMenuWasOpen) root.querySelector('[data-action="workspace-menu"]')?.setAttribute('aria-expanded', 'false');
            }
            if (!event.target.closest('.comment-picker,.composer-tools')) closeCommentPicker();
            return;
        }
        const action = target.dataset.action;
        if (state.context?.kind === 'workspace' && !['workspace-menu', 'about-openconcept'].includes(action)) {
            state.context = null;
            removeFloatingElements();
            root.querySelector('[data-action="workspace-menu"]')?.setAttribute('aria-expanded', 'false');
        }
        if (action === 'open-page') { event.preventDefault(); event.stopPropagation(); if (Date.now() < state.suppressTreeClickUntil) return; state.mobileSidebar = false; await openPage(target.dataset.id); }
        else if (action === 'workspace-menu') {
            event.stopPropagation();
            if (state.context?.kind === 'workspace') {
                state.context = null;
                removeFloatingElements();
                target.setAttribute('aria-expanded', 'false');
            } else {
                const rect = target.getBoundingClientRect();
                state.context = { kind: 'workspace', x: rect.left + 5, y: rect.bottom + 4 };
                removeFloatingElements();
                root.insertAdjacentHTML('beforeend', renderContextMenu());
                target.setAttribute('aria-expanded', 'true');
            }
        }
        else if (action === 'about-openconcept') { state.context = null; state.dialog = 'about-openconcept'; renderApp(); }
        else if (action === 'profile') openProfileDialog();
        else if (action === 'home') await showHome();
        else if (action === 'files') await showFiles();
        else if (action === 'trash') await showTrash();
        else if (action === 'open-rag-settings') { state.mobileSidebar = false; window.OpenConceptRagSettings?.open?.(); }
        else if (action === 'settings-tab') activateSettingsTab(String(target.dataset.settingsTab || 'general'));
        else if (action === 'open-plugin') openPlugin(target.dataset.pluginId);
        else if (action === 'open-database-connection-status') openDatabaseConnectionStatus(target.dataset.pluginId);
        else if (action === 'toggle-sidebar') { state.sidebarCollapsed = false; state.mobileSidebar = true; renderApp(); }
        else if (action === 'close-mobile-sidebar') { state.mobileSidebar = false; renderApp(); }
        else if (action === 'toggle-tree') { event.stopPropagation(); const id = Number(target.dataset.id); state.expanded.has(id) ? state.expanded.delete(id) : state.expanded.add(id); renderApp(); }
        else if (action === 'new-page') { event.stopPropagation(); await createPage(target.dataset.parent || null); }
        else if (action === 'clear-local-search') { updateLocalSearch(''); root.querySelector('#localSearchInput')?.focus(); }
        else if (action === 'search-by-tag') searchByTag(target.dataset.tag);
        else if (action === 'editor-find-open') openEditorFind();
        else if (action === 'editor-find-close') closeEditorFind();
        else if (action === 'editor-find-prev') moveEditorFind(-1);
        else if (action === 'editor-find-next') moveEditorFind(1);
        else if (action === 'editor-find-replace') replaceCurrentEditorFind();
        else if (action === 'editor-find-replace-all') replaceAllEditorFind();
        else if (action === 'search') openAiSearch();
        else if (action === 'ai-new-chat') startNewAiConversation();
        else if (action === 'ai-suggestion') { state.aiQuestion = target.textContent.trim(); state.aiSearchError = ''; state.aiSearchErrorDetails = null; renderApp(); }
        else if (action === 'ai-summarize-page') summarizeCurrentPage();
        else if (action === 'open-ai-action-page') {
            clearTimeout(aiPanelCloseTimer);
            aiPanelCloseTimer = null;
            aiPanelClosing = false;
            state.dialog = null;
            document.body.classList.remove('ai-search-open');
            await openPage(Number(target.dataset.id));
        }
        else if (action === 'inbox') { state.dialog = 'inbox'; state.inboxFilter = 'all'; renderApp(); await refreshNotifications(); }
        else if (action === 'members') { state.dialog = 'members'; renderApp(); }
        else if (action === 'settings') await showSettings();
        else if (action === 'back-to-plugin-settings') { state.dialog = null; state.dialogData = null; await showSettings('plugins'); }
        else if (action === 'open-translation-provider-settings') { state.dialog = null; state.dialogData = null; await showSettings('translation'); }
        else if (action === 'translate-page') { state.dialogData = { selected_page_ids: [] }; state.dialog = 'translation'; renderApp(); }
        else if (action === 'translation-select-all') { state.dialogData = { ...(state.dialogData || {}), selected_page_ids: translationDescendants().map(page => Number(page.page_id)) }; renderApp(); }
        else if (action === 'translation-root-only') { state.dialogData = { ...(state.dialogData || {}), selected_page_ids: [] }; renderApp(); }
        else if (action === 'execute-translation') await executeTranslation();
        else if (action === 'refresh-plugins') { state.pluginsLoaded = false; await loadPlugins(); }
        else if (action === 'test-ai-provider') await testAiProvider();
        else if (action === 'test-translation-provider') await testTranslationProvider();
        else if (action === 'download-plugin') await downloadPlugin(target.dataset.pluginId);
        else if (action === 'toggle-plugin') await togglePlugin(target.dataset.pluginId, target.dataset.enabled === 'true');
        else if (action === 'close-dialog' || action === 'backdrop' && event.target === target) {
            const closingShareDialog = state.dialog === 'share';
            if (state.dialog === 'reactivate-confirmation') { state.dialog = 'members'; state.dialogData = null; renderApp(); }
            else if (state.dialog === 'profile') { closeProfileDialog(); renderApp(); }
            else if (!closeAiSearch()) { state.dialog = null; state.dialogData = null; renderApp(); }
            if (closingShareDialog) restoreShareButtonFocus();
        }
        else if (action === 'comments') { state.panel = state.panel === 'comments' ? null : 'comments'; renderApp(); }
        else if (action === 'close-panel') { state.panel = null; state.replyTo = null; renderApp(); }
        else if (action === 'share') await openShareDialog();
        else if (action === 'refresh-affected-publications') await refreshAffectedPublications();
        else if (action === 'dismiss-affected-publications') {
            state.affectedPublications.clear();
            syncAffectedPublicationsBanner();
        }
        else if (action === 'retry-public-share') await loadPublicShare(Number(state.page?.id || 0));
        else if (action === 'select-all-public-pages') setAllPublicShareDescendants(true);
        else if (action === 'clear-public-pages') setAllPublicShareDescendants(false);
        else if (action === 'start-public-share') await persistPublicShare(true, 'share.public.published');
        else if (action === 'save-public-share') await persistPublicShare(true, 'share.public.updated');
        else if (action === 'refresh-public-share') await refreshPublicShare();
        else if (action === 'stop-public-share') await stopPublicShare();
        else if (action === 'copy-public-link') await copyPublicShareLink();
        else if (action === 'undo') undoEdit();
        else if (action === 'favorite') toggleFavorite();
        else if (action === 'favorite-by-id') toggleFavorite(target.dataset.id);
        else if (action === 'tree-more') { event.stopPropagation(); showPageMenu(Number(target.dataset.id), target); }
        else if (action === 'page-more') {
            const rect = target.getBoundingClientRect(); state.context = { x: Math.max(8, rect.right - 214), y: Math.max(8, Math.min(rect.bottom + 4, innerHeight - 292)), pageId: state.page.id };
            removeFloatingElements(); root.insertAdjacentHTML('beforeend', `<div class="context-menu" style="left:${state.context.x}px;top:${state.context.y}px"><button class="menu-item" data-action="history">${svg('history','small')}<span>${esc(t('page.history'))}</span></button>${canEditCurrentPage() ? `<button class="menu-item" data-action="metadata">${svg('settings','small')}<span>${esc(t('page.propertiesShort'))}</span></button>` : ''}<button class="menu-item" data-action="favorite">${svg('star','small')}<span>${esc(t('common.favorites'))}</span></button><div class="menu-sep"></div><button class="menu-item" data-action="export-page" data-format="print">${svg('print','small')}<span>${esc(t('page.print'))}</span></button><button class="menu-item" data-action="export-page" data-format="pdf">${svg('download','small')}<span>${esc(t('page.savePdf'))}</span></button><button class="menu-item" data-action="export-page" data-format="md">${svg('fileText','small')}<span>${esc(t('page.markdownFile'))}</span></button>${canEditCurrentPage() ? `<div class="menu-sep"></div><button class="menu-item danger" data-action="archive-by-id" data-id="${state.page.id}">${svg('archive','small')}<span>${esc(t('page.moveToTrash'))}</span></button>` : ''}</div>`);
        }
        else if (action === 'export-page') exportCurrentPage(target.dataset.format);
        else if (action === 'history') { state.context = null; state.dialog = 'history'; renderApp(); }
        else if (action === 'metadata') { state.context = null; state.dialog = 'metadata'; renderApp(); }
        else if (action === 'icon-picker') { state.dialog = 'icon'; renderApp(); }
        else if (action === 'cover-picker') { state.dialog = 'cover'; renderApp(); }
        else if (action === 'choose-icon') { state.page.icon = target.dataset.value; state.dialog = null; scheduleSave(true); renderApp(); }
        else if (action === 'choose-cover') { state.page.cover = target.dataset.value; state.dialog = null; scheduleSave(true); renderApp(); }
        else if (action === 'choose-profile-initials') chooseProfileAvatar('initials');
        else if (action === 'choose-profile-icon') chooseProfileAvatar('emoji', target.dataset.value || '');
        else if (action === 'choose-profile-photo') selectProfilePhoto();
        else if (action === 'save-profile') saveProfile();
        else if (action === 'open-password-change') openOwnPasswordChangeDialog();
        else if (action === 'submit-password-change') submitOwnPasswordChange();
        else if (action === 'save-metadata') {
            const requestedStatus = document.getElementById('metaStatus').value;
            if (requestedStatus !== 'private' && hasPrivateAncestor()) {
                document.getElementById('metaStatus').value = 'private';
                toast(privateParentMessage(), 'error');
                return;
            }
            state.page.status = requestedStatus;
            if (state.page.status === 'private') {
                state.page.visibility = 'private';
                state.page.access_member_ids = [];
            } else if (state.page.visibility === 'private') {
                state.page.visibility = 'company';
            }
            state.page.category = document.getElementById('metaCategory').value.trim() || 'ナレッジ';
            state.page.language_code = document.getElementById('metaLanguage')?.value || state.page.language_code || 'und';
            state.page.manual_tags = uniquePageTagNames(document.getElementById('metaTags').value.split(','), 12);
            syncPageTags(state.page);
            state.dialog = null; scheduleSave(true); renderApp(); toast(t('page.propertiesUpdated'));
        }
        else if (action === 'toggle-comments-setting') { state.page.comments_enabled = !state.page.comments_enabled; target.classList.toggle('on', state.page.comments_enabled); }
        else if (action === 'copy-link') { await navigator.clipboard?.writeText(`${location.origin}${location.pathname}#page-${state.page.id}`); toast(t('share.linkCopied')); }
        else if (action === 'add-block') { const el = root.querySelector(`.block-content[data-id="${CSS.escape(target.dataset.id)}"]`) || target.closest('.block-wrap'); showSlashFor(el, true, '', 'insert'); }
        else if (action === 'prepend-block') showSlashFor(target, true, '', 'insert-before');
        else if (action === 'append-block') insertBlock();
        else if (action === 'choose-block') chooseBlock(target.dataset.type);
        else if (action === 'upload-file-block') selectFileForBlock(target.dataset.id);
        else if (action === 'table-align') setTableAlignment(target.dataset.id, target.dataset.align);
        else if (action === 'table-add-row') addTableRow(target.dataset.id);
        else if (action === 'table-add-column') addTableColumn(target.dataset.id);
        else if (action === 'table-delete-row') deleteTableRows(target.dataset.id);
        else if (action === 'table-delete-column') deleteTableColumns(target.dataset.id);
        else if (action === 'table-copy') await copyTableSelection(target.dataset.id);
        else if (action === 'table-paste') pasteTableClipboard(target.dataset.id);
        else if (action === 'block-menu') showBlockMenu(target.dataset.id, target, state.preservedTextSelection);
        else if (action === 'turn-block') { state.context = null; const el = root.querySelector(`.block-content[data-id="${CSS.escape(target.dataset.id)}"]`) || root.querySelector(`[data-block-id="${CSS.escape(target.dataset.id)}"]`); showSlashFor(el || document.body, true); }
        else if (action === 'duplicate-block') { const index = state.page.blocks.findIndex(block => block.id === target.dataset.id); if (index >= 0) { const copy = JSON.parse(JSON.stringify(state.page.blocks[index])); copy.id = uid(); state.page.blocks.splice(index + 1, 0, copy); state.context = null; renderEditorOnly(); scheduleSave(); } }
        else if (action === 'delete-selected-text') deleteEditorTextSelection(state.context?.textSelection);
        else if (action === 'delete-block') { const index = state.page.blocks.findIndex(block => block.id === target.dataset.id); if (index >= 0) { state.page.blocks.splice(index, 1); if (!state.page.blocks.length) state.page.blocks.push({ id: uid(), type: 'paragraph', content: '' }); state.context = null; renderEditorOnly(); scheduleSave(); } }
        else if (action === 'format') formatCurrentSelection(target.dataset.command);
        else if (action === 'format-code') formatCurrentSelection('formatBlock', 'code');
        else if (action === 'format-link') {
            if (!canEditCurrentPage()) { hideBubble(); return; }
            const url = window.prompt(t('editor.linkPrompt'));
            if (url && /^https?:\/\//i.test(url)) formatCurrentSelection('createLink', url);
        }
        else if (action === 'clear-format') formatCurrentSelection('removeFormat');
        else if (action === 'send-comment') sendComment();
        else if (action === 'comment-mention') openMentionPickerFromButton();
        else if (action === 'comment-emoji') {
            state.commentPicker = { type: 'emoji', selectedIndex: 0 };
            renderCommentPicker();
            document.getElementById('commentInput')?.focus();
        }
        else if (action === 'choose-comment-mention') chooseCommentMention(Number(target.dataset.id));
        else if (action === 'choose-comment-emoji') chooseCommentEmoji(target.dataset.value || '');
        else if (action === 'reply-comment') { const comment = state.page.comments.find(item => Number(item.id) === Number(target.dataset.id)); state.replyTo = comment; renderApp(); }
        else if (action === 'cancel-reply') { state.replyTo = null; renderApp(); }
        else if (action === 'resolve-comment') { try { await api('resolve-comment', { method: 'POST', body: { id: Number(target.dataset.id) } }); const comment = state.page.comments.find(item => Number(item.id) === Number(target.dataset.id)); if (comment) comment.resolved_at = comment.resolved_at ? null : new Date().toISOString(); renderApp(); } catch (err) { toast(err.message, 'error'); } }
        else if (action === 'restore-revision') { try { await api('restore-revision', { method: 'POST', body: { revision_id: Number(target.dataset.id) } }); state.dialog = null; await openPage(state.page.id, false); toast(t('page.versionRestored')); } catch (err) { toast(err.message, 'error'); } }
        else if (action === 'archive-by-id') archivePage(Number(target.dataset.id));
        else if (action === 'restore-page') restorePage(Number(target.dataset.id));
        else if (action === 'delete-page-permanently') deletePagePermanently(Number(target.dataset.id), target.dataset.title || t('page.thisPage'));
        else if (action === 'file-category-list') { state.fileTypeFilter = target.dataset.category; state.fileListPage = 1; renderApp(); }
        else if (action === 'file-overview') { state.fileSearchQuery = ''; state.fileTypeFilter = ''; state.fileListPage = 1; renderApp(); }
        else if (action === 'file-list-page') { state.fileListPage = Number(target.dataset.page) || 1; renderApp(); root.querySelector('.file-results-summary')?.scrollIntoView({ block: 'nearest' }); }
        else if (action === 'delete-file') deleteFile(Number(target.dataset.id), target.dataset.title || t('files.thisFile'));
        else if (action === 'open-search-result') { state.dialog = null; await openPage(Number(target.dataset.id)); }
        else if (action === 'open-ai-source') await openPage(Number(target.dataset.id));
        else if (action === 'notification') {
            const notificationId = Number(target.dataset.id);
            const pageId = Number(target.dataset.pageId || 0);
            const notificationType = target.dataset.type || '';
            await setNotificationRead(notificationId, true);
            state.dialog = null;
            if (pageId) {
                await openPage(pageId);
                if (['comment', 'reply', 'mention'].includes(notificationType) && state.page) {
                    state.panel = 'comments';
                    renderApp();
                }
            }
            else renderApp();
        }
        else if (action === 'toggle-notification-read') await setNotificationRead(Number(target.dataset.id), target.dataset.read === '1');
        else if (action === 'inbox-filter') { state.inboxFilter = target.dataset.filter === 'unread' ? 'unread' : 'all'; updateNotificationChrome(); }
        else if (action === 'refresh-notifications') { await refreshNotifications(true); toast(t('inbox.refreshed')); }
        else if (action === 'mark-read') { try { await api('mark-notifications', { method: 'POST', body: {} }); state.notifications.forEach(item => item.is_read = 1); updateNotificationChrome(); toast(t('inbox.allMarkedRead')); } catch (err) { toast(err.message, 'error'); } }
        else if (action === 'invite-member') { state.dialog = 'invite-member'; state.dialogData = null; renderApp(); }
        else if (action === 'submit-invite') submitInvitation();
        else if (action === 'edit-member-name') {
            const memberId = Number(target.dataset.memberId);
            const member = state.members.find(item => Number(item.id) === memberId)
                || state.suspendedMembers.find(item => Number(item.id) === memberId);
            openMemberNameEditor(member);
        }
        else if (action === 'save-member-name') saveMemberName();
        else if (action === 'cancel-member-name') { state.dialog = 'members'; state.dialogData = null; renderApp(); }
        else if (action === 'cancel-reactivation') { state.dialog = 'members'; state.dialogData = null; renderApp(); }
        else if (action === 'confirm-reactivation') confirmAccountReactivation();
        else if (action === 'confirm-administrator-action') confirmAdministratorAction();
        else if (action === 'reset-member-password') {
            const member = state.members.find(item => Number(item.id) === Number(target.dataset.memberId));
            if (member) openAdministratorConfirmation('reset', member);
        }
        else if (action === 'resend-invitation') {
            const member = state.members.find(item => Number(item.id) === Number(target.dataset.memberId));
            if (member?.role === 'admin') openAdministratorConfirmation('resend', member);
            else resendInvitation(Number(target.dataset.memberId));
        }
        else if (action === 'delete-pending-invitation') {
            const member = state.members.find(item => Number(item.id) === Number(target.dataset.memberId));
            if (member?.role === 'admin') openAdministratorConfirmation('delete', member);
            else deletePendingInvitation(Number(target.dataset.memberId));
        }
        else if (action === 'copy-temporary-password') { await navigator.clipboard?.writeText(state.dialogData?.temporary_password || ''); toast(t('settings.temporaryPasswordCopied')); }
        else if (action === 'logout') { try { await api('logout', { method: 'POST', body: {} }); renderLogin(); } catch (err) { toast(err.message, 'error'); } }
    });

    document.addEventListener('keydown', event => {
        if (event.defaultPrevented) return;
        if (state.dialog === 'share' && event.key === 'Tab') {
            const focusable = shareDialogFocusableElements();
            if (focusable.length) {
                const first = focusable[0];
                const last = focusable[focusable.length - 1];
                if (event.shiftKey && (document.activeElement === first || !focusable.includes(document.activeElement))) {
                    event.preventDefault();
                    last.focus();
                    return;
                }
                if (!event.shiftKey && (document.activeElement === last || !focusable.includes(document.activeElement))) {
                    event.preventDefault();
                    first.focus();
                    return;
                }
            }
        }
        if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'f' && state.view === 'page' && state.page && !state.dialog) { event.preventDefault(); openEditorFind(); return; }
        if ((event.metaKey || event.ctrlKey) && !event.shiftKey && event.key.toLowerCase() === 'z' && event.target.closest?.('#editor,#pageTitle')) { event.preventDefault(); undoEdit(); return; }
        if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') { event.preventDefault(); openAiSearch(); }
        if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 's') { event.preventDefault(); scheduleSave(true); }
        if (event.key === 'Escape') {
            if (state.editorFind.open) closeEditorFind();
            else if (state.dialog === 'ai-search') closeAiSearch();
            else if (state.dialog === 'profile') { closeProfileDialog(); renderApp(); }
            else if (state.dialog || state.slash || state.context || state.bubble) { const closingShareDialog = state.dialog === 'share'; state.dialog = null; state.slash = null; state.context = null; state.bubble = null; renderApp(); if (closingShareDialog) restoreShareButtonFocus(); }
            else if (state.panel) { state.panel = null; renderApp(); }
            else if (state.tableSelection) clearTableSelection();
            else if (hasCrossBlockTextSelection()) getSelection()?.removeAllRanges();
        }
    });

    window.addEventListener('popstate', () => {
        const match = location.hash.match(/^#page-(\d+)$/);
        const settingsMatch = location.hash.match(/^#settings(?:-(general|ai|translation|plugins))?$/);
        if (match) openPage(Number(match[1]), false);
        else if (settingsMatch) showSettings(settingsMatch[1] || 'general', false);
        else if (location.hash === '#trash') showTrash(false);
        else if (location.hash === '#files') showFiles(false);
        else showHome(false);
    });

    window.addEventListener('beforeunload', event => {
        if (state.dirty || state.saving) { event.preventDefault(); event.returnValue = ''; }
    });

    window.addEventListener('pagehide', () => resetUndoHistory());

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden && state.user) refreshNotifications();
    });

    (async function init() {
        try {
            const session = await api('session');
            state.csrf = session.csrf;
            state.initialized = Boolean(session.initialized);
            state.user = session.user;
            if (!state.initialized) renderSetup();
            else if (state.user?.must_change_password) renderPasswordChange();
            else if (state.user) await loadWorkspace();
            else renderLogin();
        } catch { renderLogin(); }
    })();
})();
