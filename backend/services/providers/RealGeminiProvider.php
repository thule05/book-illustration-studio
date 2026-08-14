<?php

declare(strict_types=1);

final class RealGeminiProvider implements GeminiProvider
{
    private const INTERACTIONS_URL = 'https://generativelanguage.googleapis.com/v1beta/interactions';
    private const FILE_UPLOAD_URL = 'https://generativelanguage.googleapis.com/upload/v1beta/files';

    private const BOOK_SYSTEM_INSTRUCTION =
        'You are an expert book illustrator. Follow the supplied book text closely. '
        . 'Keep every output family-friendly and suitable for a polished storybook.';

    private const IMAGE_SYSTEM_INSTRUCTION =
        'Create one complete book illustration with no panels, borders, title, caption, '
        . 'description, watermark, or other written text. Use uplifting colors and keep '
        . 'the result family-friendly. Preserve the requested art style and character identity.';

    /** @var string */
    private $apiKey;

    /** @var string */
    private $textModel;

    /** @var string */
    private $imageModel;

    /** @var string */
    private $storageRoot;

    /** @var callable|null */
    private $transport;

    /**
     * The optional transport is used by contract tests so they never consume Gemini quota.
     * It receives method, URL, headers, and body, and returns status, headers, and body.
     */
    public function __construct(
        string $apiKey,
        string $textModel,
        string $imageModel,
        string $storageRoot,
        ?callable $transport = null
    ) {
        $this->apiKey = trim($apiKey);
        $this->textModel = trim($textModel);
        $this->imageModel = trim($imageModel);
        $this->storageRoot = rtrim($storageRoot, '/\\');
        $this->transport = $transport;

        if ($this->apiKey === '') {
            throw new InvalidArgumentException('A Gemini API key is required for the real provider.');
        }
        if ($this->textModel === '' || $this->imageModel === '') {
            throw new InvalidArgumentException('Gemini text and image model IDs are required.');
        }
        if ($this->storageRoot === '') {
            throw new InvalidArgumentException('A storage root is required.');
        }
    }

    public function uploadBook(int $projectId, string $bookText): array
    {
        if ($projectId <= 0 || trim($bookText) === '') {
            throw new InvalidArgumentException('A project and non-empty book text are required.');
        }

        $byteLength = strlen($bookText);
        $metadata = $this->encodeJson([
            'file' => [
                'display_name' => "project-{$projectId}-book.txt",
            ],
        ]);

        $start = $this->sendRequest('POST', self::FILE_UPLOAD_URL, [
            'x-goog-api-key: ' . $this->apiKey,
            'X-Goog-Upload-Protocol: resumable',
            'X-Goog-Upload-Command: start',
            'X-Goog-Upload-Header-Content-Length: ' . $byteLength,
            'X-Goog-Upload-Header-Content-Type: text/plain',
            'Content-Type: application/json',
        ], $metadata);
        $this->assertSuccess($start);

        $uploadUrl = (string) ($start['headers']['x-goog-upload-url'] ?? '');
        if ($uploadUrl === '' || !$this->isGoogleUploadUrl($uploadUrl)) {
            throw new RuntimeException('Gemini File API did not return a valid upload URL.');
        }

        $uploaded = $this->sendRequest('POST', $uploadUrl, [
            'Content-Length: ' . $byteLength,
            'Content-Type: text/plain',
            'X-Goog-Upload-Offset: 0',
            'X-Goog-Upload-Command: upload, finalize',
        ], $bookText);
        $uploadedJson = $this->decodeSuccessfulJson($uploaded);
        $fileUri = trim((string) ($uploadedJson['file']['uri'] ?? ''));

        if ($fileUri === '') {
            throw new RuntimeException('Gemini File API response did not include file.uri.');
        }

        return ['book_file_uri' => $fileUri];
    }

