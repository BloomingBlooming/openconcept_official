<?php

declare(strict_types=1);

/**
 * Dedicated, runtime-local connection settings for translation providers.
 *
 * New profiles initially use the common connection values and credential.
 * Saved profiles keep their own connection values and explicitly select
 * either the current common credential or a retained individual credential.
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
        if ($this->dedicated->hasStoredSettings()) {
            return $this->dedicated->resolved();
        }
        $shared = $this->legacySharedSettings->resolved();
        $shared['use_shared_api_key'] = true;
        $shared['api_key_source'] = $shared['auth_mode'] === 'none' ? 'none' : 'shared';
        return $shared;
    }

    /** @return array{settings: array<string, mixed>, secret: array<string, mixed>, individual_secret: array<string, mixed>, inherited: bool} */
    public function publicState(): array
    {
        $inherited = !$this->dedicated->hasStoredSettings();
        $state = $this->dedicated->publicState();
        $settings = is_array($state['settings'] ?? null) ? $state['settings'] : [];
        return [
            'settings' => $this->translationFields($settings),
            'secret' => is_array($state['secret'] ?? null) ? $state['secret'] : [],
            'individual_secret' => is_array($state['individual_secret'] ?? null) ? $state['individual_secret'] : [],
            'inherited' => $inherited,
        ];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function save(array $input): array
    {
        $current = $this->resolved();
        $model = trim((string) ($input['translation_model'] ?? $current['translation_model'] ?? ''));
        // A resolved common credential is runtime data, never an implicit
        // individual-key save. Only a key explicitly entered here is stored.
        unset($current['api_key'], $current['api_key_source']);
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
        $this->dedicated->save($payload);
        return $this->publicState();
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
            'use_shared_api_key' => ($settings['use_shared_api_key'] ?? false) === true,
            'translation_model' => (string) ($settings['translation_model'] ?? ''),
            'timeout' => (int) ($settings['timeout'] ?? 120),
            'verify_tls' => (bool) ($settings['verify_tls'] ?? true),
            'allow_private_network' => (bool) ($settings['allow_private_network'] ?? false),
            'allow_http' => (bool) ($settings['allow_http'] ?? false),
        ];
    }
}
