<?php

declare(strict_types=1);

/**
 * Dedicated, runtime-local connection settings for translation providers.
 *
 * Existing installations inherit the shared AI connection until an
 * administrator saves this dedicated profile. After that point its encrypted
 * credential and connection settings are independent.
 */
final class TranslationProviderSettings
{
    private AiProviderSettings $dedicated;

    public function __construct(
        string $storageRoot,
        private readonly AiProviderSettings $legacySharedSettings
    ) {
        $this->dedicated = new AiProviderSettings($storageRoot, 'translation-provider');
    }

    /** @return array<string, mixed> */
    public function resolved(): array
    {
        return $this->dedicated->hasStoredSettings()
            ? $this->dedicated->resolved()
            : $this->legacySharedSettings->resolved();
    }

    /** @return array{settings: array<string, mixed>, secret: array<string, mixed>, inherited: bool} */
    public function publicState(): array
    {
        $inherited = !$this->dedicated->hasStoredSettings();
        $state = $inherited
            ? $this->legacySharedSettings->publicState()
            : $this->dedicated->publicState();
        $settings = is_array($state['settings'] ?? null) ? $state['settings'] : [];
        return [
            'settings' => $this->translationFields($settings),
            'secret' => is_array($state['secret'] ?? null) ? $state['secret'] : [],
            'inherited' => $inherited,
        ];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function save(array $input): array
    {
        $current = $this->resolved();
        $model = trim((string) ($input['translation_model'] ?? $current['translation_model'] ?? ''));
        $payload = [
            ...$current,
            ...$input,
            // AiProviderSettings validates a common connection record. These
            // unused model slots remain local to the translation profile.
            'text_model' => $model,
            'transcription_model' => $model,
            'translation_model' => $model,
            'vision_model' => $model,
        ];
        if (!$this->dedicated->hasStoredSettings()
            && trim((string) ($input['api_key'] ?? '')) === ''
            && !(bool) ($input['clear_api_key'] ?? false)) {
            $payload['api_key'] = (string) ($current['api_key'] ?? '');
        }
        $state = $this->dedicated->save($payload);
        return [
            'settings' => $this->translationFields((array) ($state['settings'] ?? [])),
            'secret' => is_array($state['secret'] ?? null) ? $state['secret'] : [],
            'inherited' => false,
        ];
    }

    /** @param array<string, mixed> $settings @return array<string, mixed> */
    private function translationFields(array $settings): array
    {
        return [
            'provider_id' => (string) ($settings['provider_id'] ?? 'openai'),
            'display_name' => (string) ($settings['display_name'] ?? 'OpenAI'),
            'base_url' => (string) ($settings['base_url'] ?? 'https://api.openai.com/v1'),
            'endpoint_mode' => (string) ($settings['endpoint_mode'] ?? 'responses'),
            'structured_output_mode' => (string) ($settings['structured_output_mode'] ?? 'json_schema'),
            'auth_mode' => (string) ($settings['auth_mode'] ?? 'bearer'),
            'translation_model' => (string) ($settings['translation_model'] ?? ''),
            'timeout' => (int) ($settings['timeout'] ?? 120),
            'verify_tls' => (bool) ($settings['verify_tls'] ?? true),
            'allow_private_network' => (bool) ($settings['allow_private_network'] ?? false),
            'allow_http' => (bool) ($settings['allow_http'] ?? false),
        ];
    }
}