    public function generateStyle(array $context, ?string $userStyle): array
    {
        $bookFileUri = $this->requireContextString($context, 'book_file_uri');

        $bookInteraction = $this->createInteraction([
            'model' => $this->textModel,
            'system_instruction' => self::BOOK_SYSTEM_INSTRUCTION,
            'input' => [
                [
                    'type' => 'text',
                    'text' => 'Read this book once and keep it as context for the illustration pipeline. '
                        . 'Do not summarize it yet.',
                ],
                [
                    'type' => 'document',
                    'uri' => $bookFileUri,
                    'mime_type' => 'text/plain',
                ],
            ],
            'response_format' => $this->plainTextFormat(),
            'store' => true,
        ]);

        $style = trim((string) ($userStyle ?? ''));
        $prompt = $style === ''
            ? 'Propose one concise, visually specific art style for illustrating this book. '
                . 'Match its setting and emotional tone, and include one distinctive creative twist. '
                . 'Return only the style description.'
            : "Use this exact user-selected art direction for every later image: {$style}\n"
                . 'Acknowledge it concisely without replacing or rewriting the requested style.';

        $styleInteraction = $this->createInteraction([
            'model' => $this->textModel,
            'input' => $prompt,
            'previous_interaction_id' => $this->interactionId($bookInteraction),
            'response_format' => $this->plainTextFormat(),
            'store' => true,
        ]);

        if ($style === '') {
            $style = trim($this->extractText($styleInteraction));
        }
        if ($style === '') {
            throw new RuntimeException('Gemini returned an empty art style.');
        }

        return [
            'style_text' => $style,
            'text_interaction_id' => $this->interactionId($styleInteraction),
        ];
    }

