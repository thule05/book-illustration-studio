<?php

declare(strict_types=1);

final class ProviderFactory
{
    public static function create(): GeminiProvider
    {
        $provider = strtolower((string) env('GEMINI_PROVIDER', 'mock'));
        $storageRoot = dirname(__DIR__, 2) . '/storage';

        if ($provider === 'gemini') {
            $apiKey = (string) env('GEMINI_API_KEY', '');
            if ($apiKey === '') {
                throw new RuntimeException('GEMINI_API_KEY is required when GEMINI_PROVIDER=gemini.');
            }

            return new RealGeminiProvider(
                $apiKey,
                (string) env('GEMINI_TEXT_MODEL', ''),
                (string) env('GEMINI_IMAGE_MODEL', ''),
                $storageRoot
            );
        }

        return new MockGeminiProvider($storageRoot);
    }
}
