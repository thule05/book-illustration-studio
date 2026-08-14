<?php

declare(strict_types=1);

/**
 * Real provider contract tests with a fake HTTP transport.
 * No request leaves this process and no Gemini quota is consumed.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/backend/utils/env.php';
require_once $root . '/backend/services/providers/GeminiProvider.php';
require_once $root . '/backend/services/providers/MockGeminiProvider.php';
require_once $root . '/backend/services/providers/RealGeminiProvider.php';
require_once $root . '/backend/services/providers/ProviderFactory.php';

$results = [];

function check(bool $condition, string $name, string $reason = ''): void
{
    global $results;
    $results[] = ['name' => $name, 'ok' => $condition, 'reason' => $reason];
    echo ($condition ? 'PASS  ' : 'FAIL  ') . $name . ($condition || $reason === '' ? '' : ": {$reason}") . "\n";
}

function interactionResponse(string $id, array $content): array
{
    return [
        'status' => 200,
        'headers' => [],
        'body' => json_encode([
            'id' => $id,
            'status' => 'completed',
            'steps' => [
                [
                    'type' => 'model_output',
                    'content' => $content,
                ],
            ],
        ], JSON_THROW_ON_ERROR),
    ];
}

function textResponse(string $id, string $text): array
{
    return interactionResponse($id, [
        ['type' => 'text', 'text' => $text],
    ]);
}

function imageResponse(string $id, string $bytes): array
{
    return interactionResponse($id, [
        [
            'type' => 'image',
            'mime_type' => 'image/jpeg',
            'data' => base64_encode($bytes),
        ],
    ]);
}

$storageRoot = rtrim(
    getenv('STORAGE_ROOT') ?: sys_get_temp_dir() . '/bis-real-provider-tests',
    '/\\'
);

$responses = [
    [
        'status' => 200,
        'headers' => [
            'X-Goog-Upload-URL' => 'https://generativelanguage.googleapis.com/upload/test-session',
        ],
        'body' => '',
    ],
    [
        'status' => 200,
        'headers' => [],
        'body' => json_encode(['file' => ['uri' => 'https://files.example/book-42']], JSON_THROW_ON_ERROR),
    ],
    textResponse('text-book', 'Book context stored.'),
    textResponse('text-style', 'Luminous ink-and-watercolor with moss-green shadows.'),
    textResponse('text-characters', json_encode([
        [
            'name' => 'Mara',
            'prompt' => 'An adult river warden in her forties with a weathered face, silver-streaked dark hair, '
                . 'a practical green coat, tall boots, calm posture, observant gray eyes, and a brass compass, '
                . 'painted in luminous ink-and-watercolor with moss-green shadows and warm reflected light.',
            'is_adult' => true,
        ],
        [
            'name' => 'Elias',
            'prompt' => 'An adult cartographer in his thirties with a lean build, curled brown hair, round spectacles, '
                . 'a rust waistcoat, rolled sleeves, an ink-marked hand, a thoughtful smile, and a worn map case, '
                . 'painted in luminous ink-and-watercolor with moss-green shadows and warm reflected light.',
            'is_adult' => true,
        ],
    ], JSON_THROW_ON_ERROR)),
    textResponse('image-context', 'Image constraints stored.'),
    imageResponse('image-portrait-1', "\xFF\xD8\xFFportrait-one"),
    imageResponse('image-portrait-2', "\xFF\xD8\xFFportrait-two"),
    textResponse('text-chapter', json_encode([
        [
            'name' => 'The Lantern Crossing',
            'prompt' => 'Mara and Elias cross the flooded river at dusk beneath one amber lantern.',
        ],
    ], JSON_THROW_ON_ERROR)),
    textResponse('image-bridge', 'Character identities will be preserved.'),
    imageResponse('image-illustration', "\xFF\xD8\xFFchapter-image"),
];

$requests = [];
$transport = static function (string $method, string $url, array $headers, string $body) use (&$responses, &$requests): array {
    $requests[] = [
        'method' => $method,
        'url' => $url,
        'headers' => $headers,
        'body' => $body,
    ];
    if ($responses === []) {
        throw new RuntimeException('The fake transport response queue is empty.');
    }

    return array_shift($responses);
};

$provider = new RealGeminiProvider(
    'test-api-key',
    'gemini-3.6-flash',
    'gemini-3.1-flash-image',
    $storageRoot,
    $transport
);

$bookText = 'The river rose beyond the old stone bridge.';
$upload = $provider->uploadBook(42, $bookText);
$uploadStartBody = json_decode($requests[0]['body'], true);
check(
    ($upload['book_file_uri'] ?? '') === 'https://files.example/book-42'
        && ($uploadStartBody['file']['display_name'] ?? '') === 'project-42-book.txt'
        && $requests[1]['body'] === $bookText,
    'resumable book upload contract'
);
check(
    !str_contains(implode("\n", $requests[1]['headers']), 'test-api-key'),
    'upload session does not forward API key'
);

$context = [
    'project_id' => 42,
    'book_file_uri' => $upload['book_file_uri'],
    'text_interaction_id' => null,
    'image_interaction_id' => null,
    'style_text' => null,
    'characters' => [],
];

$style = $provider->generateStyle($context, null);
$bookPayload = json_decode($requests[2]['body'], true);
$stylePayload = json_decode($requests[3]['body'], true);
check(
    ($bookPayload['input'][1]['uri'] ?? '') === $upload['book_file_uri']
        && ($stylePayload['previous_interaction_id'] ?? '') === 'text-book'
        && ($style['text_interaction_id'] ?? '') === 'text-style',
    'book context and style interaction are chained'
);

$context['style_text'] = $style['style_text'];
$context['text_interaction_id'] = $style['text_interaction_id'];
$characterResult = $provider->generateCharacters($context);
$characterPayload = json_decode($requests[4]['body'], true);
check(
    count($characterResult['characters'] ?? []) === 2
        && ($characterPayload['response_format']['schema']['maxItems'] ?? null) === 2
        && ($characterPayload['previous_interaction_id'] ?? '') === 'text-style',
    'adult character output is structured and capped at 2'
);

$characters = [
    array_merge(['id' => 1, 'order_index' => 1], $characterResult['characters'][0]),
    array_merge(['id' => 2, 'order_index' => 2], $characterResult['characters'][1]),
];
$context['characters'] = $characters;
$context['text_interaction_id'] = $characterResult['text_interaction_id'];

$portraitOne = $provider->generatePortrait($context, $characters[0]);
$imageContextPayload = json_decode($requests[5]['body'], true);
$portraitOnePayload = json_decode($requests[6]['body'], true);
check(
    str_contains((string) ($imageContextPayload['input'] ?? ''), 'no panels')
        && ($portraitOnePayload['previous_interaction_id'] ?? '') === 'image-context'
        && ($portraitOnePayload['response_format']['aspect_ratio'] ?? '') === '9:16'
        && is_file($storageRoot . '/' . $portraitOne['relative_path']),
    'first portrait initializes image chain and saves media'
);

$context['image_interaction_id'] = $portraitOne['image_interaction_id'];
$requestCountBeforeSecondPortrait = count($requests);
$portraitTwo = $provider->generatePortrait($context, $characters[1]);
$portraitTwoPayload = json_decode($requests[7]['body'], true);
check(
    count($requests) === $requestCountBeforeSecondPortrait + 1
        && ($portraitTwoPayload['previous_interaction_id'] ?? '') === 'image-portrait-1'
        && is_file($storageRoot . '/' . $portraitTwo['relative_path']),
    'second portrait reuses the persisted image chain'
);

$chapter = $provider->generateChapter($context);
$chapterPayload = json_decode($requests[8]['body'], true);
check(
    ($chapterPayload['response_format']['schema']['maxItems'] ?? null) === 1
        && ($chapterPayload['previous_interaction_id'] ?? '') === 'text-characters'
        && ($chapter['name'] ?? '') === 'The Lantern Crossing',
    'chapter output is structured and capped at 1'
);

$context['image_interaction_id'] = $portraitTwo['image_interaction_id'];
$illustration = $provider->generateIllustration(
    $context,
    array_merge(['order_index' => 1], $chapter),
    [$portraitOne['relative_path'], $portraitTwo['relative_path']]
);
$bridgePayload = json_decode($requests[9]['body'], true);
$illustrationPayload = json_decode($requests[10]['body'], true);
check(
    ($bridgePayload['previous_interaction_id'] ?? '') === 'image-portrait-2'
        && ($illustrationPayload['previous_interaction_id'] ?? '') === 'image-bridge'
        && ($illustrationPayload['response_format']['aspect_ratio'] ?? '') === '16:9'
        && is_file($storageRoot . '/' . $illustration['relative_path']),
    'chapter illustration reuses portraits and saves media'
);

check(count($requests) === 11 && $responses === [], 'expected Gemini request count without auto-retry');

$errorCalls = 0;
$errorTransport = static function () use (&$errorCalls): array {
    $errorCalls++;
    return [
        'status' => 429,
        'headers' => [],
        'body' => json_encode(['error' => ['message' => 'Image quota is unavailable.']], JSON_THROW_ON_ERROR),
    ];
};
$errorProvider = new RealGeminiProvider(
    'private-test-key',
    'gemini-3.6-flash',
    'gemini-3.1-flash-image',
    $storageRoot,
    $errorTransport
);
$errorMessage = '';
try {
    $errorProvider->generateChapter([
        'text_interaction_id' => 'previous-text',
        'characters' => [['name' => 'Mara']],
    ]);
} catch (RuntimeException $e) {
    $errorMessage = $e->getMessage();
}
check(
    $errorCalls === 1
        && str_contains($errorMessage, 'HTTP 429')
        && str_contains($errorMessage, 'quota')
        && !str_contains($errorMessage, 'private-test-key'),
    'API failure is retryable by user, not automatically retried or leaked'
);

putenv('GEMINI_PROVIDER=gemini');
putenv('GEMINI_API_KEY=factory-test-key');
putenv('GEMINI_TEXT_MODEL');
putenv('GEMINI_IMAGE_MODEL');
$factoryProvider = ProviderFactory::create();
check($factoryProvider instanceof RealGeminiProvider, 'provider factory switches from mock to real');

$failed = array_values(array_filter($results, static fn (array $result): bool => !$result['ok']));
echo "\nSummary: " . (count($results) - count($failed)) . ' passed, ' . count($failed) . " failed\n";
exit($failed === [] ? 0 : 1);
