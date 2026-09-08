(() => {
    'use strict';

    const i18nDomain = 'rag-core';
    const endpoint = 'rag-core';
    const t = (key, parameters = {}, fallback = key) => window.OpenConceptI18n?.t(i18nDomain, key, parameters, fallback) || fallback;
    const esc = value => String(value ?? '').replace(/[&<>"']/g, character => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[character]));
    let overlay = null;
    let flowHost = null;
    let payload = null;
    let notice = null;
    let baseline = null;
    let runtimeIssue = null;
    const connectionChecks = { retrieval: null, answer: null };
    let requestSequence = 0;

    const embeddingFailureCodes = new Set([
        'embedding_not_configured', 'embedding_authentication_failed', 'embedding_endpoint_not_found',
        'embedding_timeout', 'embedding_model_unavailable', 'embedding_service_unavailable',
        'embedding_request_rejected', 'embedding_invalid_response', 'embedding_dimension_mismatch',
        'embedding_tls_failed', 'embedding_http_not_allowed', 'embedding_private_network_not_allowed',
        'embedding_url_invalid', 'embedding_dns_failed', 'embedding_connection_refused', 'embedding_unreachable',
    ]);

    function embeddingFailureMessage(code, details = {}) {
        const messages = {
            embedding_not_configured: t('ui.embeddingNotConfigured', {}, 'The Embedding Base URL is not configured.'),
            embedding_authentication_failed: t('ui.embeddingAuthenticationFailed', {}, 'Authentication failed. Confirm that the API key configured in OpenConcept matches the Embedding server.'),
            embedding_endpoint_not_found: t('ui.embeddingEndpointNotFound', {}, 'The /embeddings endpoint was not found. Confirm the Base URL and API version path.'),
            embedding_timeout: t('ui.embeddingTimeout', {}, 'The Embedding server did not respond before the timeout.'),
            embedding_model_unavailable: t('ui.embeddingModelUnavailable', {}, 'The configured embedding model is not loaded or was rejected by the server.'),
            embedding_service_unavailable: t('ui.embeddingServiceUnavailable', {}, 'The Embedding server is starting, overloaded, or temporarily unavailable.'),
            embedding_request_rejected: t('ui.embeddingRequestRejected', {}, 'The Embedding server rejected the probe request.'),
            embedding_invalid_response: t('ui.embeddingInvalidResponse', {}, 'The server responded, but its embedding response format is invalid.'),
            embedding_dimension_mismatch: t('ui.embeddingDimensionMismatch', {}, 'The returned vector dimensions do not match the configured index dimensions.'),
            embedding_tls_failed: t('ui.embeddingTlsFailed', {}, 'TLS certificate verification or the secure connection failed.'),
            embedding_http_not_allowed: t('ui.embeddingHttpNotAllowed', {}, 'The endpoint uses HTTP, but HTTP access is not enabled.'),
            embedding_private_network_not_allowed: t('ui.embeddingPrivateNetworkNotAllowed', {}, 'The endpoint is on a private network, but private-network access is not enabled.'),
            embedding_url_invalid: t('ui.embeddingUrlInvalid', {}, 'The Embedding Base URL is invalid.'),
            embedding_dns_failed: t('ui.embeddingDnsFailed', {}, 'The Embedding server host name could not be resolved.'),
            embedding_connection_refused: t('ui.embeddingConnectionRefused', {}, 'The host refused the connection. Confirm that the Embedding service is running and listening on this port.'),
            embedding_unreachable: t('ui.embeddingUnreachable', {}, 'The Embedding server could not be reached.'),
        };
        let message = messages[code] || t('ui.embeddingUnavailable', {}, 'The Standard RAG embedding service is unavailable.');
        const facts = [];
        if (Number(details.http_status || 0) > 0) facts.push(`HTTP ${Number(details.http_status)}`);
        if (String(details.model_id || '').trim()) facts.push(`${t('ui.modelId', {}, 'Model ID')}: ${String(details.model_id)}`);
        if (Number(details.expected_dimensions || 0) > 0 || Number(details.actual_dimensions || 0) > 0) {
            facts.push(`${t('ui.embeddingDimensions', {}, 'Dimensions')}: ${Number(details.expected_dimensions || 0)} → ${Number(details.actual_dimensions || 0)}`);
        }
        return facts.length ? `${message} (${facts.join(' / ')})` : message;
    }

    async function request(action, options = {}) {
        const method = options.method || 'GET';
        const response = await fetch(`api.php?action=${encodeURIComponent(endpoint + '-' + action)}`, {
            method,
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.OPENCONCEPT_BOOT?.csrf || '',
            },
            body: options.body ? JSON.stringify(options.body) : undefined,
        });
        const raw = await response.text();
        let data = null;
        try {
            data = raw === '' ? null : JSON.parse(raw);
        } catch {
            data = null;
        }
        if (!data || typeof data !== 'object' || Array.isArray(data)) {
            const error = new Error(t('ui.invalidServerResponse', {}, 'The server returned an invalid RAG response. Your selections remain on this screen; reload to verify the saved state.'));
            error.code = 'invalid_json_response';
            error.status = response.status;
            error.data = {};
            error.settingsTarget = 'save';
            throw error;
        }
        if (!response.ok || data.error || data.ok === false) {
            const code = String(data.code || '');
            const errorMessage = code === 'pgvector_missing'
                ? t('ui.pgvectorRequired', {}, 'Standard vector search is unavailable because pgvector is missing from PostgreSQL. Regular AI Search remains available. Configure pgvector and the Embedding connection, then build and activate the index.')
                : code === 'postgresql_required'
                ? t('ui.postgresqlRequired', {}, 'Standard vector search is unavailable because PostgreSQL is not in use. Regular AI Search can still use generated summaries and chunks with LIKE and keyword queries in the current database. To add vector search, configure PostgreSQL, pgvector, and Embedding, then build and activate the index.')
                : embeddingFailureCodes.has(code)
                ? embeddingFailureMessage(code, data.details || {})
                : data.error || t('ui.requestFailed', {}, 'RAG request failed.');
            const error = new Error(errorMessage);
            error.code = code;
            error.details = data.details || {};
            error.status = response.status;
            error.data = data;
            error.settingsTarget = String(data.settings_target || data.details?.settings_target || '');
            throw error;
        }
        if (method !== 'GET' && data.ok !== true) {
            const error = new Error(t('ui.invalidServerResponse', {}, 'The server returned an invalid RAG response. Your selections remain on this screen; reload to verify the saved state.'));
            error.code = 'invalid_success_response';
            error.status = response.status;
            error.data = data;
            error.settingsTarget = 'save';
            throw error;
        }
        if (method === 'GET' && action === 'settings'
            && (!data.settings || typeof data.settings !== 'object'
                || typeof data.configuration_saved !== 'boolean'
                || !data.status || typeof data.status !== 'object'
                || !data.deployment_requirement || typeof data.deployment_requirement !== 'object')) {
            const error = new Error(t('ui.invalidServerResponse', {}, 'The server returned an invalid RAG response. Your selections remain on this screen; reload to verify the saved state.'));
            error.code = 'invalid_settings_response';
            error.status = response.status;
            error.data = data;
            error.settingsTarget = 'save';
            throw error;
        }
        return data;
    }

    function unmount() {
        if (flowHost?.isConnected) flowHost.innerHTML = '';
        flowHost = null;
        overlay = null;
        payload = null;
        notice = null;
        baseline = null;
        runtimeIssue = null;
        connectionChecks.retrieval = null;
        connectionChecks.answer = null;
        requestSequence++;
    }

    function checked(value) {
        return value ? ' checked' : '';
    }

    function selected(actual, expected) {
        return actual === expected ? ' selected' : '';
    }

    function toggleOption(name, enabled, labelKey, labelFallback, helpKey, helpFallback) {
        return `<label class="rag-core-toggle">
            <input type="checkbox" name="${esc(name)}"${checked(enabled)}>
            <span class="rag-core-toggle-copy">
                <span class="rag-core-toggle-heading"><strong>${esc(t(labelKey, {}, labelFallback))}</strong><em data-toggle-state>${esc(t(enabled ? 'ui.on' : 'ui.off', {}, enabled ? 'On' : 'Off'))}</em></span>
                <small>${esc(t(helpKey, {}, helpFallback))}</small>
            </span>
        </label>`;
    }

    function securityOptions(prefix, verifyTls, allowPrivate, allowHttp) {
        return `<section class="rag-core-option-group wide">
            <header class="rag-core-option-header"><strong>${esc(t('ui.connectionSecurity', {}, 'Connection security'))}</strong><span>${esc(t('ui.connectionSecurityHelp', {}, 'Select an option to enable that verification or connection permission.'))}</span></header>
            <div class="rag-core-option-list">
                ${toggleOption(`${prefix}_verify_ssl`, verifyTls, 'ui.verifySsl', 'Verify TLS certificates', 'ui.verifySslHelp', 'Verify the server certificate for HTTPS connections. Keep this on for normal HTTPS connections.')}
                ${toggleOption(`${prefix}_allow_private`, allowPrivate, 'ui.allowPrivate', 'Allow private network', 'ui.allowPrivateHelp', 'Allow connections to localhost, internal LAN, and Docker private addresses. Enable only when required.')}
                ${toggleOption(`${prefix}_allow_http`, allowHttp, 'ui.allowHttp', 'Allow HTTP', 'ui.allowHttpHelp', 'Allow unencrypted http:// connections. Enable only when required for a trusted local connection.')}
            </div>
        </section>`;
    }

    function behaviorOptions(streaming) {
        return `<section class="rag-core-option-group wide">
            <header class="rag-core-option-header"><strong>${esc(t('ui.behaviorOptions', {}, 'Behavior options'))}</strong><span>${esc(t('ui.behaviorOptionsHelp', {}, 'Select an option to enable that behavior.'))}</span></header>
            <div class="rag-core-option-list single">
                ${toggleOption('llm_streaming', streaming, 'ui.streaming', 'Enable streaming', 'ui.streamingHelp', 'Receive the answer in parts. Enable this only when the configured provider supports streaming.')}
            </div>
        </section>`;
    }

    function generationRows(status) {
        const generations = Array.isArray(status?.generations) ? status.generations : [];
        if (!generations.length) return `<p>${esc(t('ui.noGenerations', {}, 'No vector or custom search index generations exist yet. These are managed separately from intermediate data for regular AI Search.'))}</p>`;
        return generations.map(generation => {
            const state = String(generation.status || 'unknown');
            const generationId = String(generation.index_generation_id || '');
            const action = state === 'ready'
                ? `<button class="btn primary compact" type="button" data-generation-action="activate" data-generation-id="${esc(generationId)}">${esc(t('ui.activate', {}, 'Activate'))}</button>`
                : state === 'retired'
                ? `<button class="btn secondary compact" type="button" data-generation-action="rollback" data-generation-id="${esc(generationId)}">${esc(t('ui.rollback', {}, 'Rollback'))}</button>`
                : '';
            const metrics = generation.metrics || {};
            return `<div class="rag-core-generation"><div><strong>${esc(generationId)}</strong><small>${esc(t('ui.generationMetrics', {
                provider: generation.engine_plugin_id || '',
                state,
                documents: Number(metrics.documents ?? metrics.completed ?? 0),
                chunks: Number(metrics.chunks || 0),
                failed: Number(metrics.failed || 0),
            }, '{provider} · {state} · documents {documents} · chunks {chunks} · failed {failed}'))}</small></div>${action}</div>`;
        }).join('');
    }

    function choice(name, value, active, titleKey, titleFallback, helpKey, helpFallback, recommended = false) {
        return `<label class="rag-core-choice"><input type="radio" name="${esc(name)}" value="${esc(value)}"${checked(active)}><span><span class="rag-core-choice-heading"><strong>${esc(t(titleKey, {}, titleFallback))}</strong>${recommended ? `<em>${esc(t('ui.recommended', {}, 'Recommended'))}</em>` : ''}</span><small>${esc(t(helpKey, {}, helpFallback))}</small></span></label>`;
    }

    function sharedAiProvider() {
        const state = payload?.shared_ai_provider || {};
        const settings = state.settings || {};
        return {
            providerId: String(settings.provider_id || ''),
            name: String(settings.display_name || (settings.provider_id === 'openai' ? 'OpenAI' : t('ui.openAiCompatible', {}, 'OpenAI-compatible API'))),
            baseUrl: String(settings.base_url || ''),
            modelId: String(settings.text_model || ''),
            endpointMode: String(settings.endpoint_mode || 'chat_completions'),
            authMode: String(settings.auth_mode || 'bearer'),
            timeout: Number(settings.timeout || 120),
            verifySsl: settings.verify_tls !== false,
            allowPrivate: Boolean(settings.allow_private_network),
            allowHttp: Boolean(settings.allow_http),
            secretAvailable: state.secret?.available === true,
            keyAvailable: ['stored', 'environment'].includes(state.secret?.source) && state.secret?.available === true,
        };
    }

    function individualSecret(secret = {}) {
        return secret.individual_secret || { configured: secret.source === 'stored', available: secret.source === 'stored' && secret.available === true };
    }

    function keySettings(prefix, title, secret, useShared, environmentName, environmentValue, includeDelete = true) {
        const stored = individualSecret(secret);
        return `<section class="rag-core-key-settings wide" data-key-settings="${esc(prefix)}" data-individual-available="${stored.available === true}" data-individual-configured="${stored.configured === true}">
            <strong>${esc(title)}</strong>
            <label class="rag-core-inline-check"><input type="checkbox" name="${esc(prefix)}_use_shared_api_key"${checked(useShared)}> <span>${esc(t('ui.useSharedApiKey', {}, 'Use the common API key'))}</span></label>
            <p data-key-shared-help>${esc(t('ui.sharedApiKeyHelp', {}, 'Use the common API key with this connection. Switching to the common key keeps the saved individual key. The service must accept the common key.'))}</p>
            <label>${esc(t('ui.individualApiKey', {}, 'Individual API key'))}<input class="input" type="password" name="${esc(prefix)}_api_key" value="" autocomplete="new-password" maxlength="4096" placeholder="${stored.available ? esc(stored.hint || '••••') : ''}"></label>
            <p role="status" data-key-retained-help>${esc(t('ui.retainedApiKeyHelp', {}, 'Your previous key is saved and can still be used. To change it, enter a new key and save.'))}</p>
            <p role="status" data-key-destination-help>${esc(t('ui.retainedApiKeyDestinationHelp', {}, 'An individual key is saved for another connection. Restore its connection settings to use it, or enter a new key and save.'))}</p>
            <label>${esc(t('ui.apiKeyEnv', {}, 'API key environment variable'))}<input class="input" name="${esc(environmentName)}" value="${esc(environmentValue)}"><small>${esc(t('ui.secretEnvHelp', {}, 'Enter the environment-variable name, never the secret value.'))}</small></label>
            ${includeDelete ? keyDeleteControl(prefix) : ''}
        </section>`;
    }

    function keyDeleteControl(prefix) {
        return `<label class="rag-core-inline-check wide"><input type="checkbox" name="${esc(prefix)}_clear_api_key"> <span>${esc(t('ui.deleteIndividualApiKey', {}, 'Delete the saved individual API key when saving'))}</span></label>`;
    }

    function endpointFacts(value) {
        const raw = String(value || '').trim();
        if (!raw) return { valid: false, missing: true, http: false, private: false, unauthenticatedLocal: false };
        try {
            const url = new URL(raw);
            const host = url.hostname.replace(/^\[|\]$/g, '').toLowerCase();
            const privateIp = host === '::1' || /^f[cd][0-9a-f]*:/i.test(host) || /^fe[89ab][0-9a-f]*:/i.test(host)
                || /^127\./.test(host) || /^10\./.test(host) || /^169\.254\./.test(host) || /^192\.168\./.test(host)
                || (() => { const match = host.match(/^172\.(\d+)\./); return match ? Number(match[1]) >= 16 && Number(match[1]) <= 31 : false; })();
            const localhost = host === 'localhost' || host.endsWith('.localhost');
            const privateHost = localhost || privateIp || host === 'host.docker.internal' || host.endsWith('.local');
            return {
                valid: ['http:', 'https:'].includes(url.protocol) && !url.username && !url.password && !url.hash,
                missing: false,
                http: url.protocol === 'http:',
                private: privateHost,
                unauthenticatedLocal: localhost || privateIp,
            };
        } catch {
            return { valid: false, missing: false, http: false, private: false, unauthenticatedLocal: false };
        }
    }

    function configurationIssue(code, severity, stage, target, title, detail) {
        return { code, severity, stage, target, title, detail };
    }

    function validSecretEnvironment(value) {
        return /^[A-Z][A-Z0-9_]{1,127}$/.test(String(value || '').trim());
    }

    function endpointConfigurationIssues(stage, target, baseUrl, modelId, allowPrivate, allowHttp) {
        const issues = [];
        const facts = endpointFacts(baseUrl);
        if (facts.missing) {
            issues.push(configurationIssue('base_url_missing', 'error', stage, target,
                t('ui.issueBaseUrl', {}, 'Base URL is required'),
                t('ui.issueBaseUrlHelp', {}, 'Enter the root URL of the service used at this step.')));
        } else if (!facts.valid) {
            issues.push(configurationIssue('base_url_invalid', 'error', stage, target,
                t('ui.issueBaseUrlInvalid', {}, 'Base URL format is invalid'),
                t('ui.issueBaseUrlInvalidHelp', {}, 'Use http:// or https:// without credentials or a # fragment.')));
        }
        if (!String(modelId || '').trim()) {
            issues.push(configurationIssue('model_missing', 'error', stage, target,
                t('ui.issueModel', {}, 'Model ID is required'),
                t('ui.issueModelHelp', {}, 'Enter the exact model identifier exposed by this API.')));
        }
        if (facts.valid && facts.http && !allowHttp) {
            issues.push(configurationIssue('http_not_allowed', 'error', stage, target,
                t('ui.issueHttp', {}, 'HTTP connection is not allowed'),
                t('ui.issueHttpHelp', {}, 'Enable HTTP only for a trusted local connection, or use HTTPS.')));
        }
        if (facts.valid && facts.private && !allowPrivate) {
            issues.push(configurationIssue('private_not_allowed', 'error', stage, target,
                t('ui.issuePrivate', {}, 'Private-network connection is not allowed'),
                t('ui.issuePrivateHelp', {}, 'Allow private-network access for localhost, LAN, or Docker host addresses.')));
        }
        return issues;
    }

    function configurationDiagnostics(body) {
        const issues = [];
        const indexedRetrieval = ['standard', 'custom'].includes(body.retrieval_mode);
        const shared = sharedAiProvider();
        const standardReady = payload?.deployment_requirement?.ready === true;
        const deploymentCode = String(payload?.deployment_requirement?.code || 'postgresql_required');
        if (body.retrieval_mode === 'standard') {
            if (!standardReady) {
                const pgvectorMissing = deploymentCode === 'pgvector_missing';
                issues.push(configurationIssue(deploymentCode, 'error', 'retrieval', 'database',
                    pgvectorMissing
                        ? t('ui.issuePgvectorMissing', {}, 'pgvector is not installed in the canonical PostgreSQL database')
                        : t('ui.issueDatabase', {}, 'Standard vector search requires PostgreSQL'),
                    pgvectorMissing
                        ? t('ui.issuePgvectorMissingHelp', {}, 'Install pgvector for this PostgreSQL server, enable the vector extension, then reload this page. You can use Custom RAG without pgvector in the canonical database.')
                        : t('ui.issueDatabaseHelp', {}, 'Regular AI Search with LIKE and keyword queries is available on the current database. To add vector search, configure PostgreSQL, pgvector, and Embedding, then build and activate the index.')));
            }
            issues.push(...endpointConfigurationIssues('retrieval', 'retrieval', body.standard.base_url, body.standard.model_id,
                body.standard.allow_private_network, body.standard.allow_http));
            if (!body.standard.use_shared_api_key && String(body.standard.api_key_env || '').trim() !== '' && !validSecretEnvironment(body.standard.api_key_env)) {
                issues.push(configurationIssue('embedding_secret_reference_invalid', 'error', 'retrieval', 'retrieval',
                    t('ui.issueAuthentication', {}, 'Authentication setting is incomplete'),
                    t('ui.secretEnvHelp', {}, 'Enter a valid environment-variable name, never the secret value.')));
            }
            if (payload?.worker?.state === 'waiting_for_embedding') {
                const runtime = payload?.standard_embedding_secret?.runtime || {};
                const code = String(runtime.status || 'embedding_unreachable');
                issues.push(configurationIssue(code, 'warning', 'retrieval', 'operations',
                    t('ui.embeddingWaiting', {}, 'Waiting for Embedding'),
                    embeddingFailureMessage(code, runtime.details || {})));
            }
        } else if (body.retrieval_mode === 'custom') {
            const customFacts = endpointFacts(body.custom.base_url);
            if (customFacts.missing || !customFacts.valid) {
                issues.push(configurationIssue('custom_url_invalid', 'error', 'retrieval', 'retrieval',
                    t('ui.issueCustomRag', {}, 'Custom RAG connection is incomplete'),
                    t('ui.issueCustomRagHelp', {}, 'Enter a valid OpenConcept RAG API v1 root URL.')));
            }
            if (customFacts.valid && customFacts.http && !body.custom.allow_http) {
                issues.push(configurationIssue('custom_http_not_allowed', 'error', 'retrieval', 'retrieval',
                    t('ui.issueHttp', {}, 'HTTP connection is not allowed'),
                    t('ui.issueHttpHelp', {}, 'Enable HTTP only for a trusted local connection, or use HTTPS.')));
            }
            if (customFacts.valid && customFacts.private && !body.custom.allow_private_network) {
                issues.push(configurationIssue('custom_private_not_allowed', 'error', 'retrieval', 'retrieval',
                    t('ui.issuePrivate', {}, 'Private-network connection is not allowed'),
                    t('ui.issuePrivateHelp', {}, 'Allow private-network access for localhost, LAN, or Docker host addresses.')));
            }
            if (!body.custom.use_shared_api_key && body.custom.auth_mode !== 'none' && String(body.custom.secret_env || '').trim() !== '' && !validSecretEnvironment(body.custom.secret_env)) {
                issues.push(configurationIssue('custom_secret_reference_invalid', 'error', 'retrieval', 'retrieval',
                    t('ui.issueAuthentication', {}, 'Authentication setting is incomplete'),
                    t('ui.secretEnvHelp', {}, 'Enter a valid environment-variable name, never the secret value.')));
            }
            if (body.custom.auth_mode === 'none' && customFacts.valid && !customFacts.unauthenticatedLocal) {
                issues.push(configurationIssue('custom_auth_scope_invalid', 'error', 'retrieval', 'retrieval',
                    t('ui.issueAuthentication', {}, 'Unauthenticated Custom RAG must be local'),
                    t('ui.issueCustomAuthenticationHelp', {}, 'Use localhost or a literal private-network address, and explicitly allow private-network access.')));
            }
        }

        if (!indexedRetrieval || body.answer.mode === 'shared') {
            // Regular search uses the common AI connection. Its setup must not
            // prevent saving the choice to stop using a vector/custom index.
            const severity = indexedRetrieval && !body.answer.clear_api_key ? 'error' : 'warning';
            issues.push(...endpointConfigurationIssues('answer', 'shared-ai', shared.baseUrl, shared.modelId, shared.allowPrivate, shared.allowHttp)
                .map(issue => ({ ...issue, severity })));
            if (shared.authMode !== 'none' && !shared.secretAvailable) {
                issues.push(configurationIssue('shared_secret_missing', severity, 'answer', 'shared-ai',
                    t('ui.issueAuthentication', {}, 'AI authentication is incomplete'),
                    t('ui.issueSharedAuthenticationHelp', {}, 'Save an API key in the common AI connection, provide its environment variable, or select no authentication for a trusted local API.')));
            }
        }

        const configuredAnswerStage = indexedRetrieval ? 'answer' : 'pipeline';
        if (body.answer.mode === 'openai_compatible') {
            issues.push(...endpointConfigurationIssues(configuredAnswerStage, 'answer', body.answer.base_url, body.answer.model_id,
                body.answer.allow_private_network, body.answer.allow_http));
            const answerEnvironment = String(body.answer.api_key_env || '').trim();
            const answerSecretAvailable = individualSecret(payload?.answer_secret).available === true;
            if (!body.answer.use_shared_api_key && answerEnvironment !== '' && !validSecretEnvironment(answerEnvironment)) {
                issues.push(configurationIssue('answer_secret_reference_invalid', 'error', configuredAnswerStage, 'answer',
                    t('ui.issueAuthentication', {}, 'AI authentication is incomplete'),
                    t('ui.issueDirectAuthenticationHelp', {}, 'Enter an API key or a valid environment-variable name that contains it.')));
            } else if (!body.answer.use_shared_api_key && body.answer.auth_mode !== 'none' && answerEnvironment === '' && !String(body.answer.api_key || '').trim() && (!answerSecretAvailable || body.answer.clear_api_key)) {
                issues.push(configurationIssue('answer_secret_missing', body.answer.clear_api_key ? 'warning' : 'error', configuredAnswerStage, 'answer',
                    t('ui.issueAuthentication', {}, 'AI authentication is incomplete'),
                    t('ui.issueDirectAuthenticationHelp', {}, 'Enter an API key or the environment-variable name that contains it.')));
            }
        } else if (body.answer.mode === 'legacy_native') {
            issues.push(configurationIssue('legacy_answer_provider', 'error', configuredAnswerStage, 'answer',
                t('ui.issueLegacyProvider', {}, 'The saved native answer adapter is retired'),
                t('ui.issueLegacyProviderHelp', {}, 'Select the common AI connection or a separate OpenAI-compatible API, then save to migrate this setting.')));
        } else if (indexedRetrieval && body.answer.mode === 'none') {
            issues.push(configurationIssue('evidence_only', 'warning', 'answer', 'answer',
                t('ui.issueEvidenceOnly', {}, 'No LLM will generate an answer'),
                t('ui.issueEvidenceOnlyHelp', {}, 'Search results remain available as evidence data, but users will not receive a natural-language answer.')));
        }

        const sharedKeyConnections = [
            [body.retrieval_mode === 'standard', body.standard, 'retrieval'],
            [body.retrieval_mode === 'custom', body.custom, 'retrieval'],
            [body.answer.mode === 'openai_compatible', body.answer, configuredAnswerStage],
        ];
        for (const [active, connection, stage] of sharedKeyConnections) {
            if (active && connection.use_shared_api_key && connection.auth_mode !== 'none' && !shared.keyAvailable) {
                issues.push(configurationIssue('shared_api_key_missing', 'warning', stage, 'shared-ai',
                    t('ui.issueAuthentication', {}, 'AI authentication is incomplete'),
                    t('ui.sharedApiKeyMissing', {}, 'Save a common API key before using this connection. The source selection and key deletion can still be saved.')));
            }
        }

        const hasActiveGeneration = Array.isArray(payload?.status?.generations)
            && payload.status.generations.some(generation => generation?.status === 'active');
        if (['standard', 'custom'].includes(body.retrieval_mode) && payload?.configuration_saved === true && !hasActiveGeneration) {
            issues.push(configurationIssue('active_index_missing', 'warning', 'retrieval', 'operations',
                t('ui.issueIndex', {}, 'No active search index is available'),
                t('ui.issueIndexHelp', {}, 'Build the new generation, then activate it from Index status and maintenance.')));
        }
        const failedGeneration = Array.isArray(payload?.status?.generations)
            && payload.status.generations.find(generation => generation?.status === 'failed' || String(generation?.failure_code || '') !== '');
        if (indexedRetrieval && failedGeneration) {
            issues.push(configurationIssue('index_generation_failed', 'warning', 'retrieval', 'operations',
                t('ui.issueIndexFailed', {}, 'An index generation needs attention'),
                t('ui.issueIndexFailedHelp', { code: failedGeneration.failure_code || 'unknown' }, 'Open Index status and maintenance to inspect the failed generation ({code}).')));
        }
        if (payload?.configuration_saved !== true) {
            issues.push(configurationIssue('recommended_draft', 'warning', 'pipeline', 'save',
                t('ui.defaultDraft', {}, 'Recommended defaults are selected but not saved'),
                t('ui.defaultDraftHelp', {}, 'Review the highlighted checks, then save to activate this route.')));
        }
        if (runtimeIssue) {
            if (!indexedRetrieval && runtimeIssue.target === 'answer') runtimeIssue.stage = 'pipeline';
            issues.unshift(runtimeIssue);
        }
        return issues;
    }

    function flowStage({ stage, title, value, detail = '', process, impact, destination, state, target = '' }) {
        const guideRow = (label, text) => `<span class="rag-flow-stage-guide-row"><b>${esc(label)}</b><span>${esc(text)}</span></span>`;
        const content = `<span class="rag-flow-status" aria-hidden="true"></span>
                <small>${esc(title)}</small>
                <strong>${esc(value)}</strong>
                ${detail ? `<span class="rag-flow-stage-detail">${esc(detail)}</span>` : ''}
                <span class="rag-flow-stage-guide">
                    ${guideRow(t('ui.flowProcessLabel', {}, 'Process'), process)}
                    ${guideRow(t('ui.flowImpactLabel', {}, 'Effect of changing settings'), impact)}
                    ${guideRow(t('ui.flowDestinationLabel', {}, 'Screen and destination'), destination)}
                </span>`;
        return `<article class="rag-flow-stage ${esc(state)}" data-flow-stage="${esc(stage)}">
            ${target ? `<button type="button" data-focus-setting="${esc(target)}">${content}</button>` : `<div>${content}</div>`}
        </article>`;
    }

    function flowState(issues, stage, fallback = 'ready') {
        return issues.some(issue => issue.stage === stage && issue.severity === 'error')
            ? 'error'
            : issues.some(issue => issue.stage === stage && issue.severity === 'warning')
            ? 'warning'
            : fallback;
    }

    function configurationFlow(body, issues) {
        const shared = sharedAiProvider();
        const currentImpact = configurationImpact(body);
        const isDraft = payload?.configuration_saved !== true || !baseline
            || JSON.stringify(currentImpact) !== JSON.stringify(baseline);
        const ragEnabled = ['standard', 'custom'].includes(body.retrieval_mode);
        const retrievalValue = body.retrieval_mode === 'standard'
            ? t('ui.standard', {}, 'Standard vector search (PostgreSQL)')
            : body.retrieval_mode === 'custom'
            ? (body.custom.name || t('ui.custom', {}, 'Custom RAG'))
            : t('ui.disableRetrieval', {}, 'Regular AI Search (LIKE and keyword search)');
        const retrievalDetail = body.retrieval_mode === 'standard'
            ? `${body.standard.model_id || t('ui.notConfigured', {}, 'Not configured')} · ${body.standard.base_url || ''}${connectionChecks.retrieval === 'ok' ? ` · ${t('ui.connectionChecked', {}, 'Connection tested')}` : ''}`
            : body.retrieval_mode === 'custom'
            ? `${body.custom.base_url || t('ui.notConfigured', {}, 'Not configured')}${connectionChecks.retrieval === 'ok' ? ` · ${t('ui.connectionChecked', {}, 'Connection tested')}` : ''}`
            : t('ui.ragBypassHelp', {}, 'Search generated intermediate data in the current database.');
        const retrievalProcess = body.retrieval_mode === 'standard'
            ? t('ui.flowRetrievalStandardHelp', {}, 'OpenConcept searches its managed PostgreSQL/pgvector index and uses the configured Embedding API to vectorize content and the question.')
            : body.retrieval_mode === 'custom'
            ? t('ui.flowRetrievalCustomHelp', {}, 'The OpenConcept server sends the question and access scope to the configured OpenConcept RAG API v1 and receives evidence candidates from its index.')
            : t('ui.flowRetrievalDisabledHelp', {}, 'Regular AI Search finds summaries, keywords, and content with LIKE queries, supplemented by full-text search on MySQL. Retrieved documents provide evidence for AI answers.');
        const retrievalImpact = body.retrieval_mode === 'custom'
            ? t('ui.flowCustomReindexImpact', {}, 'Saving sends registered document snapshots to the configured service and builds an external index. The current route remains active until the new generation is activated.')
            : body.retrieval_mode === 'standard'
            ? t('ui.impactReindex', {}, 'Saving builds a new index generation. The current route remains active until it is activated.')
            : t('ui.impactRagDisabled', {}, 'Saving switches to regular AI Search using LIKE and keyword queries, without building a new vector or custom search index.');
        const retrievalDestination = ragEnabled
            ? t('ui.flowRetrievalDestination', {}, 'Candidates return to OpenConcept and continue to evidence verification. Nothing is shown directly at this step.')
            : t('ui.flowRetrievalDisabledDestination', {}, 'The browser does not open an external screen; processing continues through the common AI Search route.');
        const configuredAnswerValue = body.answer.mode === 'shared'
            ? shared.name
            : body.answer.mode === 'openai_compatible'
            ? t('ui.separateAi', {}, 'Use an individual endpoint and model')
            : body.answer.mode === 'legacy_native'
            ? String(body.answer.provider_id || t('ui.notConfigured', {}, 'Not configured'))
            : t('ui.evidenceOnly', {}, 'Display evidence data only');
        const configuredAnswerDetail = body.answer.mode === 'shared'
            ? `${shared.modelId || t('ui.notConfigured', {}, 'Not configured')} · ${shared.baseUrl || ''}`
            : body.answer.mode === 'openai_compatible'
            ? `${body.answer.model_id || t('ui.notConfigured', {}, 'Not configured')} · ${body.answer.base_url || ''}${connectionChecks.answer === 'ok' ? ` · ${t('ui.connectionChecked', {}, 'Connection tested')}` : ''}`
            : body.answer.mode === 'legacy_native'
            ? t('ui.legacyProviderDetail', {}, 'Unsupported saved adapter configuration')
            : t('ui.evidenceOnlyHelp', {}, 'The verified evidence is returned without an LLM call.');
        const retrievalState = flowState(issues, 'retrieval');
        const answerState = flowState(issues, 'answer', ragEnabled && body.answer.mode === 'none' ? 'warning' : 'ready');
        const retrievalBlocked = issues.some(issue => issue.stage === 'retrieval' && issue.severity === 'error');
        const answerBlocked = issues.some(issue => issue.stage === 'answer' && issue.severity === 'error');
        const routeBlocked = retrievalBlocked || answerBlocked;
        const answerValue = ragEnabled
            ? configuredAnswerValue
            : t('ui.flowAnswerFallbackValue', {}, 'Generate evidence-based answers with the common AI connection');
        const answerDetail = ragEnabled
            ? configuredAnswerDetail
            : `${shared.name} · ${shared.modelId || t('ui.notConfigured', {}, 'Not configured')} · ${shared.baseUrl || ''}`;
        const selectedAnswerProcess = body.answer.mode === 'shared'
            ? t('ui.flowAnswerSharedHelp', {}, 'The server sends the question and verified evidence to the common AI API configured under AI and voice providers and receives a natural-language answer.')
            : body.answer.mode === 'openai_compatible'
            ? t('ui.flowAnswerSeparateHelp', {}, 'The server sends the question and verified evidence to the RAG-only OpenAI-compatible API and receives an answer without changing the common AI and voice connection.')
            : body.answer.mode === 'none'
            ? t('ui.flowAnswerEvidenceOnlyHelp', {}, 'No LLM is called. Verified evidence is converted to Evidence JSON instead of a natural-language answer.')
            : t('ui.answerBlockedHelp', {}, 'Select a supported answer route before this step can run.');
        const answerProcess = !ragEnabled
            ? t('ui.flowAnswerFallbackHelp', {}, 'Evidence retrieved from the database is sent to the common AI connection to generate an answer. The answer settings below for vector or custom search are not used.')
            : retrievalBlocked
            ? `${t('ui.answerBlockedHelp', {}, 'Fix the retrieval step before this answer route can run.')} ${selectedAnswerProcess}`
            : selectedAnswerProcess;
        const answerImpact = !ragEnabled
            ? t('ui.flowAnswerFallbackImpact', {}, 'Configure answers for regular AI Search through the common AI connection. The settings below apply only to vector or custom search routes.')
            : body.answer.mode === 'shared'
            ? t('ui.flowAnswerSharedImpact', {}, 'Common AI connection changes affect both regular AI Search and RAG answers. No index rebuild is required.')
            : t('ui.impactAnswerOnly', {}, 'Changing this does not rebuild the index');
        const answerDestination = !ragEnabled
            ? t('ui.flowAnswerFallbackDestination', {}, 'The common AI response also returns to the OpenConcept AI Search panel.')
            : body.answer.mode === 'none'
            ? t('ui.flowAnswerEvidenceOnlyDestination', {}, 'Evidence JSON proceeds directly to result display inside OpenConcept. Nothing is sent to an external API and no external screen opens.')
            : t('ui.flowAnswerDestination', {}, 'The selected AI API only generates the answer. Its response returns to OpenConcept, and the provider screen is never opened.');
        const outputValue = !ragEnabled
            ? t('ui.flowFallbackOutput', {}, 'Common AI Search answer and sources')
            : body.answer.mode === 'none'
            ? t('ui.flowEvidenceOutput', {}, 'Evidence data')
            : t('ui.flowAnswerOutput', {}, 'Answer and sources');
        const outputProcess = routeBlocked
            ? t('ui.outputBlockedHelp', {}, 'Resolve the highlighted configuration problem before this route can be used.')
            : !ragEnabled
            ? t('ui.flowOutputFallbackHelp', {}, 'The common AI Search answer and sources are shown in the same OpenConcept AI Search panel.')
            : body.answer.mode === 'none'
            ? t('ui.flowOutputEvidenceOnlyHelp', {}, 'Evidence JSON is shown in the OpenConcept AI Search answer area, with sources listed below it.')
            : t('ui.flowOutputHelp', {}, 'The generated answer and permitted sources are shown in the OpenConcept AI Search panel. The display location stays the same when an external API is selected.');
        const generations = Array.isArray(payload?.status?.generations) ? payload.status.generations : [];
        const activeGeneration = generations.find(generation => generation?.status === 'active');
        const engines = Array.isArray(payload?.status?.engines) ? payload.status.engines : [];
        const primaryEngine = engines.find(engine => engine?.engine_instance_id === 'primary');
        const runtimeRagActive = Boolean(activeGeneration && primaryEngine?.enabled === true);
        const runtimeProvider = activeGeneration?.engine_plugin_id === 'openconcept-rag-standard'
            ? t('ui.standard', {}, 'Standard vector search (PostgreSQL)')
            : t('ui.custom', {}, 'Custom RAG');
        const runtimeRoute = runtimeRagActive
            ? t('ui.flowRuntimeRagHelp', { provider: runtimeProvider }, 'The active {provider} index is currently serving questions.')
            : t('ui.effectiveFallbackHelp', {}, 'This route searches summaries, keywords, and content in the current database and generates answers through the saved common AI connection. It requires generated intermediate data and a configured AI connection.');
        return `<section class="rag-flow" aria-label="${esc(t('ui.flowTitle', {}, 'Question-to-answer configuration flow'))}">
            <header><div><strong>${esc(t('ui.flowTitle', {}, 'Question-to-answer configuration flow'))}</strong><span>${esc(t('ui.flowHelp', {}, 'This diagram previews the selected route. Regular AI Search takes effect after saving. Changes to vector or custom search take effect after the new index generation is activated.'))}</span></div><em class="${isDraft ? 'draft' : 'saved'}">${esc(isDraft ? t('ui.flowDraft', {}, 'Editing (unsaved)') : t('ui.flowSaved', {}, 'Saved route'))}</em></header>
            <div class="rag-flow-location" role="note">
                <span class="rag-flow-location-icon" aria-hidden="true">↳</span>
                <span class="rag-flow-location-content">
                    <strong>${esc(t('ui.flowDisplayTitle', {}, 'Where results appear'))}</strong>
                    <span>${esc(t('ui.flowDisplayNotice', {}, 'Whichever RAG or AI API you select, results appear in the OpenConcept AI Search panel. The browser does not move to an external service screen.'))}</span>
                    <small>${esc(t('ui.flowScopeHelp', {}, 'This flow applies to regular workspace-wide AI Search. Current-page questions, page summaries, and page-edit actions use the common AI connection.'))}</small>
                    <span class="rag-flow-runtime"><b>${esc(t('ui.flowCurrentRouteLabel', {}, 'Currently effective route'))}</b><span>${esc(runtimeRoute)}</span></span>
                </span>
            </div>
            <div class="rag-flow-track">
                ${flowStage({ stage: 'question', title: t('ui.flowQuestion', {}, '1. Question'), value: t('ui.flowQuestionValue', {}, 'AI Search input'), process: t('ui.flowQuestionHelp', {}, 'The user submits a question from AI Search in the left menu (Ctrl/Cmd+K).'), impact: t('ui.flowQuestionImpact', {}, 'Changing retrieval or answer providers does not change where questions are entered.'), destination: t('ui.flowQuestionDestination', {}, 'The OpenConcept AI Search panel opened from the left menu. The submitted question remains in the conversation.'), state: 'ready' })}
                <span class="rag-flow-arrow" aria-hidden="true">&rarr;</span>
                ${flowStage({ stage: 'retrieval', title: t('ui.flowRetrieval', {}, '2. Find evidence'), value: retrievalValue, detail: retrievalDetail, process: retrievalProcess, impact: retrievalImpact, destination: retrievalDestination, state: retrievalState, target: 'retrieval' })}
                <span class="rag-flow-arrow" aria-hidden="true">&rarr;</span>
                ${flowStage({ stage: 'evidence', title: t('ui.flowEvidence', {}, '3. Verify evidence'), value: t('ui.flowEvidenceValue', {}, 'ACL and revision checks'), process: ragEnabled ? t('ui.flowEvidenceHelp', {}, 'OpenConcept rechecks current permissions, document revisions, and source identity for every candidate, removing inaccessible or stale evidence.') : t('ui.flowEvidenceSkippedHelp', {}, 'Regular AI Search checks current permissions and published source data, passing only evidence from accessible documents to answer generation.'), impact: t('ui.impactFixed', {}, 'Always enabled in RAG; no setting'), destination: ragEnabled ? t('ui.flowEvidenceDestination', {}, 'Only verified evidence proceeds to answer generation and later appears under Sources.') : t('ui.flowEvidenceSkippedDestination', {}, 'Accessible evidence is passed to answer generation and displayed as sources.'), state: retrievalBlocked ? 'blocked' : 'ready' })}
                <span class="rag-flow-arrow" aria-hidden="true">&rarr;</span>
                ${flowStage({ stage: 'answer', title: t('ui.flowAnswer', {}, '4. Generate answer'), value: answerValue, detail: answerDetail, process: answerProcess, impact: answerImpact, destination: answerDestination, state: retrievalBlocked ? 'blocked' : answerState, target: !ragEnabled || body.answer.mode === 'shared' ? 'shared-ai' : 'answer' })}
                <span class="rag-flow-arrow" aria-hidden="true">&rarr;</span>
                ${flowStage({ stage: 'output', title: t('ui.flowOutput', {}, '5. Show result'), value: outputValue, process: outputProcess, impact: t('ui.impactNone', {}, 'No setting'), destination: t('ui.flowOutputDestination', {}, 'The OpenConcept AI Search panel. Selecting a source below the answer opens its OpenConcept page in the main area.'), state: routeBlocked ? 'blocked' : 'ready' })}
            </div>
        </section>`;
    }

    function diagnosticsPanel(issues) {
        const errorCount = issues.filter(issue => issue.severity === 'error').length;
        const warningCount = issues.filter(issue => issue.severity === 'warning').length;
        const statusClass = errorCount ? 'error' : warningCount ? 'warning' : 'ready';
        const statusLabel = errorCount
            ? t('ui.diagnosticsErrors', { count: errorCount }, '{count} problems')
            : warningCount
            ? t('ui.diagnosticsWarnings', { count: warningCount }, '{count} checks')
            : t('ui.diagnosticsReady', {}, 'Ready');
        const rows = issues.length ? issues.map(issue => `<li class="${esc(issue.severity)}"><span aria-hidden="true"></span><div><strong>${esc(issue.title)}</strong><p>${esc(issue.detail)}</p></div>${issue.target ? `<button type="button" data-focus-setting="${esc(issue.target)}">${esc(t('ui.fixSetting', {}, 'Open setting'))}</button>` : ''}</li>`).join('')
            : `<li class="ready"><span aria-hidden="true"></span><div><strong>${esc(t('ui.diagnosticsReady', {}, 'Ready'))}</strong><p>${esc(t('ui.diagnosticsReadyHelp', {}, 'No contradictory or incomplete settings were detected.'))}</p></div></li>`;
        return `<section class="rag-diagnostics ${statusClass}" aria-live="polite"><header><div><strong>${esc(t('ui.diagnosticsTitle', {}, 'Configuration diagnosis'))}</strong><span>${esc(t('ui.diagnosticsHelp', {}, 'Problems are linked to the setting that must be corrected.'))}</span></div><em>${esc(statusLabel)}</em></header><ul>${rows}</ul></section>`;
    }

    function currentConfiguration(settings, deploymentRequirement) {
        const answer = settings.answer || {};
        const answerConnection = answer.configuration?.connection || {};
        const shared = sharedAiProvider();
        const generations = Array.isArray(payload?.status?.generations) ? payload.status.generations : [];
        const activeGeneration = generations.find(generation => generation?.status === 'active');
        const engines = Array.isArray(payload?.status?.engines) ? payload.status.engines : [];
        const primaryEngine = engines.find(engine => engine?.engine_instance_id === 'primary');
        const ragActive = Boolean(activeGeneration && primaryEngine?.enabled === true);
        const usesShared = answer.provider_id === 'openai-compatible' && answerConnection.use_shared_ai_provider === true;
        const answerName = answer.mode === 'none'
            ? t('ui.evidenceOnly', {}, 'Display Evidence JSON only')
            : usesShared
            ? t('ui.sharedAi', {}, 'Use the common AI connection (endpoint and model)')
            : answer.provider_id || t('ui.notConfigured', {}, 'Not configured');
        const answerDetail = answer.mode === 'none'
            ? t('ui.evidenceOnlyHelp', {}, 'Shows verified evidence without sending it to an LLM.')
            : usesShared
            ? `${shared.name} · ${shared.modelId || t('ui.notConfigured', {}, 'Not configured')} · ${shared.baseUrl || ''}`
            : `${answerConnection.model_id || t('ui.notConfigured', {}, 'Not configured')} · ${answerConnection.base_url || t('ui.notConfigured', {}, 'Not configured')}`;
        const databaseStatus = deploymentRequirement.ready === true
            ? t('ui.standardAvailable', {}, 'Database requirements for vector search are met')
            : deploymentRequirement.code === 'pgvector_missing'
            ? t('ui.standardPgvectorMissing', {}, 'Vector search: pgvector missing')
            : t('ui.standardUnavailable', {}, 'Vector search: PostgreSQL not in use');
        const activeProvider = activeGeneration?.engine_plugin_id === 'openconcept-rag-standard'
            ? t('ui.standard', {}, 'Standard vector search (PostgreSQL)')
            : t('ui.custom', {}, 'Custom RAG');
        const routeName = ragActive
            ? t('ui.effectiveRag', { provider: activeProvider }, 'Active RAG route: {provider}')
            : t('ui.effectiveFallback', {}, 'Regular AI Search (LIKE and keyword search)');
        const routeHelp = ragActive
            ? t('ui.effectiveRagHelp', { generation: activeGeneration.index_generation_id || '' }, 'Questions use active index generation {generation}, then the saved answer setting shown below.')
            : t('ui.effectiveFallbackHelp', {}, 'This route searches summaries, keywords, and content in the current database and generates answers through the saved common AI connection. It requires generated intermediate data and a configured AI connection.');
        const routeDetail = ragActive ? `${answerName} · ${answerDetail}` : `${shared.name} · ${shared.modelId || t('ui.notConfigured', {}, 'Not configured')} · ${shared.baseUrl || ''}`;
        return `<section class="rag-core-current" aria-label="${esc(t('ui.effectiveRoute', {}, 'Currently effective route'))}">
            <div class="rag-core-current-heading"><strong>${esc(t('ui.effectiveRoute', {}, 'Currently effective route'))}</strong><span>${esc(databaseStatus)}</span></div>
            <div class="rag-core-effective-route ${ragActive ? 'rag' : 'fallback'}"><span class="rag-core-route-status" aria-hidden="true"></span><div><strong>${esc(routeName)}</strong><p>${esc(routeHelp)}</p><small title="${esc(routeDetail)}">${esc(routeDetail)}</small></div></div>
        </section>`;
    }

    function workerPanel() {
        const worker = payload?.worker || {};
        const waiting = worker.state === 'waiting_for_embedding';
        const stateLabel = waiting
            ? t('ui.embeddingWaiting', {}, 'Waiting for Embedding')
            : worker.state === 'processing'
            ? t('ui.workerProcessing', {}, 'Processing')
            : worker.state === 'queued'
            ? t('ui.workerQueued', {}, 'Queued')
            : t('ui.workerIdle', {}, 'Idle');
        const runtime = payload?.standard_embedding_secret?.runtime || {};
        const failure = waiting ? embeddingFailureMessage(String(runtime.status || 'embedding_unreachable'), runtime.details || {}) : '';
        const guidance = payload?.embedding_server_guidance || {};
        return `<section class="rag-core-worker ${waiting ? 'waiting' : ''}">
            <header><div><strong>${esc(t('ui.workerStatus', {}, 'Worker status'))}</strong><span>${esc(stateLabel)}</span></div><em>${esc(t('ui.workerCounts', { queued: Number(worker.queued || 0), processing: Number(worker.processing || 0), failed: Number(worker.failed || 0) }, 'Queued {queued} / Processing {processing} / Failed {failed}'))}</em></header>
            ${failure ? `<p>${esc(failure)}</p><small>${esc(t('ui.embeddingServerAdminHelp', {}, 'OpenConcept reports and retries this failure, but a server administrator must start or correct the Embedding service.'))}</small>${guidance.command ? `<code>${esc(guidance.command)}</code>` : ''}` : ''}
            <div><button class="btn secondary" type="button" data-process${worker.run_now_available === false ? ' disabled' : ''}>${esc(t('ui.runWorkerNow', {}, 'Run worker now'))}</button>
            ${waiting ? `<button class="btn primary" type="button" data-retry-standard>${esc(t('ui.retryEmbedding', {}, 'Retry Embedding and rebuild'))}</button>` : ''}</div>
        </section>`;
    }


    function render() {
        if (!overlay || !payload) return;
        const settings = payload.settings || {};
        const deploymentRequirement = payload.deployment_requirement || {};
        const standardReady = deploymentRequirement.ready === true;
        const standardDefaultBaseUrl = payload.standard_embedding_default_base_url || 'http://127.0.0.1:8001/v1';
        const retrieval = settings.retrieval || { mode: 'unconfigured', configuration: {} };
        const retrievalMode = payload.configuration_saved !== true
            ? (standardReady ? 'standard' : 'unconfigured')
            : ['standard', 'custom', 'unconfigured'].includes(retrieval.mode) ? retrieval.mode : 'unconfigured';
        const retrievalConfiguration = retrieval.configuration || {};
        const retrievalProfiles = settings.retrieval_profiles || {};
        const standardConfiguration = retrievalMode === 'standard'
            ? retrievalConfiguration
            : (retrievalProfiles.standard || {});
        const customConfiguration = retrievalMode === 'custom'
            ? retrievalConfiguration
            : (retrievalProfiles.custom || {});
        const standard = standardConfiguration.embedding || {};
        const chunking = standardConfiguration.chunking || {};
        const custom = customConfiguration.connection || {};
        const answer = settings.answer || {};
        const activeAnswerConnection = answer.configuration?.connection || {};
        const answerConnection = activeAnswerConnection.use_shared_ai_provider === true || answer.mode !== 'provider'
            ? (settings.answer_profiles?.openai_compatible?.connection || {})
            : activeAnswerConnection;
        const answerSecret = payload.answer_secret || {};
        const embeddingSecret = payload.standard_embedding_secret || {};
        const customSecret = payload.custom_secret || {};
        const answerMode = payload.configuration_saved !== true && !answer.mode
            ? 'shared'
            : answer.mode === 'provider' && answer.provider_id === 'openai-compatible' && activeAnswerConnection.use_shared_ai_provider === true
            ? 'shared'
            : answer.mode === 'provider' && answer.provider_id === 'openai-compatible'
            ? 'openai_compatible'
            : answer.mode === 'native'
            ? 'legacy_native'
            : 'none';
        const shared = sharedAiProvider();
        const embeddingUseSharedKey = standard.use_shared_api_key ?? embeddingSecret.use_shared_api_key ?? Object.keys(standard).length === 0;
        const customUseSharedKey = custom.use_shared_api_key ?? customSecret.use_shared_api_key ?? Object.keys(custom).length === 0;
        const answerUseSharedKey = answerConnection.use_shared_api_key ?? answerSecret.use_shared_api_key ?? Object.keys(answerConnection).length === 0;
        const answerBaseUrl = answerConnection.base_url || shared.baseUrl || 'https://api.openai.com/v1';
        const standardBaseUrl = standard.base_url || standardDefaultBaseUrl;
        const standardBaseFacts = endpointFacts(standardBaseUrl);
        const answerBaseFacts = endpointFacts(answerBaseUrl);
        const answerAuthMode = answerConnection.auth_mode || shared.authMode || 'bearer';
        const answerVerifySsl = answerConnection.verify_ssl === undefined ? shared.verifySsl : answerConnection.verify_ssl !== false;
        const answerAllowPrivate = answerConnection.allow_private_network === undefined ? (shared.allowPrivate || answerBaseFacts.private) : Boolean(answerConnection.allow_private_network);
        const answerAllowHttp = answerConnection.allow_http === undefined ? (shared.allowHttp || answerBaseFacts.http) : Boolean(answerConnection.allow_http);
        const defaultEndpointMode = answerBaseUrl.replace(/\/+$/, '').toLowerCase() === 'https://api.openai.com/v1'
            ? 'responses'
            : 'chat_completions';
        const deploymentCode = String(deploymentRequirement.code || 'postgresql_required');
        const deploymentNotice = !standardReady
            ? `<p class="notice error" data-standard-requirement="${esc(deploymentCode)}">${esc(deploymentCode === 'pgvector_missing'
                ? t('ui.pgvectorRequired', {}, 'Standard vector search is unavailable because pgvector is missing from PostgreSQL. Regular AI Search remains available. Configure pgvector and the Embedding connection, then build and activate the index.')
                : t('ui.postgresqlRequired', {}, 'Standard vector search is unavailable because PostgreSQL is not in use. Regular AI Search can still use generated summaries and chunks with LIKE and keyword queries in the current database. To add vector search, configure PostgreSQL, pgvector, and Embedding, then build and activate the index.'))} <button class="btn secondary compact" type="button" data-focus-setting="database">${esc(t('ui.openDatabaseSettings', {}, 'Open database settings'))}</button></p>`
            : '';
        const legacyAnswerChoice = answerMode === 'legacy_native'
            ? `${choice('answer_mode', 'legacy_native', true, 'ui.legacyProvider', 'Retired answer adapter setting', 'ui.legacyProviderHelp', 'This saved route is retained only so you can migrate it. Select a supported answer route before saving.')}<div data-answer-mode-panel="legacy_native" class="rag-core-advanced"><p class="notice error">${esc(t('ui.issueLegacyProviderHelp', {}, 'Select the common AI connection or a separate OpenAI-compatible API, then save to migrate this setting.'))}</p></div>`
            : '';
        overlay.innerHTML = `<div class="rag-core-workspace" aria-label="${esc(t('core.name', {}, 'RAG Core'))}">
                ${flowHost?.isConnected ? '' : '<div class="rag-core-flow-overview" data-flow-preview></div>'}
                ${currentConfiguration(settings, deploymentRequirement)}
                <section class="rag-core-intermediate" role="note">
                    <strong>${esc(t('ui.intermediateDataTitle', {}, 'RAG is not limited to vector search'))}</strong>
                    <p>${esc(t('ui.intermediateDataHelp', {}, 'RAG uses retrieved information as evidence for AI answers. Without migrating to PostgreSQL, published-page source and permission data are saved, and summaries, tags, and chunks are generated after successful AI processing. A relational database can find summaries, keywords, and content with LIKE queries for use as evidence. LIKE matches text; MySQL also uses full-text search.'))}</p>
                    <p>${esc(t('ui.intermediateAnalysisHelp', {}, 'OpenConcept\'s standard vector search requires migration to PostgreSQL, the pgvector extension, and an Embedding connection. After documents are embedded and the search index is built and activated, vector search based on semantic similarity is also available.'))}</p>
                </section>
                ${notice ? `<div class="rag-core-persistent-message ${notice.type === 'error' ? 'error' : notice.type === 'warning' ? 'warning' : 'success'}" role="${notice.type === 'error' ? 'alert' : 'status'}">${esc(notice.text)}</div>` : ''}
                <div data-diagnostics-preview></div>
                <form class="rag-core-grid" data-settings-form>
                    <fieldset class="rag-core-section" id="ragCoreRetrievalSection">
                        <legend><span>1</span><div><strong>${esc(t('ui.retrievalEngine', {}, 'RAG retrieval engine'))}</strong><small>${esc(t('ui.retrievalEngineHelp', {}, 'Choose a search method. Vector and custom connection settings are retained separately; changing them creates a new index generation. Regular AI Search takes effect after saving.'))}</small></div></legend>
                        <div class="rag-core-availability" data-retrieval-availability><strong>${esc(t('ui.coreAlwaysAvailable', {}, 'RAG Core is always available'))}</strong><span>${esc(retrievalMode === 'unconfigured'
                            ? t('ui.providerUnconfigured', {}, 'Regular AI Search is selected. Intermediate data generation and search in the current database continue.')
                            : t('ui.providerConfigured', { provider: retrievalMode === 'standard' ? t('ui.standard', {}, 'Standard vector search (PostgreSQL)') : t('ui.custom', {}, 'Custom RAG') }, '{provider} is configured as the retrieval provider.'))}</span></div>
                        ${choice('retrieval_mode', 'standard', retrievalMode === 'standard', 'ui.standard', 'Standard vector search (PostgreSQL)', 'ui.standardChoiceHelp', 'OpenConcept manages chunking, embeddings, and search. PostgreSQL with pgvector is required.', standardReady)}
                        ${choice('retrieval_mode', 'custom', retrievalMode === 'custom', 'ui.custom', 'Custom RAG', 'ui.customChoiceHelp', 'Uses the built-in HTTP connection for the next Custom RAG generation. No plugin switch is required; search changes after that generation is activated.')}
                        ${choice('retrieval_mode', 'unconfigured', retrievalMode === 'unconfigured', 'ui.disableRetrieval', 'Regular AI Search (LIKE and keyword search)', 'ui.disableRetrievalHelp', 'Use the current database to find generated summaries, keywords, and content with LIKE queries and use them as evidence for AI answers. MySQL also uses full-text search.', !standardReady)}
                        ${deploymentNotice}
                        <div data-mode-panel="standard" class="rag-core-advanced">
                            <details${retrievalMode === 'standard' ? ' open' : ''}><summary>${esc(t('ui.standardDetails', {}, 'Standard RAG connection and advanced settings'))}</summary>
                                <div class="rag-core-fields">
                                    <label>${esc(t('ui.baseUrl', {}, 'Base URL'))}<input class="input" name="standard_base_url" value="${esc(standardBaseUrl)}" placeholder="${esc(standardDefaultBaseUrl)}"><small>${esc(t('ui.standardBaseUrlHelp', {}, 'OpenAI-compatible embeddings endpoint used by Standard RAG.'))}</small></label>
                                    ${keySettings('standard', t('ui.embeddingApiKey', {}, 'Embedding API key'), embeddingSecret, embeddingUseSharedKey, 'standard_api_key_env', standard.api_key_env || 'OPENCONCEPT_BGE_M3_API_KEY')}
                                    <label>${esc(t('ui.modelId', {}, 'Model ID'))}<input class="input" name="standard_model_id" value="${esc(standard.model_id || 'BAAI/bge-m3')}"><small>${esc(t('ui.embeddingModelHelp', {}, 'Changing the embedding model requires a complete new index generation.'))}</small></label>
                                    <label>${esc(t('ui.modelRevision', {}, 'Model revision'))}<input class="input" name="standard_model_revision" value="${esc(standard.model_revision || '')}"></label>
                                    <label>${esc(t('ui.timeout', {}, 'Timeout (seconds)'))}<input class="input" type="number" min="1" max="600" name="standard_timeout" value="${Number(standard.timeout || 60)}"></label>
                                    <label>${esc(t('ui.batchSize', {}, 'Embedding batch size'))}<input class="input" type="number" min="1" max="256" name="standard_batch_size" value="${Number(standard.batch_size || 32)}"></label>
                                    <label>${esc(t('ui.chunkTarget', {}, 'Target chunk characters'))}<input class="input" type="number" min="256" max="20000" name="target_characters" value="${Number(chunking.target_characters || 2400)}"><small>${esc(t('ui.chunkTargetHelp', {}, 'Approximate text size of each searchable unit. Smaller values improve precision but create more chunks.'))}</small></label>
                                    <label>${esc(t('ui.rrfK', {}, 'RRF constant'))}<input class="input" type="number" min="1" max="1000" name="rrf_k" value="${Number(standardConfiguration.rrf_k || 60)}"><small>${esc(t('ui.rrfHelp', {}, 'Controls how vector and keyword rankings are merged. Keep 60 unless you are tuning retrieval quality.'))}</small></label>
                                    ${securityOptions(
                                        'standard',
                                        standard.verify_ssl !== false,
                                        standard.allow_private_network === undefined ? standardBaseFacts.private : Boolean(standard.allow_private_network),
                                        standard.allow_http === undefined ? standardBaseFacts.http : Boolean(standard.allow_http)
                                    )}
                                    <div class="rag-core-panel-actions wide">
                                        <div class="rag-core-connection-test">
                                            <button class="btn secondary compact" type="button" data-test-standard aria-describedby="ragCoreStandardTestResult">${esc(t('ui.testConnection', {}, 'Test connection'))}</button>
                                            <span class="rag-core-connection-result" id="ragCoreStandardTestResult" data-connection-result="standard" data-state="idle" hidden></span>
                                        </div>
                                        <button class="btn primary compact" type="submit" data-rag-save data-save-location="standard">${esc(t('ui.save', {}, 'Save and rebuild when required'))}</button>
                                    </div>
                                </div>
                            </details>
                        </div>
                        <div data-mode-panel="custom" class="rag-core-advanced">
                            <div class="rag-core-fields">
                                <label>${esc(t('ui.connectionName', {}, 'Connection name'))}<input class="input" name="custom_name" value="${esc(custom.name || 'Custom RAG')}"></label>
                                <label>${esc(t('ui.baseUrl', {}, 'Base URL'))}<input class="input" name="custom_base_url" value="${esc(custom.base_url || '')}" placeholder="https://rag.example.com"><small>${esc(t('ui.customBaseUrlHelp', {}, 'Root URL of a service implementing OpenConcept RAG API v1. This is a retrieval API, not an LLM endpoint.'))}</small></label>
                                <label>${esc(t('ui.authentication', {}, 'Authentication'))}<select class="select" name="custom_auth_mode"><option value="bearer"${selected(custom.auth_mode || 'bearer', 'bearer')}>Bearer Token</option><option value="api_key"${selected(custom.auth_mode, 'api_key')}>API Key Header</option><option value="none"${selected(custom.auth_mode, 'none')}>None (local only)</option></select></label>
                                ${keySettings('custom', t('ui.customApiKey', {}, 'Custom RAG API key'), customSecret, customUseSharedKey, 'custom_secret_env', custom.secret_env || 'OPENCONCEPT_CUSTOM_RAG_API_KEY')}
                                <label>${esc(t('ui.indexNamespace', {}, 'Index / namespace'))}<input class="input" name="custom_index_id" value="${esc(custom.index_id || '')}"><small>${esc(t('ui.indexNamespaceHelp', {}, 'Leave empty when the external service should create and return an index ID.'))}</small></label>
                                <label>${esc(t('ui.connectTimeout', {}, 'Connection timeout (seconds)'))}<input class="input" type="number" min="1" max="120" name="custom_connect_timeout" value="${Number(custom.connect_timeout || 10)}"></label>
                                <label>${esc(t('ui.searchTimeout', {}, 'Search timeout (seconds)'))}<input class="input" type="number" min="1" max="600" name="custom_search_timeout" value="${Number(custom.search_timeout || 30)}"></label>
                                ${securityOptions('custom', custom.verify_ssl !== false, Boolean(custom.allow_private_network), Boolean(custom.allow_http))}
                                <div class="rag-core-panel-actions wide">
                                    <div class="rag-core-connection-test">
                                        <button class="btn secondary compact" type="button" data-test-custom aria-describedby="ragCoreCustomTestResult">${esc(t('ui.testConnection', {}, 'Test connection'))}</button>
                                        <span class="rag-core-connection-result" id="ragCoreCustomTestResult" data-connection-result="custom" data-state="idle" hidden></span>
                                    </div>
                                    <button class="btn primary compact" type="submit" data-rag-save data-save-location="custom">${esc(t('ui.save', {}, 'Save and rebuild when required'))}</button>
                                </div>
                            </div>
                        </div>
                    </fieldset>
                    <fieldset class="rag-core-section" id="ragCoreAnswerSection">
                        <legend><span>2</span><div><strong>${esc(t('ui.answerGeneration', {}, 'Answers for vector and custom search'))}</strong><small>${esc(t('ui.answerGenerationHelp', {}, 'Turns verified evidence into a readable answer. Changes in this section do not rebuild the index.'))}</small></div></legend>
                        ${choice('answer_mode', 'shared', answerMode === 'shared', 'ui.sharedAi', 'Use the common AI connection (endpoint and model)', 'ui.sharedAiHelp', 'Use the common endpoint, model, and API key for answers. Changes to the common connection apply here too.', true)}
                        ${choice('answer_mode', 'openai_compatible', answerMode === 'openai_compatible', 'ui.separateAi', 'Use an individual endpoint and model', 'ui.separateAiHelp', 'Configure an OpenAI-compatible endpoint and model for answers. You can choose the common API key or save an individual key for this connection.')}
                        ${choice('answer_mode', 'none', answerMode === 'none', 'ui.evidenceOnly', 'Display Evidence JSON only', 'ui.evidenceOnlyHelp', 'Do not call an LLM. Show only the verified evidence returned by retrieval.')}
                        ${legacyAnswerChoice}
                        <div data-answer-mode-panel="shared" class="rag-core-advanced"><div class="rag-core-shared-ai">
                            <div><span>${esc(t('ui.providerId', {}, 'Provider'))}</span><strong>${esc(shared.name || t('ui.notConfigured', {}, 'Not configured'))}</strong></div>
                            <div><span>${esc(t('ui.baseUrl', {}, 'Base URL'))}</span><strong title="${esc(shared.baseUrl)}">${esc(shared.baseUrl || t('ui.notConfigured', {}, 'Not configured'))}</strong></div>
                            <div><span>${esc(t('ui.modelId', {}, 'Model ID'))}</span><strong>${esc(shared.modelId || t('ui.notConfigured', {}, 'Not configured'))}</strong></div>
                            <button class="btn secondary compact" type="button" data-focus-setting="shared-ai">${esc(t('ui.openSharedAi', {}, 'Open common AI connection'))}</button>
                        </div></div>
                        <div data-answer-mode-panel="openai_compatible" class="rag-core-advanced"><div class="rag-core-fields">
                            <p class="wide rag-core-saved-notice">${esc(t('ui.savedValuesHelp', {}, 'Saved values are shown below. Connection testing does not save changes; select Save after testing.'))}</p>
                            <label>${esc(t('ui.baseUrl', {}, 'Base URL'))}<input class="input" name="llm_base_url" value="${esc(answerBaseUrl)}" placeholder="https://api.openai.com/v1"></label>
                            ${keySettings('llm', t('ui.answerApiKey', {}, 'Answer API key'), answerSecret, answerUseSharedKey, 'llm_api_key_env', answerConnection.api_key_env || 'OPENAI_API_KEY', false)}
                            <label>${esc(t('ui.authentication', {}, 'Authentication'))}<select class="select" name="llm_auth_mode"><option value="bearer"${selected(answerAuthMode, 'bearer')}>Bearer</option><option value="x-api-key"${selected(answerAuthMode, 'x-api-key')}>X-API-Key</option><option value="none"${selected(answerAuthMode, 'none')}>${esc(t('ui.noAuthentication', {}, 'None (trusted local API)'))}</option></select></label>
                            <label>${esc(t('ui.modelId', {}, 'Model ID'))}<input class="input" name="llm_model_id" value="${esc(answerConnection.model_id || shared.modelId || '')}"></label>
                            <label>${esc(t('ui.endpointMode', {}, 'Endpoint mode'))}<select class="select" name="llm_endpoint_mode"><option value="chat_completions"${selected(answerConnection.endpoint_mode || defaultEndpointMode, 'chat_completions')}>Chat Completions</option><option value="responses"${selected(answerConnection.endpoint_mode || defaultEndpointMode, 'responses')}>Responses</option></select></label>
                            <label>${esc(t('ui.timeout', {}, 'Timeout (seconds)'))}<input class="input" type="number" min="1" max="600" name="llm_timeout" value="${Number(answerConnection.timeout || 60)}"></label>
                            ${securityOptions('llm', answerVerifySsl, answerAllowPrivate, answerAllowHttp)}
                            ${behaviorOptions(Boolean(answerConnection.streaming))}
                            <div class="rag-core-connection-test wide">
                                <button class="btn secondary compact" type="button" data-test-llm aria-describedby="ragCoreLlmTestResult">${esc(t('ui.testConnection', {}, 'Test connection'))}</button>
                                <span class="rag-core-connection-result" id="ragCoreLlmTestResult" data-connection-result="llm" data-state="idle" hidden></span>
                            </div>
                        </div></div>
                        <p class="rag-core-key-retention-note">${esc(t('ui.keyRetentionHelp', {}, 'Switching to the common connection keeps the saved individual key. It is deleted only when you select delete and save.'))}</p>
                        ${keyDeleteControl('llm')}
                        <section class="rag-core-answer-instructions" data-answer-instructions>
                            <header><strong>${esc(t('ui.answerInstructions', {}, 'Answer instructions'))}</strong><span>${esc(t('ui.answerInstructionsHelp', {}, 'These settings shape the answer after evidence is verified. They never rebuild the search index.'))}</span></header>
                            <div class="rag-core-fields">
                                <label>${esc(t('ui.temperature', {}, 'Temperature'))}<input class="input" type="number" min="0" max="2" step="0.1" name="temperature" value="${Number(answer.temperature ?? answerConnection.temperature ?? 0.2)}"></label>
                                <label>${esc(t('ui.answerLanguage', {}, 'Answer language'))}<input class="input" name="answer_language" value="${esc(answer.language || '')}"></label>
                                <label class="wide">${esc(t('ui.systemPrompt', {}, 'System prompt'))}<textarea class="input" rows="7" name="system_prompt">${esc(answer.system_prompt || payload.default_system_prompt || '')}</textarea></label>
                            </div>
                        </section>
                    </fieldset>
                    <div class="rag-core-impact unchanged wide" data-impact><strong>${esc(t('ui.noUnsavedChanges', {}, 'No unsaved changes'))}</strong><span>${esc(t('ui.noUnsavedChangesHelp', {}, 'The summary above reflects the configuration currently in use.'))}</span></div>
                    <details class="rag-core-operations wide"><summary>${esc(t('ui.operations', {}, 'Index status and maintenance'))}</summary><div>
                        <p>${esc(t('ui.operationsHelp', {}, 'Use these controls to inspect index generations, process queued synchronization jobs, or retry one document.'))}</p>
                        ${workerPanel()}
                        <section class="rag-core-generations"><strong>${esc(t('ui.generations', {}, 'Index generations'))}</strong>${generationRows(payload.status)}</section>
                        <section class="rag-core-retry"><label>${esc(t('ui.retryDocumentId', {}, 'Document ID to reprocess'))}<input class="input" name="retry_document_id"></label> <button class="btn secondary compact" type="button" data-retry-document>${esc(t('ui.retryDocument', {}, 'Reprocess document'))}</button></section>
                    </div></details>
                    <div class="rag-core-actions" id="ragCoreSave"><button class="btn primary" type="submit" data-rag-save data-save-location="footer">${esc(t('ui.save', {}, 'Save and rebuild when required'))}</button></div>
                    <p class="rag-core-message wide" data-message role="status" aria-live="polite"></p>
                </form>
        </div>`;
        bind();
        updatePanels();
        baseline = configurationImpactFromSaved(settings);
        updatePreviews();
    }

    function formValues() {
        const form = overlay.querySelector('[data-settings-form]');
        const data = new FormData(form);
        const keyValue = prefix => {
            const input = form.querySelector(`[name="${prefix}_api_key"]`);
            return input?.disabled || form.querySelector(`[name="${prefix}_clear_api_key"]`)?.checked ? '' : String(input?.value || '');
        };
        const savedField = name => String(form.querySelector(`[name="${name}"]`)?.value || '');
        const embeddingApiKey = keyValue('standard');
        return {
            form,
            data,
            custom: {
                name: data.get('custom_name'), base_url: data.get('custom_base_url'), auth_mode: data.get('custom_auth_mode'),
                secret_env: savedField('custom_secret_env'), index_id: data.get('custom_index_id'), connect_timeout: Number(data.get('custom_connect_timeout') || 10),
                use_shared_api_key: data.has('custom_use_shared_api_key'), api_key: keyValue('custom'), clear_api_key: data.has('custom_clear_api_key'),
                search_timeout: Number(data.get('custom_search_timeout') || 30),
                verify_ssl: data.has('custom_verify_ssl'), allow_private_network: data.has('custom_allow_private'), allow_http: data.has('custom_allow_http'),
            },
            llm: {
                base_url: data.get('llm_base_url'), api_key_env: savedField('llm_api_key_env'), model_id: data.get('llm_model_id'),
                api_key: keyValue('llm'), use_shared_api_key: data.has('llm_use_shared_api_key'), clear_api_key: data.has('llm_clear_api_key'),
                endpoint_mode: data.get('llm_endpoint_mode'), auth_mode: data.get('llm_auth_mode') || 'bearer',
                timeout: Number(data.get('llm_timeout') || 60), streaming: data.has('llm_streaming'),
                temperature: Number(data.get('temperature') || 0.2), verify_ssl: data.has('llm_verify_ssl'),
                allow_private_network: data.has('llm_allow_private'), allow_http: data.has('llm_allow_http'),
            },
            embeddingApiKey,
        };
    }

    function settingsBody(requireRetrieval = true) {
        const { data, custom, llm, embeddingApiKey } = formValues();
        const answerMode = String(data.get('answer_mode') || 'none');
        const retrievalMode = data.get('retrieval_mode');
        if (requireRetrieval && !retrievalMode) {
            throw new Error(t('ui.providerRequired', {}, 'Select Standard RAG or Custom RAG before saving.'));
        }
        return {
            retrieval_mode: retrievalMode,
            standard: {
                base_url: data.get('standard_base_url'), api_key_env: String(overlay.querySelector('[name="standard_api_key_env"]')?.value || ''), model_id: data.get('standard_model_id'),
                api_key: embeddingApiKey, use_shared_api_key: data.has('standard_use_shared_api_key'), clear_api_key: data.has('standard_clear_api_key'),
                model_revision: data.get('standard_model_revision'), timeout: Number(data.get('standard_timeout') || 60),
                batch_size: Number(data.get('standard_batch_size') || 32), target_characters: Number(data.get('target_characters') || 2400), rrf_k: Number(data.get('rrf_k') || 60),
                verify_ssl: data.has('standard_verify_ssl'), allow_private_network: data.has('standard_allow_private'), allow_http: data.has('standard_allow_http'),
            },
            custom,
            answer: {
                mode: answerMode, ...llm,
                provider_id: answerMode === 'legacy_native' ? String(payload?.settings?.answer?.provider_id || '') : '',
                configuration: answerMode === 'legacy_native' ? (payload?.settings?.answer?.configuration || {}) : {},
                system_prompt: data.get('system_prompt'), language: data.get('answer_language'),
            },
        };
    }

    function standardImpact(configuration) {
        return {
            base_url: String(configuration.base_url || ''),
            api_key_env: String(configuration.api_key_env || ''),
            use_shared_api_key: configuration.use_shared_api_key === true,
            model_id: String(configuration.model_id || ''),
            model_revision: String(configuration.model_revision || ''),
            timeout: Number(configuration.timeout || 60),
            batch_size: Number(configuration.batch_size || 32),
            target_characters: Number(configuration.target_characters || 2400),
            rrf_k: Number(configuration.rrf_k || 60),
            verify_ssl: configuration.verify_ssl !== false,
            allow_private_network: Boolean(configuration.allow_private_network),
            allow_http: Boolean(configuration.allow_http),
        };
    }

    function customImpact(configuration) {
        return {
            name: String(configuration.name || ''),
            base_url: String(configuration.base_url || ''),
            auth_mode: String(configuration.auth_mode || 'bearer'),
            secret_env: String(configuration.secret_env || ''),
            use_shared_api_key: configuration.use_shared_api_key === true,
            index_id: String(configuration.index_id || ''),
            connect_timeout: Number(configuration.connect_timeout || 10),
            search_timeout: Number(configuration.search_timeout || 30),
            verify_ssl: configuration.verify_ssl !== false,
            allow_private_network: Boolean(configuration.allow_private_network),
            allow_http: Boolean(configuration.allow_http),
        };
    }

    function answerConnectionImpact(configuration) {
        return {
            base_url: String(configuration.base_url || ''),
            api_key_env: String(configuration.api_key_env || ''),
            use_shared_api_key: configuration.use_shared_api_key === true,
            model_id: String(configuration.model_id || ''),
            endpoint_mode: String(configuration.endpoint_mode || 'chat_completions'),
            auth_mode: String(configuration.auth_mode || 'bearer'),
            timeout: Number(configuration.timeout || 60),
            streaming: Boolean(configuration.streaming),
            verify_ssl: configuration.verify_ssl !== false,
            allow_private_network: Boolean(configuration.allow_private_network),
            allow_http: Boolean(configuration.allow_http),
        };
    }

    function configurationImpact(body) {
        if (!body) return null;
        const retrieval = body.retrieval_mode === 'standard'
            ? { mode: 'standard', configuration: standardImpact(body.standard) }
            : body.retrieval_mode === 'custom'
            ? { mode: 'custom', configuration: customImpact(body.custom) }
            : { mode: 'unconfigured' };
        const behavior = {
            temperature: Number(body.answer.temperature ?? 0.2),
            system_prompt: String(body.answer.system_prompt || ''),
            language: String(body.answer.language || ''),
        };
        const answer = body.answer.mode === 'shared'
            ? { mode: 'shared', ...behavior }
            : body.answer.mode === 'openai_compatible'
            ? { mode: 'openai_compatible', configuration: answerConnectionImpact(body.answer), api_key_changed: String(body.answer.api_key || '') !== '', ...behavior }
            : body.answer.mode === 'legacy_native'
            ? { mode: 'legacy_native', provider_id: body.answer.provider_id, configuration: body.answer.configuration }
            : { mode: 'none' };
        const credentials = ['standard', 'custom', 'answer'].map(name => ({
            changed: String(body[name]?.api_key || '') !== '',
            deleted: body[name]?.clear_api_key === true,
        }));
        return { retrieval, answer, credentials };
    }

    function configurationImpactFromSaved(settings) {
        const retrievalRecord = settings?.retrieval || {};
        const retrievalConfiguration = retrievalRecord.configuration || {};
        const retrieval = retrievalRecord.mode === 'standard'
            ? { mode: 'standard', configuration: standardImpact({
                ...(retrievalConfiguration.embedding || {}),
                target_characters: retrievalConfiguration.chunking?.target_characters,
                rrf_k: retrievalConfiguration.rrf_k,
            }) }
            : retrievalRecord.mode === 'custom'
            ? { mode: 'custom', configuration: customImpact(retrievalConfiguration.connection || {}) }
            : { mode: 'unconfigured' };
        const answerRecord = settings?.answer || {};
        const connection = answerRecord.configuration?.connection || {};
        const behavior = {
            temperature: Number(answerRecord.temperature ?? 0.2),
            system_prompt: String(answerRecord.system_prompt || ''),
            language: String(answerRecord.language || ''),
        };
        const answer = answerRecord.mode === 'provider' && answerRecord.provider_id === 'openai-compatible' && connection.use_shared_ai_provider === true
            ? { mode: 'shared', ...behavior }
            : answerRecord.mode === 'provider' && answerRecord.provider_id === 'openai-compatible'
            ? { mode: 'openai_compatible', configuration: answerConnectionImpact(connection), api_key_changed: false, ...behavior }
            : answerRecord.mode === 'native'
            ? { mode: 'legacy_native', provider_id: String(answerRecord.provider_id || ''), configuration: answerRecord.configuration || {} }
            : { mode: 'none' };
        return { retrieval, answer, credentials: ['standard', 'custom', 'answer'].map(() => ({ changed: false, deleted: false })) };
    }

    function updateImpactPreview() {
        const node = overlay?.querySelector('[data-impact]');
        if (!node || !baseline) return;
        let current;
        try {
            current = configurationImpact(settingsBody(false));
        } catch {
            return;
        }
        const retrievalChanged = JSON.stringify(current.retrieval) !== JSON.stringify(baseline.retrieval);
        const answerChanged = JSON.stringify(current.answer) !== JSON.stringify(baseline.answer)
            || JSON.stringify(current.credentials) !== JSON.stringify(baseline.credentials);
        if (retrievalChanged && current.retrieval.mode === 'unconfigured') {
            node.className = 'rag-core-impact answer-only wide';
            node.innerHTML = `<strong>${esc(t('ui.regularSearchImpact', {}, 'Switch to regular AI Search'))}</strong><span>${esc(t('ui.impactRagDisabled', {}, 'Saving switches to regular AI Search using LIKE and keyword queries, without building a new vector or custom search index.'))}</span>`;
            return;
        }
        const mode = retrievalChanged ? 'rebuild' : answerChanged ? 'answer-only' : 'unchanged';
        const titleKey = mode === 'rebuild' ? 'ui.rebuildRequired' : mode === 'answer-only' ? 'ui.noRebuildRequired' : 'ui.noUnsavedChanges';
        const helpKey = mode === 'rebuild' ? 'ui.rebuildRequiredHelp' : mode === 'answer-only' ? 'ui.noRebuildRequiredHelp' : 'ui.noUnsavedChangesHelp';
        node.className = `rag-core-impact ${mode} wide`;
        node.innerHTML = `<strong>${esc(t(titleKey, {}, mode === 'rebuild' ? 'New index generation required' : mode === 'answer-only' ? 'No index rebuild required' : 'No unsaved changes'))}</strong><span>${esc(t(helpKey, {}, mode === 'rebuild' ? 'Saving starts a new generation. The current active index remains available until the new one is activated.' : mode === 'answer-only' ? 'Only answer generation changes; the existing search index remains active.' : 'The summary above reflects the configuration currently in use.'))}</span>`;
    }

    function structuralErrors(issues) {
        return issues.filter(issue => issue.severity === 'error' && issue !== runtimeIssue);
    }

    function bindFocusNavigation(root) {
        if (!(root instanceof Element)) return;
        root.onclick = event => {
            const trigger = event.target instanceof Element
                ? event.target.closest('[data-focus-setting]')
                : null;
            if (!trigger || !root.contains(trigger)) return;
            event.stopPropagation();
            focusSetting(String(trigger.dataset.focusSetting || ''));
        };
    }

    function updatePreviews() {
        if (!overlay) return;
        let body;
        try {
            body = settingsBody(false);
        } catch (error) {
            message(error.message, true);
            return;
        }
        const issues = configurationDiagnostics(body);
        const flow = flowHost?.isConnected ? flowHost : overlay.querySelector('[data-flow-preview]');
        const diagnostics = overlay.querySelector('[data-diagnostics-preview]');
        if (flow) flow.innerHTML = configurationFlow(body, issues);
        if (diagnostics) diagnostics.innerHTML = diagnosticsPanel(issues);
        bindFocusNavigation(overlay);
        if (flow) bindFocusNavigation(flow);
        const form = overlay.querySelector('[data-settings-form]');
        const saveDisabled = structuralErrors(issues).length > 0;
        if (form?.dataset.busy !== 'true') {
            form?.querySelectorAll('[data-rag-save]').forEach(saveButton => {
                saveButton.disabled = saveDisabled;
            });
        }
        updateImpactPreview();
    }

    function focusNode(node) {
        if (!(node instanceof Element)) return;
        if (node instanceof HTMLDetailsElement) node.open = true;
        node.scrollIntoView({ behavior: 'smooth', block: 'center' });
        node.classList.add('rag-core-focus-pulse');
        window.setTimeout(() => node.classList.remove('rag-core-focus-pulse'), 1800);
        const control = node.matches('input,select,textarea,button,summary')
            ? node
            : node.querySelector('input:not([disabled]),select:not([disabled]),textarea:not([disabled]),button:not([disabled]),summary');
        if (control instanceof HTMLElement) window.setTimeout(() => control.focus({ preventScroll: true }), 250);
    }

    function focusSetting(target) {
        const selectors = {
            retrieval: '#ragCoreRetrievalSection',
            answer: '#ragCoreAnswerSection',
            'shared-ai': '#aiProviderDetails',
            operations: '.rag-core-operations',
            save: '#ragCoreSave',
        };
        if (target === 'database') {
            document.getElementById('settingsTab-plugins')?.click();
            window.setTimeout(() => focusNode(document.querySelector('[data-plugin-card-id="database-postgresql-adapter"]') || document.getElementById('settingsPanel-plugins')), 50);
            return;
        }
        const selector = selectors[target];
        if (!selector) return;
        if (target === 'shared-ai') {
            document.getElementById('settingsTab-ai')?.click();
            const details = document.getElementById('aiProviderDetails');
            if (details instanceof HTMLDetailsElement) details.open = true;
        }
        focusNode(document.querySelector(selector));
    }

    function runtimeIssueFromError(error, stage, target) {
        const code = String(error?.code || 'request_failed');
        const settingsTarget = String(error?.settingsTarget || error?.details?.settings_target || target || '');
        const resolvedTarget = settingsTarget === 'database' || settingsTarget === 'retrieval' || settingsTarget === 'answer' || settingsTarget === 'shared-ai'
            ? settingsTarget
            : target;
        const embeddingFailure = embeddingFailureCodes.has(code);
        const detail = embeddingFailure
            ? `${embeddingFailureMessage(code, error?.details || {})} ${t('ui.embeddingServerAdminHelp', {}, 'OpenConcept reports and retries this failure, but a server administrator must start or correct the Embedding service.')}`
            : String(error?.message || t('ui.requestFailed', {}, 'RAG request failed.'));
        return configurationIssue(code, 'error', stage, resolvedTarget,
            embeddingFailure ? t('ui.embeddingCheckFailed', {}, 'Embedding preflight failed') : t('ui.runtimeIssue', {}, 'The last operation failed'),
            detail);
    }

    function runtimeIssueFromEmbedding(embedding) {
        const code = String(embedding?.status || 'embedding_unreachable');
        return configurationIssue(code, 'warning', 'retrieval', 'operations',
            t('ui.embeddingWaiting', {}, 'Waiting for Embedding'),
            `${embeddingFailureMessage(code, embedding?.details || {})} ${t('ui.embeddingRetryHelp', {}, 'The settings were saved. Correct the server-side problem, then retry the check and rebuild.')}`);
    }

    function message(text, error = false) {
        const node = overlay?.querySelector('[data-message]');
        if (!node) return;
        node.textContent = text;
        node.classList.toggle('error', error);
    }

    function connectionResult(scope, state = 'idle', text = '') {
        const node = overlay?.querySelector(`[data-connection-result="${scope}"]`);
        if (!node) return;
        const visible = state !== 'idle' && String(text).trim() !== '';
        node.hidden = !visible;
        node.dataset.state = visible ? state : 'idle';
        node.textContent = visible ? String(text) : '';
    }

    function connectionTestBusy(button, busy) {
        button.disabled = busy;
        button.setAttribute('aria-busy', busy ? 'true' : 'false');
    }

    function updatePanels() {
        const mode = overlay.querySelector('input[name="retrieval_mode"]:checked')?.value || 'unconfigured';
        const availability = overlay.querySelector('[data-retrieval-availability] > span');
        if (availability) availability.textContent = mode === 'unconfigured'
            ? t('ui.providerUnconfigured', {}, 'Regular AI Search is selected. Intermediate data generation and search in the current database continue.')
            : t('ui.providerConfigured', { provider: mode === 'standard' ? t('ui.standard', {}, 'Standard vector search (PostgreSQL)') : t('ui.custom', {}, 'Custom RAG') }, '{provider} is configured as the retrieval provider.');
        overlay.querySelectorAll('[data-mode-panel]').forEach(panel => { panel.hidden = panel.dataset.modePanel !== mode; });
        overlay.querySelectorAll('[data-standard-requirement]').forEach(node => { node.hidden = mode !== 'standard'; });
        if (mode === 'standard') {
            const details = overlay.querySelector('[data-mode-panel="standard"] details');
            if (details instanceof HTMLDetailsElement) details.open = true;
        }
        const answerMode = overlay.querySelector('input[name="answer_mode"]:checked')?.value || 'none';
        overlay.querySelectorAll('[data-answer-mode-panel]').forEach(panel => { panel.hidden = panel.dataset.answerModePanel !== answerMode; });
        overlay.querySelectorAll('[data-answer-instructions]').forEach(panel => { panel.hidden = !['shared', 'openai_compatible'].includes(answerMode); });
        overlay.querySelectorAll('[data-key-settings]').forEach(section => {
            const prefix = section.dataset.keySettings;
            const useShared = section.querySelector(`[name="${prefix}_use_shared_api_key"]`)?.checked === true;
            const noAuth = overlay.querySelector(`[name="${prefix}_auth_mode"]`)?.value === 'none';
            const directAnswerInactive = prefix === 'llm' && answerMode !== 'openai_compatible';
            const deleting = overlay.querySelector(`[name="${prefix}_clear_api_key"]`)?.checked === true;
            const baseUrl = String(overlay.querySelector(`[name="${prefix}_base_url"]`)?.value || '').trim().replace(/\/+$/, '');
            const authMode = overlay.querySelector(`[name="${prefix}_auth_mode"]`)?.value || 'bearer';
            section.dataset.initialBaseUrl ??= baseUrl;
            section.dataset.initialAuthMode ??= authMode;
            const canReuse = section.dataset.individualAvailable === 'true' && section.dataset.initialBaseUrl === baseUrl && section.dataset.initialAuthMode === authMode;
            const credentialInputs = section.querySelectorAll('input:not([type="checkbox"])');
            credentialInputs.forEach(input => { input.disabled = useShared || noAuth || directAnswerInactive || (deleting && input.type === 'password'); });
            section.querySelector('[data-key-shared-help]').hidden = !useShared;
            section.querySelector('[data-key-retained-help]').hidden = useShared || noAuth || deleting || !canReuse;
            section.querySelector('[data-key-destination-help]').hidden = useShared || noAuth || deleting || section.dataset.individualConfigured !== 'true' || canReuse;
        });
    }

    function bind() {
        const bindToggle = input => {
            input.addEventListener('change', () => {
                const state = input.closest('.rag-core-toggle')?.querySelector('[data-toggle-state]');
                if (state) state.textContent = t(input.checked ? 'ui.on' : 'ui.off', {}, input.checked ? 'On' : 'Off');
            });
        };
        overlay.querySelectorAll('.rag-core-toggle input[type="checkbox"]').forEach(bindToggle);
        const form = overlay.querySelector('[data-settings-form]');
        const formChanged = event => {
            const name = String(event.target?.name || '');
            if (name.startsWith('standard_') || name.startsWith('custom_') || ['retrieval_mode', 'target_characters', 'rrf_k'].includes(name)) {
                connectionChecks.retrieval = null;
            }
            if (name.startsWith('standard_') || ['target_characters', 'rrf_k'].includes(name)) connectionResult('standard');
            if (name.startsWith('custom_')) connectionResult('custom');
            if (name === 'retrieval_mode') {
                connectionResult('standard');
                connectionResult('custom');
            }
            if (name.startsWith('llm_') || ['answer_mode', 'temperature', 'answer_language', 'system_prompt'].includes(name)) {
                connectionChecks.answer = null;
                connectionResult('llm');
            }
            runtimeIssue = null;
            message('');
            updatePanels();
            updatePreviews();
        };
        form.addEventListener('input', formChanged);
        form.addEventListener('change', formChanged);
        overlay.querySelector('[data-settings-form]').onsubmit = async event => {
            event.preventDefault();
            const form = event.currentTarget;
            if (form.dataset.busy === 'true') return;
            const saveButtons = [...form.querySelectorAll('[data-rag-save]')];
            const body = settingsBody();
            const errors = structuralErrors(configurationDiagnostics(body));
            if (errors.length) {
                message(t('ui.saveBlockedHelp', {}, 'Correct the highlighted configuration problems before saving.'), true);
                focusSetting(errors[0].target);
                return;
            }
            form.dataset.busy = 'true';
            form.setAttribute('aria-busy', 'true');
            saveButtons.forEach(button => { button.disabled = true; });
            message(t('ui.saving', {}, 'Saving…'));
            try {
                const result = await request('settings', { method: 'POST', body });
                const savedPayload = await request('settings');
                const savedMode = String(savedPayload.settings?.retrieval?.mode || 'unconfigured');
                if (savedMode !== body.retrieval_mode) {
                    const error = new Error(t('ui.settingsNotPersisted', {}, 'The saved settings could not be verified. Your selections remain on this screen.'));
                    error.code = 'settings_not_persisted';
                    error.settingsTarget = 'save';
                    throw error;
                }
                payload = savedPayload;
                runtimeIssue = result.waiting_for_embedding === true
                    ? runtimeIssueFromEmbedding(result.embedding || {})
                    : null;
                connectionChecks.retrieval = null;
                connectionChecks.answer = null;
                notice = result.waiting_for_retrieval === true
                    ? { type: 'warning', text: t('ui.savedWaitingForRetrieval', {}, 'Settings saved. Retrieval is waiting because its connection or index rebuild failed. Check the retrieval service and save again.') }
                    : result.waiting_for_credentials === true
                    ? { type: 'warning', text: t('ui.savedWaitingForCredentials', {}, 'Saved. Configure the selected API key, then save this connection again to enable retrieval.') }
                    : result.waiting_for_embedding === true
                    ? { type: 'warning', text: t('ui.savedWaitingForEmbedding', {}, 'Saved. Index rebuilding is waiting for the Embedding service; the common AI route remains available.') }
                    : {
                        type: 'success',
                        text: body.retrieval_mode === 'unconfigured'
                            ? t('ui.savedRegularSearch', {}, 'Saved. Regular AI Search uses LIKE and keyword queries. Intermediate data generation continues.')
                            : result.generation
                            ? t('ui.savedRebuildStarted', {}, 'Saved. A new index generation is now building; activate it after it becomes ready.')
                            : t('ui.savedNoRebuild', {}, 'Saved. The current search index remains active because retrieval settings did not change.'),
                    };
                render();
            } catch (error) {
                notice = { type: 'error', text: error.message };
                const target = error.settingsTarget
                    || (['postgresql_required', 'pgvector_missing'].includes(error.code) ? 'database'
                        : embeddingFailureCodes.has(error.code) ? 'retrieval' : 'save');
                const stage = ['answer', 'shared-ai'].includes(target) ? 'answer' : target === 'database' ? 'retrieval' : 'pipeline';
                runtimeIssue = runtimeIssueFromError(error, stage, target);
                message(error.message, true);
                form.dataset.busy = 'false';
                form.setAttribute('aria-busy', 'false');
                updatePreviews();
            }
        };
        overlay.querySelector('[data-test-standard]').onclick = async event => {
            const button = event.currentTarget;
            connectionTestBusy(button, true);
            connectionChecks.retrieval = null;
            runtimeIssue = null;
            const testingMessage = t('ui.testing', {}, 'Testing connection...');
            connectionResult('standard', 'pending', testingMessage);
            message(testingMessage);
            try {
                await request('connection-test', { method: 'POST', body: { type: 'standard-rag', configuration: settingsBody(false).standard } });
                connectionChecks.retrieval = 'ok';
                const successMessage = t('ui.connectionOkSaveRequired', {}, 'Connection succeeded. Save these values before using them.');
                connectionResult('standard', 'success', successMessage);
                message(successMessage);
            } catch (error) {
                runtimeIssue = runtimeIssueFromError(error, 'retrieval', 'retrieval');
                connectionResult('standard', 'error', error.message);
                message(error.message, true);
            } finally {
                connectionTestBusy(button, false);
                updatePreviews();
            }
        };
        overlay.querySelector('[data-test-custom]').onclick = async event => {
            const button = event.currentTarget;
            connectionTestBusy(button, true);
            connectionChecks.retrieval = null;
            runtimeIssue = null;
            const testingMessage = t('ui.testing', {}, 'Testing connection...');
            connectionResult('custom', 'pending', testingMessage);
            message(testingMessage);
            try {
                await request('connection-test', { method: 'POST', body: { type: 'custom-rag', configuration: formValues().custom } });
                connectionChecks.retrieval = 'ok';
                const successMessage = t('ui.connectionOkSaveRequired', {}, 'Connection succeeded. Save these values before using them.');
                connectionResult('custom', 'success', successMessage);
                message(successMessage);
            } catch (error) {
                runtimeIssue = runtimeIssueFromError(error, 'retrieval', 'retrieval');
                connectionResult('custom', 'error', error.message);
                message(error.message, true);
            } finally {
                connectionTestBusy(button, false);
                updatePreviews();
            }
        };
        overlay.querySelector('[data-test-llm]').onclick = async event => {
            const button = event.currentTarget;
            connectionTestBusy(button, true);
            connectionChecks.answer = null;
            runtimeIssue = null;
            const testingMessage = t('ui.testing', {}, 'Testing connection...');
            connectionResult('llm', 'pending', testingMessage);
            message(testingMessage);
            try {
                await request('connection-test', { method: 'POST', body: { type: 'openai-compatible', configuration: formValues().llm } });
                connectionChecks.answer = 'ok';
                const successMessage = t('ui.connectionOkSaveRequired', {}, 'Connection succeeded. Save these values before using them.');
                connectionResult('llm', 'success', successMessage);
                message(successMessage);
            } catch (error) {
                runtimeIssue = runtimeIssueFromError(error, 'answer', 'answer');
                connectionResult('llm', 'error', error.message);
                message(error.message, true);
            } finally {
                connectionTestBusy(button, false);
                updatePreviews();
            }
        };
        overlay.querySelector('[data-process]').onclick = async event => {
            const button = event.currentTarget;
            button.disabled = true;
            try {
                const result = await request('process', { method: 'POST', body: { limit: 100 } });
                notice = { type: 'success', text: t('ui.jobsProcessed', { count: Number(result.result?.processed || 0) }, 'Processed {count} queued jobs.') };
                payload = await request('settings'); render();
            }
            catch (error) {
                runtimeIssue = runtimeIssueFromError(error, 'retrieval', 'operations');
                message(error.message, true);
                button.disabled = false;
                updatePreviews();
            }
        };
        const retryStandard = overlay.querySelector('[data-retry-standard]');
        if (retryStandard) retryStandard.onclick = async event => {
            const button = event.currentTarget;
            button.disabled = true;
            runtimeIssue = null;
            message(t('ui.testing', {}, 'Testing connection...'));
            try {
                const result = await request('standard-retry', { method: 'POST', body: {} });
                payload = await request('settings');
                if (result.waiting_for_embedding === true) {
                    runtimeIssue = runtimeIssueFromEmbedding(result.embedding || {});
                    notice = { type: 'warning', text: t('ui.stillWaitingForEmbedding', {}, 'The Embedding service is still unavailable. No index jobs were started.') };
                } else {
                    notice = { type: 'success', text: t('ui.embeddingRecoveredRebuildStarted', {}, 'Embedding is available. A new RAG index generation is now building.') };
                }
                render();
            } catch (error) {
                runtimeIssue = runtimeIssueFromError(error, 'retrieval', 'operations');
                message(error.message, true);
                button.disabled = false;
                updatePreviews();
            }
        };
        overlay.querySelector('[data-retry-document]').onclick = async event => {
            const documentId = String(overlay.querySelector('[name="retry_document_id"]')?.value || '').trim();
            if (!documentId) { message(t('ui.retryDocumentRequired', {}, 'Enter a document ID.'), true); return; }
            const button = event.currentTarget;
            button.disabled = true;
            try {
                const result = await request('document-retry', { method: 'POST', body: { document_id: documentId } });
                notice = { type: 'success', text: t('ui.retryQueued', { count: Number(result.queued || 0) }, 'Document reprocessing was queued.') };
                payload = await request('settings');
                render();
            } catch (error) {
                runtimeIssue = runtimeIssueFromError(error, 'retrieval', 'operations');
                message(error.message, true);
                button.disabled = false;
                updatePreviews();
            }
        };
        overlay.querySelectorAll('[data-generation-action]').forEach(button => {
            button.onclick = async () => {
                button.disabled = true;
                try {
                    await request(`generation-${button.dataset.generationAction}`, { method: 'POST', body: { generation_id: button.dataset.generationId } });
                    notice = { type: 'success', text: t('ui.generationChanged', {}, 'The active index generation was updated.') };
                    payload = await request('settings'); render();
                }
                catch (error) {
                    runtimeIssue = runtimeIssueFromError(error, 'retrieval', 'operations');
                    message(error.message, true);
                    button.disabled = false;
                    updatePreviews();
                }
            };
        });
    }

    async function mount(host) {
        if (!(host instanceof Element)) return;
        const sequence = ++requestSequence;
        overlay = host;
        flowHost = document.getElementById('ragCoreFlowMount');
        payload = null;
        baseline = null;
        notice = null;
        overlay.innerHTML = `<div class="rag-core-loading">${esc(t('ui.loading', {}, 'Loading…'))}</div>`;
        if (flowHost) flowHost.innerHTML = `<div class="rag-core-loading">${esc(t('ui.loading', {}, 'Loading…'))}</div>`;
        try {
            const nextPayload = await request('settings');
            if (sequence !== requestSequence || overlay !== host || !host.isConnected) return;
            payload = nextPayload;
            render();
        } catch (error) {
            if (sequence !== requestSequence || overlay !== host || !host.isConnected) return;
            overlay.innerHTML = `<div class="rag-core-persistent-message error" role="alert">${esc(error.message)}</div>`;
            if (flowHost?.isConnected) flowHost.innerHTML = `<div class="rag-core-persistent-message error" role="alert">${esc(error.message)}</div>`;
        }
    }

    function open() {
        const host = document.getElementById('ragCoreSettingsMount');
        if (host) mount(host);
        else document.querySelector('[data-action="settings"]')?.click();
    }

    window.OpenConceptRagSettings = Object.freeze({ open, mount, unmount });
    window.addEventListener('openconcept:locale-change', () => { if (overlay?.isConnected) mount(overlay); });
})();
