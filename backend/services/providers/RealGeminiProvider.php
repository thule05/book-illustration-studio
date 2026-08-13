<?php

declare(strict_types=1);

final class RealGeminiProvider implements GeminiProvider
{
    /** @var string */
    private $apiKey;

    /** @var string */
    private $textModel;

    /** @var string */
    private $imageModel;

    /** @var string */
    private $storageRoot;

    public function __construct(string $apiKey, string $textModel, string $imageModel, string $storageRoot)
    {
        $this->apiKey = $apiKey;
        $this->textModel = $textModel;
        $this->imageModel = $imageModel;
        $this->storageRoot = $storageRoot;
    }

    private function notImplemented(string $method): void
    {
        throw new RuntimeException(
            "RealGeminiProvider::{$method} is not implemented yet. Set GEMINI_PROVIDER=mock for development."
        );
    }

    public function uploadBook(int $projectId, string $bookText): array
    {
        $this->notImplemented(__FUNCTION__);
        return [];
    }

    public function generateStyle(array $context, ?string $userStyle): array
    {
        $this->notImplemented(__FUNCTION__);
        return [];
    }

    public function generateCharacters(array $context): array
    {
        $this->notImplemented(__FUNCTION__);
        return [];
    }

    public function generatePortrait(array $context, array $character): array
    {
        $this->notImplemented(__FUNCTION__);
        return [];
    }

    public function generateChapter(array $context): array
    {
        $this->notImplemented(__FUNCTION__);
        return [];
    }

    public function generateIllustration(array $context, array $chapter, array $portraitPaths): array
    {
        $this->notImplemented(__FUNCTION__);
        return [];
    }
}
