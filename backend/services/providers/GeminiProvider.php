<?php

declare(strict_types=1);

interface GeminiProvider
{
    public function uploadBook(int $projectId, string $bookText): array;

    /** @return array{style_text: string, text_interaction_id: string} */
    public function generateStyle(array $context, ?string $userStyle): array;

    /** @return array{characters: list<array{name: string, prompt: string, is_adult: bool}>, text_interaction_id: string} */
    public function generateCharacters(array $context): array;

    /** @return array{relative_path: string, image_interaction_id: string} */
    public function generatePortrait(array $context, array $character): array;

    /** @return array{name: string, prompt: string, text_interaction_id: string} */
    public function generateChapter(array $context): array;

    /** @return array{relative_path: string, image_interaction_id: string} */
    public function generateIllustration(array $context, array $chapter, array $portraitPaths): array;
}
