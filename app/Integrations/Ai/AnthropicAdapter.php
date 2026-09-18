<?php

namespace App\Integrations\Ai;

use App\Integrations\Gateway;
use App\Integrations\SecretStore;
use RuntimeException;

/**
 * Anthropic Messages API adaptörü (faz 60e) — Gateway `ai` sağlayıcısı üzerinden; anahtar yalnız başlıkta.
 * Model ve sürüm config'ten (AI_MODEL); yanıt metni + kullanım (input/output token) döner.
 */
class AnthropicAdapter implements AiProviderInterface
{
    public function __construct(private readonly Gateway $gateway, private readonly SecretStore $secrets) {}

    public function available(): bool
    {
        return $this->secrets->enabled('ai') && $this->secrets->missing('ai') === [];
    }

    public function defaultModel(): string
    {
        return (string) $this->secrets->config('ai', 'model', 'claude-sonnet-5');
    }

    public function complete(string $system, string $user, ?string $model = null, int $maxTokens = 4000): array
    {
        if (! $this->available()) {
            throw new RuntimeException('AI sağlayıcısı bağlı değil (AI_ENABLED + AI_API_KEY).');
        }

        $model = $model !== null && $model !== '' ? $model : $this->defaultModel();
        $response = $this->gateway->request('ai', 'POST', '/v1/messages', [
            'headers' => ['x-api-key' => $this->secrets->get('ai', 'api_key'), 'anthropic-version' => (string) $this->secrets->config('ai', 'version', '2023-06-01')],
            'json' => ['model' => $model, 'max_tokens' => $maxTokens, 'system' => $system, 'messages' => [['role' => 'user', 'content' => $user]]],
            'timeout' => max(30, (int) $this->secrets->config('ai', 'timeout', 120)),
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('AI sağlayıcısı HTTP '.$response->status().': '.mb_substr((string) $response->json('error.message', ''), 0, 200));
        }

        $text = '';

        foreach ((array) $response->json('content', []) as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text') {
                $text .= (string) ($block['text'] ?? '');
            }
        }

        return [
            'text' => $text,
            'input_tokens' => (int) $response->json('usage.input_tokens', 0),
            'output_tokens' => (int) $response->json('usage.output_tokens', 0),
            'model' => (string) ($response->json('model') ?: $model),
            'provider' => 'ai',
        ];
    }
}
