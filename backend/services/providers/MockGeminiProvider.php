<?php

declare(strict_types=1);

class MockGeminiProvider implements GeminiProvider
{
    private string $storageRoot;

    public function __construct(string $storageRoot)
    {
        $this->storageRoot = rtrim($storageRoot, '/\\');
    }

    public function uploadBook(int $projectId, string $bookText): array
    {
        return [
            'book_file_uri' => "mock://book/{$projectId}",
        ];
    }

    public function generateStyle(array $context, ?string $userStyle): array
    {
        $this->simulateLatency();

        $projectId = (int) $context['project_id'];
        $style = trim((string) ($userStyle ?? ''));

        if ($style === '') {
            $style = 'Warm watercolor storybook illustration with soft edges and gentle lighting.';
        }

        return [
            'style_text' => $style,
            'text_interaction_id' => "mock-text-{$projectId}-style",
        ];
    }

    public function generateCharacters(array $context): array
    {
        $this->simulateLatency();

        $projectId = (int) $context['project_id'];
        $style = (string) ($context['style_text'] ?? 'storybook style');
        $snippet = substr(
            preg_replace(
                '/\s+/',
                ' ',
                (string) ($context['book_text'] ?? '')
            ),
            0,
            80
        );

        return [
            'characters' => [
                [
                    'name' => 'Mira',
                    'prompt' => "Adult lead character for {$snippet}. Render in {$style}.",
                    'is_adult' => true,
                ],
                [
                    'name' => 'Jonah',
                    'prompt' => "Adult companion character for {$snippet}. Render in {$style}.",
                    'is_adult' => true,
                ],
            ],
            'text_interaction_id' => "mock-text-{$projectId}-characters",
        ];
    }

    public function generatePortrait(array $context, array $character): array
    {
        $this->simulateLatency();

        $projectId = (int) $context['project_id'];
        $characterId = (int) $character['id'];
        $order = (int) $character['order_index'];

        $relative = "images/portraits/{$projectId}/character-{$order}.png";

        $this->writePlaceholderImage(
            $this->storageRoot . '/' . $relative
        );

        return [
            'relative_path' => $relative,
            'image_interaction_id' =>
                "mock-image-{$projectId}-portrait-{$characterId}",
        ];
    }

    public function generateChapter(array $context): array
    {
        $this->simulateLatency();

        $projectId = (int) $context['project_id'];

        $names = array_column(
            $context['characters'] ?? [],
            'name'
        );

        $cast = $names !== []
            ? implode(' and ', $names)
            : 'the characters';

        return [
            'name' => 'Riverbank Morning',
            'prompt' =>
                "One key scene featuring {$cast} beside the river at dawn.",
            'text_interaction_id' =>
                "mock-text-{$projectId}-chapters",
        ];
    }

    public function generateIllustration(
        array $context,
        array $chapter,
        array $portraitPaths
    ): array {
        $this->simulateLatency();

        $projectId = (int) $context['project_id'];

        $relative =
            "images/illustrations/{$projectId}/chapter-1.png";

        $this->writePlaceholderImage(
            $this->storageRoot . '/' . $relative
        );

        return [
            'relative_path' => $relative,
            'image_interaction_id' =>
                "mock-image-{$projectId}-illustration",
        ];
    }

    /**
     * Simulate the latency of a real Gemini request.
     * This is only used by the mock provider so the frontend
     * loading/running state can be demonstrated during development.
     */
    private function simulateLatency(): void
    {
        $milliseconds = max(0, (int) env('MOCK_LATENCY_MS', '1500'));
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }

    private function writePlaceholderImage(string $absolutePath): void
    {
        $dir = dirname($absolutePath);

        if (
            !is_dir($dir) &&
            !mkdir($dir, 0775, true) &&
            !is_dir($dir)
        ) {
            throw new RuntimeException(
                'Could not create image directory.'
            );
        }

        $seed = md5($absolutePath);

        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true
        );

        if ($png === false) {
            throw new RuntimeException(
                'Invalid placeholder image data.'
            );
        }

        if (
            file_put_contents($absolutePath, $png) === false
        ) {
            throw new RuntimeException(
                'Could not write placeholder image.'
            );
        }
    }
}