    public function generateCharacters(array $context): array
    {
        $interaction = $this->createInteraction([
            'model' => $this->textModel,
            'input' => 'Identify the main adult characters who should stay visually consistent in the '
                . 'illustrations. Return at most 2 adults and never include a child. For each adult, '
                . 'write a detailed standalone portrait prompt of at least 50 words covering age, face, '
                . 'hair, clothing, build, expression, distinctive features, and the established art style.',
            'previous_interaction_id' => $this->requireContextString($context, 'text_interaction_id'),
            'response_format' => [
                'type' => 'text',
                'mime_type' => 'application/json',
                'schema' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 2,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'prompt' => ['type' => 'string'],
                            'is_adult' => ['type' => 'boolean'],
                        ],
                        'required' => ['name', 'prompt', 'is_adult'],
                    ],
                ],
            ],
            'store' => true,
        ]);

        $characters = $this->decodeStructuredList($this->extractText($interaction), 'characters');
        if (count($characters) > 2) {
            throw new RuntimeException('Gemini returned more than the 2-character cap.');
        }

        $normalized = [];
        foreach ($characters as $character) {
            $name = trim((string) ($character['name'] ?? ''));
            $prompt = trim((string) ($character['prompt'] ?? ''));
            $isAdult = ($character['is_adult'] ?? null) === true;

            if ($name === '' || $prompt === '' || !$isAdult) {
                throw new RuntimeException('Gemini returned an invalid or non-adult character.');
            }

            $normalized[] = [
                'name' => $name,
                'prompt' => $prompt,
                'is_adult' => true,
            ];
        }

        return [
            'characters' => $normalized,
            'text_interaction_id' => $this->interactionId($interaction),
        ];
    }

    public function generatePortrait(array $context, array $character): array
    {
        $projectId = (int) ($context['project_id'] ?? 0);
        $order = (int) ($character['order_index'] ?? 0);
        $name = trim((string) ($character['name'] ?? ''));
        $prompt = trim((string) ($character['prompt'] ?? ''));

        if ($projectId <= 0 || $order <= 0 || $name === '' || $prompt === '') {
            throw new InvalidArgumentException('A valid project and character are required for a portrait.');
        }

        $previousId = trim((string) ($context['image_interaction_id'] ?? ''));
        if ($previousId === '') {
            $imageContext = $this->createInteraction([
                'model' => $this->imageModel,
                'system_instruction' => self::IMAGE_SYSTEM_INSTRUCTION,
                'input' => 'Prepare to illustrate this book using the following art style: '
                    . $this->requireContextString($context, 'style_text')
                    . '. Keep the same appearance for every recurring character. '
                    . 'For every generated image, follow these rules: ' . self::IMAGE_SYSTEM_INSTRUCTION . ' '
                    . 'Acknowledge these constraints in one short sentence; do not generate an image yet.',
                'response_format' => $this->plainTextFormat(),
                'store' => true,
            ]);
            $previousId = $this->interactionId($imageContext);
        }

        $interaction = $this->createInteraction([
            'model' => $this->imageModel,
            'input' => "Generate a full-body portrait of {$name}. {$prompt} "
                . 'Use a simple unobtrusive background. Create exactly one image and no written text.',
            'previous_interaction_id' => $previousId,
            'response_format' => $this->imageFormat('9:16'),
            'store' => true,
        ]);

        $image = $this->extractImage($interaction);
        $relativePath = $this->saveImage(
            $projectId,
            'portraits',
            "character-{$order}",
            $image['mime_type'],
            $image['data']
        );

        return [
            'relative_path' => $relativePath,
            'image_interaction_id' => $this->interactionId($interaction),
        ];
    }

    public function generateChapter(array $context): array
    {
        $characterNames = array_values(array_filter(array_map(
            static fn (array $character): string => trim((string) ($character['name'] ?? '')),
            $context['characters'] ?? []
        )));
        $cast = $characterNames === [] ? 'the established adult characters' : implode(', ', $characterNames);

        $interaction = $this->createInteraction([
            'model' => $this->textModel,
            'input' => "Choose exactly one key scene from the book for a chapter illustration featuring {$cast}. "
                . 'The prompt must name the relevant established characters, describe their action, setting, '
                . 'composition, lighting, mood, and established art style. Do not invent a second scene.',
            'previous_interaction_id' => $this->requireContextString($context, 'text_interaction_id'),
            'response_format' => [
                'type' => 'text',
                'mime_type' => 'application/json',
                'schema' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 1,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'prompt' => ['type' => 'string'],
                        ],
                        'required' => ['name', 'prompt'],
                    ],
                ],
            ],
            'store' => true,
        ]);

        $chapters = $this->decodeStructuredList($this->extractText($interaction), 'chapters');
        if (count($chapters) !== 1) {
            throw new RuntimeException('Gemini must return exactly one chapter illustration prompt.');
        }

        $name = trim((string) ($chapters[0]['name'] ?? ''));
        $prompt = trim((string) ($chapters[0]['prompt'] ?? ''));
        if ($name === '' || $prompt === '') {
            throw new RuntimeException('Gemini returned an invalid chapter prompt.');
        }

        return [
            'name' => $name,
            'prompt' => $prompt,
            'text_interaction_id' => $this->interactionId($interaction),
        ];
    }

    public function generateIllustration(array $context, array $chapter, array $portraitPaths): array
    {
        $projectId = (int) ($context['project_id'] ?? 0);
        $order = (int) ($chapter['order_index'] ?? 1);
        $name = trim((string) ($chapter['name'] ?? ''));
        $prompt = trim((string) ($chapter['prompt'] ?? ''));

        if ($projectId <= 0 || $name === '' || $prompt === '' || $portraitPaths === []) {
            throw new InvalidArgumentException('A chapter and completed portraits are required for an illustration.');
        }

        $bridge = $this->createInteraction([
            'model' => $this->imageModel,
            'input' => 'The portraits generated earlier define the canonical appearance of the recurring '
                . 'characters. Reuse those exact identities in the next scene; pose, expression, camera angle, '
                . 'and position may change. Acknowledge briefly and do not generate an image yet.',
            'previous_interaction_id' => $this->requireContextString($context, 'image_interaction_id'),
            'response_format' => $this->plainTextFormat(),
            'store' => true,
        ]);

        $interaction = $this->createInteraction([
            'model' => $this->imageModel,
            'input' => "Generate the single chapter illustration '{$name}'. {$prompt} "
                . 'Reuse the established character appearances. Create exactly one image and no written text.',
            'previous_interaction_id' => $this->interactionId($bridge),
            'response_format' => $this->imageFormat('16:9'),
            'store' => true,
        ]);

        $image = $this->extractImage($interaction);
        $relativePath = $this->saveImage(
            $projectId,
            'illustrations',
            'chapter-' . max(1, $order),
            $image['mime_type'],
            $image['data']
        );

        return [
            'relative_path' => $relativePath,
            'image_interaction_id' => $this->interactionId($interaction),
        ];
    }

    private function createInteraction(array $payload): array
    {
        $response = $this->sendRequest('POST', self::INTERACTIONS_URL, [
            'x-goog-api-key: ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ], $this->encodeJson($payload));
        $interaction = $this->decodeSuccessfulJson($response);

        $status = (string) ($interaction['status'] ?? '');
        if ($status !== 'completed') {
            throw new RuntimeException(
                'Gemini interaction did not complete synchronously (status: '
                . ($status !== '' ? $status : 'unknown') . ').'
            );
        }
        $this->interactionId($interaction);

        return $interaction;
    }

    /** @return array{type: string, mime_type: string} */
    private function plainTextFormat(): array
    {
        return [
            'type' => 'text',
            'mime_type' => 'text/plain',
        ];
    }

    /** @return array{type: string, mime_type: string, aspect_ratio: string, image_size: string} */
    private function imageFormat(string $aspectRatio): array
    {
        $imageSize = strtoupper((string) $this->environment('GEMINI_IMAGE_SIZE', '1K'));
        if (!in_array($imageSize, ['512', '1K', '2K', '4K'], true)) {
            throw new RuntimeException('GEMINI_IMAGE_SIZE must be 512, 1K, 2K, or 4K.');
        }

        return [
            'type' => 'image',
            'mime_type' => 'image/jpeg',
            'aspect_ratio' => $aspectRatio,
            'image_size' => $imageSize,
        ];
    }

    private function extractText(array $interaction): string
    {
        $steps = array_reverse($interaction['steps'] ?? []);
        foreach ($steps as $step) {
            $contentItems = array_reverse($step['content'] ?? []);
            foreach ($contentItems as $content) {
                if (($content['type'] ?? '') === 'text' && isset($content['text'])) {
                    return (string) $content['text'];
                }
            }
        }

        throw new RuntimeException('Gemini interaction did not return text output.');
    }

    /** @return array{data: string, mime_type: string} */
    private function extractImage(array $interaction): array
    {
        $steps = array_reverse($interaction['steps'] ?? []);
        foreach ($steps as $step) {
            $contentItems = array_reverse($step['content'] ?? []);
            foreach ($contentItems as $content) {
                if (($content['type'] ?? '') !== 'image' || empty($content['data'])) {
                    continue;
                }

                $mimeType = strtolower((string) ($content['mime_type'] ?? 'image/jpeg'));
                $data = (string) $content['data'];
                if (preg_match('#^data:[^;]+;base64,(.+)$#s', $data, $matches) === 1) {
                    $data = $matches[1];
                }

                return [
                    'data' => $data,
                    'mime_type' => $mimeType,
                ];
            }
        }

        throw new RuntimeException('Gemini interaction did not return image output.');
    }

    private function saveImage(
        int $projectId,
        string $category,
        string $baseName,
        string $mimeType,
        string $base64Data
    ): string {
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];
        $extension = $extensions[$mimeType] ?? null;
        if ($extension === null) {
            throw new RuntimeException('Gemini returned an unsupported image MIME type.');
        }

        $bytes = base64_decode($base64Data, true);
        if ($bytes === false || $bytes === '') {
            throw new RuntimeException('Gemini returned invalid image data.');
        }

        $relativePath = "images/{$category}/{$projectId}/{$baseName}.{$extension}";
        $absolutePath = $this->storageRoot . '/' . $relativePath;
        $directory = dirname($absolutePath);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create the generated-image directory.');
        }
        if (file_put_contents($absolutePath, $bytes, LOCK_EX) === false) {
            throw new RuntimeException('Could not save the generated image.');
        }

        return $relativePath;
    }

    /** @return list<array<string, mixed>> */
    private function decodeStructuredList(string $text, string $label): array
    {
        $trimmed = trim($text);
        if (str_starts_with($trimmed, '```')) {
            $trimmed = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $trimmed) ?? $trimmed;
        }

        try {
            $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Gemini returned invalid {$label} JSON.", 0, $e);
        }

        if (
            !is_array($decoded)
            || $decoded === []
            || array_keys($decoded) !== range(0, count($decoded) - 1)
        ) {
            throw new RuntimeException("Gemini returned an invalid {$label} list.");
        }

        foreach ($decoded as $item) {
            if (!is_array($item)) {
                throw new RuntimeException("Gemini returned an invalid {$label} item.");
            }
        }

        return $decoded;
    }

    private function requireContextString(array $context, string $key): string
    {
        $value = trim((string) ($context[$key] ?? ''));
        if ($value === '') {
            throw new RuntimeException("Gemini context is missing {$key}.");
        }

        return $value;
    }

    private function interactionId(array $interaction): string
    {
        $id = trim((string) ($interaction['id'] ?? ''));
        if ($id === '') {
            throw new RuntimeException('Gemini interaction response did not include an ID.');
        }

        return $id;
    }

    private function encodeJson(array $payload): string
    {
        try {
            return json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        } catch (JsonException $e) {
            throw new RuntimeException('Could not encode the Gemini request.', 0, $e);
        }
    }

    private function decodeSuccessfulJson(array $response): array
    {
        $this->assertSuccess($response);

        try {
            $decoded = json_decode((string) $response['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Gemini returned invalid JSON.', 0, $e);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('Gemini returned an invalid JSON object.');
        }

        return $decoded;
    }

    private function assertSuccess(array $response): void
    {
        $status = (int) ($response['status'] ?? 0);
        if ($status >= 200 && $status < 300) {
            return;
        }

        $message = '';
        $decoded = json_decode((string) ($response['body'] ?? ''), true);
        if (is_array($decoded)) {
            $message = trim((string) ($decoded['error']['message'] ?? ''));
        }
        if ($message === '') {
            $message = 'Unexpected response from Gemini.';
        }
        $message = substr(preg_replace('/\s+/', ' ', $message) ?? $message, 0, 500);

        throw new RuntimeException("Gemini API request failed (HTTP {$status}): {$message}");
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    private function sendRequest(string $method, string $url, array $headers, string $body): array
    {
        if ($this->transport !== null) {
            $response = ($this->transport)($method, $url, $headers, $body);
            if (!is_array($response)) {
                throw new RuntimeException('Gemini test transport returned an invalid response.');
            }

            return [
                'status' => (int) ($response['status'] ?? 0),
                'headers' => $this->normalizeHeaders($response['headers'] ?? []),
                'body' => (string) ($response['body'] ?? ''),
            ];
        }

        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP cURL extension is required for Gemini API calls.');
        }

        $responseHeaders = [];
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_CONNECTTIMEOUT => max(1, (int) $this->environment('GEMINI_CONNECT_TIMEOUT_SECONDS', '15')),
            CURLOPT_TIMEOUT => max(1, (int) $this->environment('GEMINI_REQUEST_TIMEOUT_SECONDS', '180')),
            CURLOPT_USERAGENT => 'BookIllustrationStudio/1.0',
            CURLOPT_HEADERFUNCTION => static function ($curlHandle, string $headerLine) use (&$responseHeaders): int {
                $length = strlen($headerLine);
                $separator = strpos($headerLine, ':');
                if ($separator !== false) {
                    $name = strtolower(trim(substr($headerLine, 0, $separator)));
                    $responseHeaders[$name] = trim(substr($headerLine, $separator + 1));
                }
                return $length;
            },
        ]);

        $responseBody = curl_exec($curl);
        if ($responseBody === false) {
            $error = curl_error($curl);
            curl_close($curl);
            throw new RuntimeException('Gemini network request failed: ' . $error);
        }

        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        return [
            'status' => $status,
            'headers' => $responseHeaders,
            'body' => (string) $responseBody,
        ];
    }

    /** @return array<string, string> */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            if (is_int($name) && is_string($value) && str_contains($value, ':')) {
                [$name, $value] = explode(':', $value, 2);
            }
            if (is_string($name)) {
                $normalized[strtolower(trim($name))] = trim((string) $value);
            }
        }

        return $normalized;
    }

    private function isGoogleUploadUrl(string $url): bool
    {
        $parts = parse_url($url);
        return ($parts['scheme'] ?? '') === 'https'
            && strtolower((string) ($parts['host'] ?? '')) === 'generativelanguage.googleapis.com';
    }

    private function environment(string $key, string $default): string
    {
        if (function_exists('env')) {
            return (string) env($key, $default);
        }

        $value = getenv($key);
        return $value === false ? $default : (string) $value;
    }
}
