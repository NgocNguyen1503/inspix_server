<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class UnsplashKeyManager
{
    private const EXHAUSTED_TTL = 3600; // 1 hour
    private const CACHE_PREFIX = 'unsplash_key_exhausted:';

    private function allKeys(): array
    {
        $raw = (string) config('services.unsplash.access_key', '');

        return collect(explode(',', $raw))
            ->map(fn($k) => trim($k))
            ->filter()
            ->values()
            ->all();
    }

    public function getAvailableKey(): ?string
    {
        foreach ($this->allKeys() as $key) {
            if (!Cache::has(self::CACHE_PREFIX . $key)) {
                return $key;
            }
        }

        return null;
    }

    // mark out of quota key
    public function markExhausted(string $key): void
    {
        Cache::put(self::CACHE_PREFIX . $key, true, self::EXHAUSTED_TTL);
    }

    public function hasAvailableKey(): bool
    {
        return $this->getAvailableKey() !== null;
    }
}