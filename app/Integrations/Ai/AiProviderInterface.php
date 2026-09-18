<?php

namespace App\Integrations\Ai;

/**
 * AI sağlayıcı arayüzü (faz 60e): tek metin tamamlama çağrısı. Uygulama kodu sağlayıcıya doğrudan bağlanmaz;
 * adaptör Gateway üzerinden çağırır, sonuç token sayılarıyla döner (maliyet ve kayıt için).
 */
interface AiProviderInterface
{
    /** @return array{text: string, input_tokens: int, output_tokens: int, model: string, provider: string} */
    public function complete(string $system, string $user, ?string $model = null, int $maxTokens = 4000): array;

    public function available(): bool;

    public function defaultModel(): string;
}
