<?php

declare(strict_types=1);

final class FileReaderService
{
    private const FORMATS = ['pdf', 'doc', 'docx', 'xls', 'xlsx'];
    private const MIME = ['pdf' => 'application/pdf', 'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
    private const DEFAULTS = [
        'automatic' => false, 'connection' => 'dedicated', 'provider_id' => 'openai',
        'base_url' => 'https://api.openai.com/v1', 'endpoint_mode' => 'responses',
        'structured_output_mode' => 'json_schema', 'auth_mode' => 'bearer',
        'text_model' => 'gpt-5.6-luna', 'timeout' => 60, 'max_file_mb' => 10,
        'max_pages' => 60, 'max_output_tokens' => 16000, 'daily_requests' => 100,
        'allow_private_network' => false, 'allow_http' => false, 'verify_tls' => true,
    ];
    private ?Closure $clientFactory;
    private string $locale = 'ja-JP';

    public function __construct(private readonly object $api, private readonly ?object $commonSettings = null, ?callable $clientFactory = null, private readonly ?object $i18n = null)
    {
        $this->clientFactory = $clientFactory === null ? null : Closure::fromCallable($clientFactory);
    }

    public function register(): void
    {
        $this->api->registerJob('scan', fn(array $payload, array $job): array => $this->scan(), ['triggers' => ['login', 'page-view']]);
        $this->api->registerJob('extract', fn(array $payload, array $job): array => $this->extract($payload, $job), ['triggers' => ['login', 'page-view']]);
        foreach (['login', 'page-view'] as $trigger) {
            $this->api->registerTrigger($trigger, function (): void {
                // Recovery is local and remains available when automatic API calls are paused.
                $this->api->enqueue('scan', [], 'trigger-scan:' . intdiv(time(), 30));
            });
        }
        $this->api->subscribe('source.published', function (): void {
            if ($this->settings()['automatic']) {
                $this->api->enqueue('scan', [], 'publication-scan:' . intdiv(time(), 30));
            }
        });
        $this->api->registerAction('settings', fn(string $mode, array $payload, array $actor): array => $this->settingsAction($mode, $payload, $actor));
        $this->api->registerAction('review', fn(string $mode, array $payload, array $actor): array => $this->reviewAction($mode, $payload, $actor));
    }

    private function settings(): array
    {
        return $this->settingsRecord()['value'];
    }

    private function settingsRecord(): array
    {
        $record = $this->api->getData('configuration', 'current');
        if ($record !== null) {
            return ['value' => array_replace(self::DEFAULTS, $record['value']), 'revision' => (int) $record['revision']];
        }
        // Read the pre-release setting without mutating it. The first successful save migrates its key.
        $stored = $this->api->getSetting('configuration', []);
        $settings = array_replace(self::DEFAULTS, is_array($stored) ? $stored : []);
        $settings['secret_ref'] = 'api_key';
        $settings['credential_destination'] = $this->credentialDestination($settings);
        return ['value' => $settings, 'revision' => 0];
    }

    private function credentialDestination(array $settings): string
    {
        return hash('sha256', json_encode(array_intersect_key($settings, array_flip(['base_url', 'provider_id', 'auth_mode'])), JSON_THROW_ON_ERROR));
    }

    private function configuration(?array $settings = null): array
    {
        $settings ??= $this->settings();
        $reference = (string) ($settings['secret_ref'] ?? '');
        $settings['api_key'] = $reference !== '' && hash_equals((string) ($settings['credential_destination'] ?? ''), $this->credentialDestination($settings))
            ? (string) ($this->api->getSecret($reference) ?? '') : '';
        if ($settings['connection'] === 'shared') {
            if ($this->commonSettings === null) {
                throw new RuntimeException('shared_connection_unavailable');
            }
            $shared = $this->commonSettings->resolved();
            // An explicitly selected common connection supplies transport credentials only.
            foreach (['provider_id', 'base_url', 'endpoint_mode', 'structured_output_mode', 'auth_mode', 'api_key', 'allow_private_network', 'allow_http', 'verify_tls'] as $key) {
                if (array_key_exists($key, $shared)) {
                    $settings[$key] = $shared[$key];
                }
            }
        }
        $settings['display_name'] = 'AI File Reader';
        return $settings;
    }

    private function fingerprint(array $configuration): string
    {
        unset($configuration['automatic'], $configuration['daily_requests'], $configuration['max_file_mb'], $configuration['max_pages']);
        return hash('sha256', json_encode($configuration, JSON_THROW_ON_ERROR));
    }

    private function ready(array $configuration): bool
    {
        return ($configuration['auth_mode'] === 'none' || $configuration['api_key'] !== '')
            && hash_equals((string) $this->api->getSetting('verified_connection', ''), $this->fingerprint($configuration));
    }

    private function client(array $configuration): object
    {
        return $this->clientFactory !== null ? ($this->clientFactory)($configuration) : new FileReaderClient($configuration);
    }

    private static function reference(array $source): array
    {
        return array_intersect_key($source, array_flip(['source_type', 'source_id', 'source_version', 'content_hash', 'page_id']));
    }

    private static function key(array $reference): string
    {
        return hash('sha256', implode('|', [(string) ($reference['source_type'] ?? ''), (string) ($reference['source_id'] ?? ''), (string) ($reference['source_version'] ?? ''), (string) ($reference['content_hash'] ?? '')]));
    }

    public function scan(): array
    {
        $this->recoverFailedJobs();
        $configuration = $this->configuration();
        if (!$configuration['automatic'] || !$this->ready($configuration)) {
            return ['state' => 'paused'];
        }
        $cursor = $this->api->getSetting('scan_cursor', null);
        $page = $this->api->listPublishedSources(is_string($cursor) && $cursor !== '' ? $cursor : null, 25, self::FORMATS);
        $queued = 0;
        foreach ($page['items'] as $source) {
            $reference = self::reference($source);
            $key = self::key($reference);
            $record = $this->api->getData('candidates', $key);
            if ($record === null) {
                $candidate = ['reference' => $reference, 'filename' => $source['filename'], 'state' => 'queued', 'text' => '', 'issues' => [], 'locations' => [], 'next_chunk' => 0, 'created_at' => gmdate('c')];
                try {
                    $this->api->putData('candidates', $key, $candidate, 0);
                } catch (RuntimeException $exception) {
                    continue; // A concurrent scan owns this version.
                }
                $this->api->setFileState($reference, 'queued', '');
            } elseif (!in_array($record['value']['state'] ?? '', ['queued', 'processing'], true)) {
                continue;
            }
            $next = (int) ($record['value']['next_chunk'] ?? 0);
            $this->api->enqueue('extract', ['key' => $key], 'extract:' . $key . ':' . $next . ':' . intdiv(time(), 30));
            $queued++;
        }
        $this->api->setSetting('scan_cursor', $page['next_cursor'] ?? null);
        return ['queued' => $queued];
    }

    private function recoverFailedJobs(): void
    {
        $cursor = $this->api->getSetting('failed_job_cursor', null);
        $jobs = $this->api->jobs('failed', is_string($cursor) ? $cursor : null, 25);
        foreach ($jobs['items'] as $job) {
            $key = (string) ($job['payload']['key'] ?? '');
            if (($job['job_name'] ?? '') !== 'extract' || preg_match('/^[a-f0-9]{64}$/D', $key) !== 1) { continue; }
            $this->api->transaction(function () use ($key): void {
                $record = $this->api->getData('candidates', $key);
                if ($record === null || !in_array($record['value']['state'] ?? '', ['queued', 'processing'], true)) { return; }
                $candidate = $record['value'];
                $reference = $candidate['reference'];
                if ($this->api->source($reference) === null) {
                    $candidate['state'] = 'superseded';
                    $this->api->putData('candidates', $key, $candidate, $record['revision']);
                    return;
                }
                $candidate['state'] = 'review';
                $candidate['issues'] = array_values(array_unique([...$candidate['issues'], 'worker_interrupted']));
                unset($candidate['plan']);
                $this->api->putData('candidates', $key, $candidate, $record['revision']);
                $this->api->setFileState($reference, 'review', '');
                $this->api->notifyAdmins('file_reading_review', $this->tr('review.notification', ['filename' => $candidate['filename']]), ['key' => $key] + $reference, 'review', 'review:' . $key);
            });
        }
        $this->api->setSetting('failed_job_cursor', $jobs['next_cursor'] ?? null);
    }

    public function extract(array $payload, array $job = []): array
    {
        $configuration = $this->configuration();
        if (!$configuration['automatic'] || !$this->ready($configuration)) {
            return ['state' => 'paused'];
        }
        $key = (string) ($payload['key'] ?? '');
        $record = $this->api->getData('candidates', $key);
        if ($record === null || !in_array($record['value']['state'] ?? '', ['queued', 'processing'], true)) {
            return ['state' => 'skipped'];
        }
        $candidate = $record['value'];
        $revision = (int) $record['revision'];
        $reference = $candidate['reference'];
        $source = $this->api->source($reference);
        if ($source === null) {
            $candidate['state'] = 'superseded';
            $this->api->putData('candidates', $key, $candidate, $revision);
            return ['state' => 'superseded'];
        }
        try {
            if (!isset($candidate['plan'])) {
                $bytes = $this->api->readSource($reference);
                if (strlen($bytes) > (int) $configuration['max_file_mb'] * 1048576) {
                    throw new RuntimeException('file_size_limit');
                }
                $extension = strtolower(pathinfo($source['filename'], PATHINFO_EXTENSION));
                $plan = in_array($extension, ['xlsx', 'xls', 'docx'], true)
                    ? FileReaderOffice::$extension($bytes)
                    : ['units' => [], 'issues' => [], 'coverage' => 'file'];
                if (!in_array($extension, self::FORMATS, true)) {
                    throw new RuntimeException('unsupported_format');
                }
                $candidate['extension'] = $extension;
                $candidate['issues'] = array_merge($candidate['issues'], $plan['issues']);
                $candidate['plan'] = $this->batches($plan['units']);
                $candidate['expected_pages'] = $extension === 'pdf' ? FileReaderClient::pdfPageCount($bytes) : null;
                if ($candidate['expected_pages'] !== null && $candidate['expected_pages'] > (int) $configuration['max_pages']) {
                    throw new RuntimeException('page_limit');
                }
                if ($plan['coverage'] !== 'file' && $plan['units'] === []) {
                    throw new RuntimeException('empty_extraction');
                }
                $candidate['configuration'] = $this->fingerprint($configuration);
                $candidate['state'] = 'processing';
                $revision = $this->api->putData('candidates', $key, $candidate, $revision);
            }
            if (!hash_equals($candidate['configuration'], $this->fingerprint($configuration))) {
                throw new RuntimeException('configuration_changed');
            }
            $usageKey = gmdate('Y-m-d');
            $reserved = $this->api->transaction(function () use ($usageKey, $configuration): bool {
                $usage = $this->api->getData('usage', $usageKey);
                $value = $usage['value'] ?? ['requests' => 0, 'input_tokens' => 0, 'output_tokens' => 0];
                if ($value['requests'] >= (int) $configuration['daily_requests']) {
                    return false;
                }
                $value['requests']++;
                $this->api->putData('usage', $usageKey, $value, (int) ($usage['revision'] ?? 0));
                return true;
            });
            if (!$reserved) {
                $this->api->enqueue('extract', ['key' => $key], 'budget:' . $key . ':' . $usageKey, 3600);
                return ['state' => 'budget_paused'];
            }
            $chunkIndex = (int) $candidate['next_chunk'];
            $units = $candidate['plan'][$chunkIndex] ?? [];
            $file = null;
            if ($candidate['plan'] === []) {
                $bytes = $this->api->readSource($reference);
                $mime = self::MIME[$candidate['extension']];
                $file = ['filename' => $source['filename'], 'mime_type' => $mime, 'data_url' => 'data:' . $mime . ';base64,' . base64_encode($bytes), 'detail' => 'high'];
            }
            if (is_callable($job['heartbeat'] ?? null)) {
                ($job['heartbeat'])();
            }
            $result = $this->client($configuration)->extract($units, $file, $candidate['expected_pages']);
            // Never commit an API reply against a deleted or replaced source version.
            if ($this->api->source($reference) === null) {
                $candidate['state'] = 'superseded';
                $this->api->putData('candidates', $key, $candidate, $revision);
                return ['state' => 'superseded'];
            }
            $candidate['text'] .= ($candidate['text'] === '' ? '' : "\n\n") . $result['text'];
            $candidate['issues'] = array_merge($candidate['issues'], $result['issues']);
            $candidate['locations'] = array_merge($candidate['locations'], $result['locations']);
            $candidate['model'] = $result['model'];
            $candidate['next_chunk']++;
            $this->api->transaction(function () use ($usageKey, $result): void {
                $usage = $this->api->getData('usage', $usageKey);
                $value = $usage['value'];
                $value['input_tokens'] += $result['usage']['input_tokens'];
                $value['output_tokens'] += $result['usage']['output_tokens'];
                $this->api->putData('usage', $usageKey, $value, (int) $usage['revision']);
            });
            if (strlen($candidate['text']) > 4194304) {
                throw new RuntimeException('extracted_text_limit');
            }
            if ($candidate['next_chunk'] < count($candidate['plan'])) {
                $this->api->putData('candidates', $key, $candidate, $revision);
                $this->api->enqueue('extract', ['key' => $key], 'extract:' . $key . ':' . $candidate['next_chunk']);
                return ['state' => 'processing'];
            }
            if (trim($candidate['text']) === '') {
                $candidate['issues'][] = 'empty_extraction';
            }
            if ($candidate['issues'] === []) {
                $candidate['state'] = 'registered';
                $candidate['registered_at'] = gmdate('c');
                unset($candidate['plan']);
                $this->api->transaction(function () use ($reference, $candidate, $key, $revision): void {
                    $this->api->acceptSearchData($reference, $candidate['text'], $this->provenance($candidate));
                    $this->api->putData('candidates', $key, $candidate, $revision);
                    $this->api->setFileState($reference, 'registered', '');
                });
                return ['state' => 'registered'];
            }
        } catch (Throwable $exception) {
            // Provider messages may contain submitted text; store a fixed diagnostic code instead.
            $message = $exception->getMessage();
            $candidate['issues'][] = preg_match('/^[a-z][a-z0-9_]{2,80}$/D', $message) ? $message : 'provider_read_failed';
        }
        $candidate['state'] = 'review';
        $candidate['issues'] = array_values(array_unique($candidate['issues']));
        unset($candidate['plan']);
        $this->api->transaction(function () use ($reference, $candidate, $key, $revision): void {
            $this->api->putData('candidates', $key, $candidate, $revision);
            $this->api->setFileState($reference, 'review', '');
            $this->api->notifyAdmins('file_reading_review', $this->tr('review.notification', ['filename' => $candidate['filename']]), ['key' => $key] + $reference, 'review', 'review:' . $key);
        });
        return ['state' => 'review'];
    }

    private function provenance(array $candidate): array
    {
        return ['plugin' => 'ai-file-reader', 'model' => $candidate['model'] ?? '', 'locations' => $candidate['locations'], 'issues' => $candidate['issues'], 'registered_at' => gmdate('c')];
    }

    private function batches(array $units): array
    {
        $batches = [];
        $batch = [];
        $size = 0;
        $total = 0;
        foreach ($units as $unit) {
            $bytes = strlen($unit['text']);
            if (($total += $bytes) > 2097152 || $bytes > 48000) {
                throw new RuntimeException('text_input_limit');
            }
            if ($batch !== [] && ($size + $bytes > 24000 || count($batch) >= 120)) {
                $batches[] = $batch;
                $batch = [];
                $size = 0;
            }
            $batch[] = $unit;
            $size += $bytes;
        }
        if ($batch !== []) {
            $batches[] = $batch;
        }
        return $batches;
    }

    private function admin(array $actor): void
    {
        if (($actor['role'] ?? '') !== 'admin' || (int) ($actor['id'] ?? 0) < 1) {
            throw new RuntimeException('Administrator access required.', 403);
        }
        $this->locale = (string) ($actor['ui_locale'] ?? 'ja-JP');
    }

    private function tr(string $key, array $parameters = []): string
    {
        if ($this->i18n !== null) {
            return $this->i18n->translate($key, $parameters, $this->locale, 'ai-file-reader');
        }
        return $key . ($parameters === [] ? '' : ': ' . implode(', ', $parameters));
    }

    public function reviewAction(string $mode, array $payload, array $actor): array
    {
        $this->admin($actor);
        if ($mode === 'invoke') {
            return $this->api->transaction(fn(): array => $this->reviewAction('decision', $payload, $actor));
        }
        $key = (string) ($payload['key'] ?? '');
        $record = $this->api->getData('candidates', $key);
        if ($record === null) {
            throw new RuntimeException('Review result unavailable.', 404);
        }
        $candidate = $record['value'];
        $reference = $candidate['reference'];
        $current = $this->api->source($reference, $actor);
        if ($current === null || !($current['readable'] ?? false)) {
            return ['title' => $this->tr('review.title'), 'blocks' => [['type' => 'text', 'text' => $this->tr('review.stale')]], 'buttons' => []];
        }
        if ($mode === 'decision') {
            if ((string) ($payload['version'] ?? '') !== (string) $record['revision'] || $candidate['state'] !== 'review') {
                throw new RuntimeException('Review has already changed. Reopen it.', 409);
            }
            $button = (string) ($payload['button'] ?? '');
            if (!in_array($button, ['register', 'discard'], true)) {
                throw new InvalidArgumentException('Unknown review action.');
            }
            if ($button === 'register' && trim($candidate['text']) === '') {
                throw new RuntimeException('No extracted text is available. Replace the file before registration.', 422);
            }
            // CAS reserves the decision before any shared side effect.
            $candidate['state'] = $button === 'register' ? 'registering' : 'discarding';
            $revision = $this->api->putData('candidates', $key, $candidate, (int) $record['revision']);
            try {
                if ($button === 'register') {
                    $this->api->acceptSearchData($reference, $candidate['text'], $this->provenance($candidate) + ['approved_by' => $actor['id']], $actor);
                    $candidate['state'] = 'registered';
                    $this->api->setFileState($reference, 'registered', '');
                } else {
                    $candidate['state'] = 'discarded';
                    $candidate['text'] = '';
                    $candidate['locations'] = [];
                    $this->api->setFileState($reference, 'unregistered', 'データ未登録');
                }
                $candidate['decided_by'] = $actor['id'];
                $candidate['decided_at'] = gmdate('c');
            } catch (Throwable $exception) {
                $candidate['state'] = 'review';
                $this->api->putData('candidates', $key, $candidate, $revision);
                throw $exception;
            }
            $this->api->putData('candidates', $key, $candidate, $revision);
            return ['title' => $this->tr('review.title'), 'blocks' => [['type' => 'text', 'text' => $this->tr('state.' . $candidate['state'])]], 'buttons' => []];
        }
        $issues = array_map(fn(string $issue): array => ['issue' => preg_match('/^[a-z][a-z0-9_]+$/D', $issue) ? $this->tr('issue.' . $issue) : $issue], $candidate['issues']);
        $buttons = [];
        if ($candidate['state'] === 'review') {
            $buttons = [
                ['id' => 'register', 'label' => $this->tr('button.register'), 'kind' => 'primary', 'disabled' => trim($candidate['text']) === ''],
                ['id' => 'discard', 'label' => $this->tr('button.discard'), 'kind' => 'danger'],
            ];
        }
        return ['title' => $this->tr('review.title'), 'description' => $candidate['filename'], 'version' => (string) $record['revision'], 'payload' => ['key' => $key], 'blocks' => [
            ['type' => 'details', 'items' => [['label' => $this->tr('label.filename'), 'value' => $candidate['filename'], 'href' => $current['download_url'] ?? null], ['label' => $this->tr('label.state'), 'value' => $this->tr('state.' . $candidate['state'])], ['label' => $this->tr('label.version'), 'value' => $reference['source_version']]]],
            ['type' => 'table', 'columns' => [['key' => 'issue', 'label' => $this->tr('label.issues')]], 'rows' => $issues],
            ['type' => 'text', 'text' => $candidate['text'] !== '' ? $candidate['text'] : $this->tr($candidate['state'] === 'review' ? 'review.empty' : 'state.' . $candidate['state'])],
        ], 'buttons' => $buttons];
    }

    public function settingsAction(string $mode, array $payload, array $actor): array
    {
        $this->admin($actor);
        $feedback = null;
        if ($mode === 'invoke') {
            $button = (string) ($payload['button'] ?? '');
            if ($button === 'save') {
                $this->saveSettings(is_array($payload['values'] ?? null) ? $payload['values'] : [], (string) ($payload['version'] ?? ''));
                $feedback = ['tone' => 'success', 'message' => $this->tr('settings.saved')];
            } elseif ($button === 'test') {
                $start = $this->settingsRecord();
                $this->assertSettingsVersion($start, (string) ($payload['version'] ?? ''));
                $configuration = $this->configuration($start['value']);
                $verificationRevision = (int) ($this->api->getData('settings', 'verified_connection')['revision'] ?? 0);
                $file = ['filename' => 'openconcept-connection-test.pdf', 'mime_type' => 'application/pdf', 'data_url' => 'data:application/pdf;base64,' . base64_encode(self::probePdf()), 'detail' => 'high'];
                $failure = null;
                $connectionSucceeded = false;
                try {
                    $result = $this->client($configuration)->extract([], $file, 1);
                    $connectionSucceeded = true;
                    if (trim($result['text']) === '' || in_array('empty_extraction', $result['issues'], true)) {
                        throw new RuntimeException('empty_extraction');
                    }
                    if ($result['issues'] !== []) {
                        throw new RuntimeException(in_array('incomplete_page_coverage', $result['issues'], true) ? 'incomplete_page_coverage' : 'incomplete_extraction');
                    }
                    if (!str_contains(preg_replace('/\s+/', '', strtoupper($result['text'])), 'OPENCONCEPT')) {
                        throw new RuntimeException('ocr_capability_failed');
                    }
                } catch (Throwable $exception) {
                    $failure = $exception;
                }
                // A failed revision/permission check must propagate; it is not a failed OCR result.
                $this->saveVerification($start, $configuration, $verificationRevision, $failure === null);
                $feedback = $failure === null
                    ? ['tone' => 'success', 'message' => $this->tr('settings.test_success')]
                    : ['tone' => $connectionSucceeded ? 'warning' : 'error',
                        'title' => $this->tr($connectionSucceeded ? 'settings.test_connected_unverified' : 'settings.test_failed_title'),
                        'message' => $this->testFailureMessage($failure, $configuration)];
            } elseif ($button === 'scan') {
                $this->api->enqueue('scan', [], 'manual-scan:' . bin2hex(random_bytes(8)));
                $feedback = ['tone' => 'info', 'message' => $this->tr('settings.queued')];
            } else {
                throw new InvalidArgumentException('Unknown settings action.');
            }
        }
        $settingsRecord = $this->settingsRecord();
        $settings = $settingsRecord['value'];
        $fields = [
            ['type' => 'checkbox', 'name' => 'automatic', 'label' => $this->tr('settings.automatic'), 'value' => (bool) $settings['automatic']],
            ['type' => 'select', 'name' => 'connection', 'label' => $this->tr('settings.connection'), 'value' => $settings['connection'], 'options' => [['value' => 'dedicated', 'label' => $this->tr('settings.dedicated')], ['value' => 'shared', 'label' => $this->tr('settings.shared')]]],
            ['type' => 'select', 'name' => 'provider_id', 'label' => $this->tr('settings.provider'), 'value' => $settings['provider_id'], 'options' => [['value' => 'openai', 'label' => 'OpenAI'], ['value' => 'compatible', 'label' => $this->tr('settings.compatible')]]],
            ['type' => 'input', 'name' => 'base_url', 'label' => $this->tr('settings.url'), 'value' => $settings['base_url']],
            ['type' => 'select', 'name' => 'endpoint_mode', 'label' => $this->tr('settings.endpoint'), 'value' => $settings['endpoint_mode'], 'options' => [['value' => 'responses', 'label' => 'Responses'], ['value' => 'chat_completions', 'label' => 'Chat Completions']]],
            ['type' => 'select', 'name' => 'structured_output_mode', 'label' => $this->tr('settings.structured'), 'value' => $settings['structured_output_mode'], 'options' => [['value' => 'json_schema', 'label' => 'JSON Schema'], ['value' => 'json_object', 'label' => 'JSON Object']]],
            ['type' => 'select', 'name' => 'auth_mode', 'label' => $this->tr('settings.auth'), 'value' => $settings['auth_mode'], 'options' => [['value' => 'bearer', 'label' => 'Bearer'], ['value' => 'none', 'label' => $this->tr('settings.no_auth')]]],
            ['type' => 'input', 'name' => 'api_key', 'label' => $this->tr('settings.key'), 'input_type' => 'password', 'value' => '', 'autocomplete' => 'new-password'],
            ['type' => 'checkbox', 'name' => 'clear_api_key', 'label' => $this->tr('settings.clear_key'), 'value' => false],
            ['type' => 'input', 'name' => 'text_model', 'label' => $this->tr('settings.model'), 'value' => $settings['text_model']],
        ];
        foreach (['timeout', 'max_file_mb', 'max_pages', 'daily_requests'] as $key) {
            $fields[] = ['type' => 'input', 'input_type' => 'number', 'name' => $key, 'label' => $this->tr('settings.' . $key), 'value' => $settings[$key]];
        }
        $cursor = $this->listCursor($payload['cursor'] ?? null);
        $page = $this->api->listData('candidates', $cursor['after'], 10);
        $rows = [];
        foreach ($page['items'] as $record) {
            $candidate = $record['value'];
            $rows[] = ['id' => $record['key'], 'filename' => $candidate['filename'], 'state' => $this->tr('state.' . $candidate['state'])];
        }
        $trail = $cursor['trail'];
        $previousAfter = array_pop($trail);
        $pagination = [
            'previous_cursor' => $cursor['after'] === null ? null : base64_encode(json_encode(['after' => $previousAfter, 'trail' => $trail], JSON_THROW_ON_ERROR)),
            'next_cursor' => ($page['next_cursor'] ?? null) === null ? null : base64_encode(json_encode(['after' => $page['next_cursor'], 'trail' => [...$cursor['trail'], $cursor['after']]], JSON_THROW_ON_ERROR)),
        ];
        $configuration = $this->configuration($settings);
        return ['title' => $this->tr('plugin.name'), 'version' => (string) $settingsRecord['revision'], 'description' => $this->tr('settings.description'), 'blocks' => array_merge([
            ['type' => 'text', 'text' => $this->tr('settings.disclosure')],
            ['type' => 'details', 'items' => [['label' => $this->tr('settings.verified'), 'value' => $this->tr($this->ready($configuration) ? 'settings.ready' : 'settings.not_verified')], ['label' => $this->tr('settings.key'), 'value' => $this->tr($configuration['api_key'] !== '' ? 'settings.key_set' : 'settings.key_empty')]]],
        ], $fields, [
            ['type' => 'table', 'columns' => [['key' => 'filename', 'label' => $this->tr('label.filename')], ['key' => 'state', 'label' => $this->tr('label.state')]], 'rows' => $rows, 'pagination' => $pagination],
        ]), 'buttons' => [
            ['id' => 'save', 'label' => $this->tr('button.save'), 'kind' => 'primary'],
            ['id' => 'test', 'label' => $this->tr('button.test'), 'validate' => false, 'busy_label' => $this->tr('settings.test_busy_label'),
                'busy_message' => $this->tr('settings.test_busy_message', ['seconds' => $configuration['timeout']])],
            ['id' => 'scan', 'label' => $this->tr('button.scan')],
        ]] + ($feedback === null ? [] : ['feedback' => $feedback]);
    }

    private function testFailureMessage(Throwable $failure, array $configuration): string
    {
        $code = $failure->getMessage();
        $status = 0;
        if ($failure instanceof AiProviderRequestException || $failure instanceof OutboundHttpException) {
            // Never render provider messages, URLs, submitted content or arbitrary exception details.
            $code = $failure->failureCode();
            $status = (int) ($failure->details()['http_status'] ?? 0);
        }
        if ($code === 'ai_provider_http_error') {
            $code = match ($status) {
                401, 403 => 'provider_authentication_failed', 429 => 'provider_rate_limited',
                400, 415, 422 => 'provider_file_input_rejected', 404 => 'provider_model_or_endpoint',
                500, 502, 503, 504 => 'provider_unavailable', default => 'provider_request_failed',
            };
        }
        $key = match ($code) {
            'ai_provider_credentials_missing' => 'settings.failure_credentials_missing',
            'provider_authentication_failed' => 'issue.provider_authentication_failed',
            'provider_rate_limited' => 'issue.provider_rate_limited',
            'provider_file_input_rejected' => 'issue.provider_file_input_rejected',
            'provider_model_or_endpoint' => 'settings.failure_model_or_endpoint',
            'provider_unavailable' => 'settings.failure_provider_unavailable',
            'outbound_timeout' => 'settings.failure_timeout',
            'outbound_tls_failed' => 'settings.failure_tls',
            'outbound_dns_failed', 'outbound_connection_refused', 'outbound_unreachable' => 'settings.failure_connection',
            'outbound_http_not_allowed', 'outbound_private_network_not_allowed', 'outbound_url_invalid' => 'settings.failure_endpoint_policy',
            'outbound_client_unavailable' => 'settings.failure_http_client',
            'ai_provider_invalid_response', 'ai_provider_invalid_structured_output', 'invalid_extraction_response' => 'issue.invalid_extraction_response',
            'ai_provider_empty_output', 'empty_extraction' => 'issue.empty_extraction',
            'ai_provider_incomplete_response', 'incomplete_extraction', 'outbound_response_too_large' => 'issue.incomplete_extraction',
            'incomplete_page_coverage' => 'issue.incomplete_page_coverage',
            'ocr_capability_failed' => 'settings.failure_ocr',
            default => $failure instanceof JsonException ? 'issue.invalid_extraction_response' : 'settings.test_failed',
        };
        return $this->tr($key, $key === 'settings.failure_timeout' ? ['seconds' => $configuration['timeout']] : []);
    }

    private function listCursor(mixed $encoded): array
    {
        if ($encoded === null || $encoded === '') { return ['after' => null, 'trail' => []]; }
        if (!is_string($encoded) || strlen($encoded) > 12000) { throw new InvalidArgumentException('Invalid list cursor.'); }
        $decoded = base64_decode($encoded, true);
        $cursor = is_string($decoded) ? json_decode($decoded, true, 16) : null;
        if (!is_array($cursor) || !is_array($cursor['trail'] ?? null) || count($cursor['trail']) > 100) { throw new InvalidArgumentException('Invalid list cursor.'); }
        foreach ([$cursor['after'] ?? null, ...$cursor['trail']] as $key) {
            if ($key !== null && (!is_string($key) || preg_match('/^[a-f0-9]{64}$/D', $key) !== 1)) { throw new InvalidArgumentException('Invalid list cursor.'); }
        }
        return ['after' => $cursor['after'] ?? null, 'trail' => $cursor['trail']];
    }

    private function assertSettingsVersion(array $record, string $version): void
    {
        if ($version !== (string) $record['revision']) { throw new RuntimeException($this->tr('settings.changed'), 409); }
    }

    private function saveVerification(array $start, array $configuration, int $verificationRevision, bool $verified): void
    {
        $this->api->transaction(function () use ($start, $configuration, $verificationRevision, $verified): void {
            $current = $this->settingsRecord();
            $this->assertSettingsVersion($current, (string) $start['revision']);
            if (!hash_equals($this->fingerprint($configuration), $this->fingerprint($this->configuration($current['value'])))) {
                throw new RuntimeException($this->tr('settings.changed'), 409);
            }
            $this->api->putData('settings', 'verified_connection', ['value' => $verified ? $this->fingerprint($configuration) : ''], $verificationRevision);
        });
    }

    private function saveSettings(array $input, string $version): void
    {
        $created = null;
        $retired = null;
        try {
            $this->api->transaction(function () use ($input, $version, &$created, &$retired): void {
                $record = $this->settingsRecord();
                $this->assertSettingsVersion($record, $version);
                $this->saveSettingsTransaction($input, $record, $created, $retired);
            });
        } catch (Throwable $exception) {
            if ($created !== null) { $this->retireSecretIfUnreferenced($created); }
            throw $exception;
        }
        // Only retire a previous immutable secret after its replacement pointer has committed.
        if ($retired !== null) { $this->retireSecretIfUnreferenced($retired); }
    }

    private function retireSecretIfUnreferenced(string $reference): void
    {
        try {
            // A commit may succeed even if its acknowledgement fails. Preserve credentials whenever
            // the pointer still references them, or a fresh pointer read cannot establish otherwise.
            $current = $this->settingsRecord();
            if (($current['value']['secret_ref'] ?? null) !== $reference) { $this->api->setSecret($reference, null); }
        } catch (Throwable) { /* An encrypted orphan is preferable to deleting an active credential. */ }
    }

    private function saveSettingsTransaction(array $input, array $record, ?string &$created, ?string &$retired): void
    {
        $settings = $record['value'];
        $previousReference = (string) ($settings['secret_ref'] ?? '');
        $previousDestination = (string) ($settings['credential_destination'] ?? '');
        foreach (['connection' => ['dedicated', 'shared'], 'provider_id' => ['openai', 'compatible'], 'endpoint_mode' => ['responses', 'chat_completions'], 'structured_output_mode' => ['json_schema', 'json_object'], 'auth_mode' => ['bearer', 'none']] as $key => $allowed) {
            if (isset($input[$key])) {
                if (!in_array($input[$key], $allowed, true)) {
                    throw new InvalidArgumentException('Invalid connection setting.');
                }
                $settings[$key] = $input[$key];
            }
        }
        foreach (['base_url', 'text_model'] as $key) {
            if (isset($input[$key])) {
                $value = trim((string) $input[$key]);
                if ($value === '' || strlen($value) > 500 || preg_match('/[\x00-\x20]/', $value)) {
                    throw new InvalidArgumentException('Invalid connection value.');
                }
                $settings[$key] = rtrim($value, '/');
            }
        }
        $url = parse_url($settings['base_url']);
        if (!is_array($url) || ($url['scheme'] ?? '') !== 'https' || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])) {
            throw new InvalidArgumentException('A valid HTTPS provider URL is required.');
        }
        if ($settings['provider_id'] === 'openai' && $settings['connection'] === 'dedicated'
            && ($settings['base_url'] !== 'https://api.openai.com/v1' || $settings['endpoint_mode'] !== 'responses' || $settings['auth_mode'] !== 'bearer')) {
            throw new InvalidArgumentException('OpenAI requires its official Responses API endpoint.');
        }
        foreach (['timeout' => [15, 60], 'max_file_mb' => [1, 10], 'max_pages' => [1, 200], 'daily_requests' => [1, 10000]] as $key => [$minimum, $maximum]) {
            if (isset($input[$key])) {
                $value = filter_var($input[$key], FILTER_VALIDATE_INT);
                if ($value === false || $value < $minimum || $value > $maximum) {
                    throw new InvalidArgumentException('Invalid processing limit: ' . $key);
                }
                $settings[$key] = $value;
            }
        }
        $settings['automatic'] = filter_var($input['automatic'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $clear = filter_var($input['clear_api_key'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $key = trim((string) ($input['api_key'] ?? ''));
        $destination = $this->credentialDestination($settings);
        if (!$clear && $key === '' && !hash_equals($previousDestination, $destination) && $previousReference !== ''
            && (string) ($this->api->getSecret($previousReference) ?? '') !== '') {
            throw new RuntimeException($this->tr('settings.destination_changed'), 422);
        }
        if (!$clear && $key !== '') {
            if (strlen($key) > 4096 || preg_match('/[\x00-\x20\x7f]/', $key)) {
                throw new InvalidArgumentException('Invalid API credential.');
            }
        }
        if (!$clear && $key === '' && $previousReference === 'api_key') { $key = (string) ($this->api->getSecret('api_key') ?? ''); }
        if ($clear) {
            $settings['secret_ref'] = null;
        } elseif ($key !== '') {
            $created = 'key-' . bin2hex(random_bytes(16));
            $this->api->setSecret($created, $key);
            $settings['secret_ref'] = $created;
        } elseif ($previousReference === 'api_key') {
            $settings['secret_ref'] = null;
        }
        $settings['credential_destination'] = $destination;
        $this->api->putData('configuration', 'current', $settings, (int) $record['revision']);
        if ($previousReference !== '' && $previousReference !== ($settings['secret_ref'] ?? '')) { $retired = $previousReference; }
    }

    private static function probePdf(): string
    {
        // Raster-only evidence: a text-only file parser must not pass the OCR capability probe.
        $glyphs = ['O' => ['01110','10001','10001','10001','10001','10001','01110'], 'P' => ['11110','10001','10001','11110','10000','10000','10000'], 'E' => ['11111','10000','10000','11110','10000','10000','11111'], 'N' => ['10001','11001','10101','10011','10001','10001','10001'], 'C' => ['01111','10000','10000','10000','10000','10000','01111'], 'T' => ['11111','00100','00100','00100','00100','00100','00100']];
        $word = 'OPENCONCEPT';
        $width = strlen($word) * 6 + 4;
        $pixels = str_repeat('ff', $width * 2);
        for ($row = 0; $row < 7; $row++) {
            $pixels .= 'ffff';
            foreach (str_split($word) as $letter) {
                foreach (str_split($glyphs[$letter][$row] . '0') as $pixel) {
                    $pixels .= $pixel === '1' ? '00' : 'ff';
                }
            }
            $pixels .= 'ffff';
        }
        $pixels .= str_repeat('ff', $width * 2) . '>';
        $stream = 'q 260 0 0 44 20 60 cm /Im1 Do Q';
        $objects = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>', '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 150] /Resources << /XObject << /Im1 4 0 R >> >> /Contents 5 0 R >>', '<< /Type /XObject /Subtype /Image /Width ' . $width . ' /Height 11 /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /ASCIIHexDecode /Length ' . strlen($pixels) . " >>\nstream\n" . $pixels . "\nendstream", "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream"];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf('%010d 00000 n ', $offset) . "\n";
        }
        return $pdf . "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
    }
}
