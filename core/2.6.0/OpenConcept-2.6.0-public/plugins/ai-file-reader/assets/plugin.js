(() => {
    'use strict';
    const pluginId = 'ai-file-reader';
    const t = key => window.OpenConceptI18n?.t(pluginId, key, {}, key) || key;
    const open = () => window.OpenConceptPluginApi?.openAction({ plugin_id: pluginId, action: 'settings', payload: {} });
    window.OpenConceptPlugins?.register(pluginId, open);
    window.addEventListener('openconcept:locale-change', () => {
        // The shared modal owns locale rendering; plugin metadata remains accessible to extensions.
        document.dispatchEvent(new CustomEvent('openconcept:plugin-label', { detail: { id: pluginId, label: t('plugin.name') } }));
    });
})();
