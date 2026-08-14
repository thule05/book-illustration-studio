<?php

declare(strict_types=1);

final class ProviderFactory
{
    public static function create(): GeminiProvider
    {
        $provider = strtolower(trim((string) env('GEMINI_PROVIDER', 'mock')));
        $storageRoot = trim((string) env('STORAGE_ROOT', ''));
        if ($storageRoot === '') {
            $storageRoot = dirname(__DIR__, 2) . '/storage';
        }
        $storageRoot = rtrim($storageRoot, '/\\');

        if ($provider === 'gemini') {
            $apiKey = (string) env('GEMINI_API_KEY', '');
            if ($apiKey === '') {
                throw new RuntimeException('GEMINI_API_KEY is required when GEMINI_PROVIDER=gemini.');
            }

            $textModel = trim((string) env('GEMINI_TEXT_MODEL', ''));
            $imageModel = trim((string) env('GEMINI_IMAGE_MODEL', ''));

            return new RealGeminiProvider(
                $apiKey,
                $textModel !== '' ? $textModel : 'gemini-3.6-flash',
                $imageModel !== '' ? $imageModel : 'gemini-3.1-flash-image',
                $storageRoot
            );
        }

        if ($provider === 'mock') {
            return new MockGeminiProvider($storageRoot);
        }

        throw new RuntimeException('GEMINI_PROVIDER must be mock or gemini.');
    }
}
