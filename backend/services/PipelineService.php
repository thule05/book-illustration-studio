<?php

declare(strict_types=1);

final class PipelineService
{
    private const STEPS = [
        1 => 'style',
        2 => 'characters',
        3 => 'portraits',
        4 => 'chapters',
        5 => 'illustrations',
    ];

    /** @var PDO */
    private $pdo;

    /** @var GeminiProvider */
    private $provider;

    /** @var ProjectService */
    private $projectService;

    public function __construct(PDO $pdo, GeminiProvider $provider, ProjectService $projectService)
    {
        $this->pdo = $pdo;
        $this->provider = $provider;
        $this->projectService = $projectService;
    }

    public function runStep(int $projectId, int $userId, ?string $userStyle = null): array
    {
        $project = $this->projectService->findOwnedProject($projectId, $userId);
        if ($project === null) {
            json_error(404, 'Project not found.');
        }

        $targetStep = $this->resolveRunnableStep($projectId);
        if ($targetStep === null) {
            $detail = $this->projectService->getDetail($projectId, $userId);
            return [
                'message' => 'All steps are already completed.',
                'detail' => $detail,
            ];
        }

        $this->recoverStaleStep($projectId, $targetStep);

        $lock = $this->acquireStepLock($projectId, $targetStep);
        if ($lock['status'] === 'already_running') {
            json_error(409, 'Step is already running.', [
                'step' => $targetStep,
            ]);
        }
        if ($lock['status'] === 'already_completed') {
            $detail = $this->projectService->getDetail($projectId, $userId);
            return [
                'message' => 'Step already completed.',
                'detail' => $detail,
            ];
        }

        try {
            $this->executeStep($projectId, $targetStep, $userStyle);
            $this->markStepCompleted($projectId, $targetStep);
            $this->refreshProjectStatus($projectId);
        } catch (Throwable $e) {
            $this->markStepFailed($projectId, $targetStep, $e->getMessage());
            json_error(500, 'Step failed.', [
                'step' => $targetStep,
                'message' => $e->getMessage(),
                'detail' => $this->projectService->getDetail($projectId, $userId),
            ]);
        }

        return [
            'message' => 'Step completed.',
            'step' => $targetStep,
            'detail' => $this->projectService->getDetail($projectId, $userId),
        ];
    }

    private function resolveRunnableStep(int $projectId): ?string
    {
        $steps = $this->fetchStepsIndexed($projectId);

        foreach (self::STEPS as $order => $stepName) {
            $state = $steps[$stepName]['state'] ?? 'pending';
            if ($state === 'completed') {
                continue;
            }

            if ($order > 1) {
                $previous = self::STEPS[$order - 1];
                if (($steps[$previous]['state'] ?? '') !== 'completed') {
                    json_error(409, 'Previous step is not completed.', [
                        'required_step' => $previous,
                        'requested_step' => $stepName,
                    ]);
                }
            }

            if (in_array($state, ['pending', 'failed', 'running'], true)) {
                return $stepName;
            }
        }

        return null;
    }

    private function recoverStaleStep(int $projectId, string $step): void
    {
        $staleSeconds = (int) env('STEP_STALE_SECONDS', '300');
        if ($staleSeconds <= 0) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE project_steps
             SET state = \'failed\',
                 error_message = \'Step timed out. Please retry.\'
             WHERE project_id = :project_id
               AND step = :step
               AND state = \'running\'
               AND started_at IS NOT NULL
               AND started_at < (NOW() - INTERVAL :seconds SECOND)'
        );
        $stmt->bindValue('project_id', $projectId, PDO::PARAM_INT);
        $stmt->bindValue('step', $step);
        $stmt->bindValue('seconds', $staleSeconds, PDO::PARAM_INT);
        $stmt->execute();
    }

    /** @return array{status: string} */
    private function acquireStepLock(int $projectId, string $step): array
    {
        $this->pdo->beginTransaction();

        try {
            $select = $this->pdo->prepare(
                'SELECT state FROM project_steps
                 WHERE project_id = :project_id AND step = :step
                 FOR UPDATE'
            );
            $select->execute([
                'project_id' => $projectId,
                'step' => $step,
            ]);
            $row = $select->fetch();

            if (!$row) {
                $this->pdo->rollBack();
                throw new RuntimeException('Project step not found.');
            }

            if ($row['state'] === 'running') {
                $this->pdo->commit();
                return ['status' => 'already_running'];
            }

            if ($row['state'] === 'completed') {
                $this->pdo->commit();
                return ['status' => 'already_completed'];
            }

            $update = $this->pdo->prepare(
                'UPDATE project_steps
                 SET state = \'running\',
                     started_at = NOW(),
                     completed_at = NULL,
                     error_message = NULL,
                     attempt_count = attempt_count + 1
                 WHERE project_id = :project_id
                   AND step = :step
                   AND state IN (\'pending\', \'failed\')'
            );
            $update->execute([
                'project_id' => $projectId,
                'step' => $step,
            ]);

            if ($update->rowCount() === 0) {
                $this->pdo->commit();
                return ['status' => 'already_running'];
            }

            $this->pdo->commit();
            return ['status' => 'acquired'];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function executeStep(int $projectId, string $step, ?string $userStyle): void
    {
        $context = $this->buildContext($projectId);

        match ($step) {
            'style' => $this->runStyle($projectId, $context, $userStyle),
            'characters' => $this->runCharacters($projectId, $context),
            'portraits' => $this->runPortraits($projectId, $context),
            'chapters' => $this->runChapters($projectId, $context),
            'illustrations' => $this->runIllustrations($projectId, $context),
            default => throw new RuntimeException('Unknown step.'),
        };
    }

    private function runStyle(int $projectId, array $context, ?string $userStyle): void
    {
        $upload = $this->provider->uploadBook($projectId, (string) $context['book_text']);

        $result = $this->provider->generateStyle($context, $userStyle);

        $stmt = $this->pdo->prepare(
            'INSERT INTO project_styles (project_id, style_text, source)
             VALUES (:project_id, :style_text, :source)'
        );
        $stmt->execute([
            'project_id' => $projectId,
            'style_text' => $result['style_text'],
            'source' => $userStyle !== null && trim($userStyle) !== '' ? 'user' : 'generated',
        ]);

        $this->updateProjectChain($projectId, [
            'gemini_book_file_uri' => $upload['book_file_uri'] ?? null,
            'gemini_text_interaction_id' => $result['text_interaction_id'],
            'status' => 'in_progress',
        ]);
    }

    private function runCharacters(int $projectId, array $context): void
    {
        $result = $this->provider->generateCharacters($context);
        $characters = $this->normalizeCharacters($result['characters']);

        $delete = $this->pdo->prepare('DELETE FROM characters WHERE project_id = :project_id');
        $delete->execute(['project_id' => $projectId]);

        $insert = $this->pdo->prepare(
            'INSERT INTO characters (project_id, order_index, name, prompt, portrait_status)
             VALUES (:project_id, :order_index, :name, :prompt, \'pending\')'
        );

        foreach ($characters as $index => $character) {
            $insert->execute([
                'project_id' => $projectId,
                'order_index' => $index + 1,
                'name' => $character['name'],
                'prompt' => $character['prompt'],
            ]);
        }

        $this->updateProjectChain($projectId, [
            'gemini_text_interaction_id' => $result['text_interaction_id'],
        ]);
    }

    private function runPortraits(int $projectId, array $context): void
    {
        $characters = $this->fetchCharacters($projectId);
        if ($characters === []) {
            throw new RuntimeException('No characters found for portrait generation.');
        }

        $imageInteractionId = null;

        foreach ($characters as $character) {
            if ($character['portrait_status'] === 'completed' && !empty($character['portrait_path'])) {
                continue;
            }

            $this->setCharacterPortraitStatus((int) $character['id'], 'generating', null);

            try {
                $result = $this->provider->generatePortrait($context, $character);
                $relativePath = $result['relative_path'];
                $imageInteractionId = $result['image_interaction_id'];

                $update = $this->pdo->prepare(
                    'UPDATE characters
                     SET portrait_path = :portrait_path,
                         portrait_status = \'completed\',
                         portrait_error = NULL
                     WHERE id = :id'
                );
                $update->execute([
                    'portrait_path' => $relativePath,
                    'id' => $character['id'],
                ]);
            } catch (Throwable $e) {
                $this->setCharacterPortraitStatus((int) $character['id'], 'failed', $e->getMessage());
                throw $e;
            }
        }

        if ($imageInteractionId !== null) {
            $this->updateProjectChain($projectId, [
                'gemini_image_interaction_id' => $imageInteractionId,
            ]);
        }
    }

    private function runChapters(int $projectId, array $context): void
    {
        $result = $this->provider->generateChapter($context);
        $chapter = $this->normalizeChapter($result);

        $delete = $this->pdo->prepare('DELETE FROM chapters WHERE project_id = :project_id');
        $delete->execute(['project_id' => $projectId]);

        $insert = $this->pdo->prepare(
            'INSERT INTO chapters (project_id, order_index, name, prompt, illustration_status)
             VALUES (:project_id, 1, :name, :prompt, \'pending\')'
        );
        $insert->execute([
            'project_id' => $projectId,
            'name' => $chapter['name'],
            'prompt' => $chapter['prompt'],
        ]);

        $this->updateProjectChain($projectId, [
            'gemini_text_interaction_id' => $result['text_interaction_id'],
        ]);
    }

    private function runIllustrations(int $projectId, array $context): void
    {
        $chapters = $this->fetchChapters($projectId);
        if ($chapters === []) {
            throw new RuntimeException('No chapter found for illustration generation.');
        }

        $chapter = $chapters[0];
        $portraitPaths = array_values(array_filter(array_column(
            $this->fetchCharacters($projectId),
            'portrait_path'
        )));

        $this->setChapterIllustrationStatus((int) $chapter['id'], 'generating', null);

        $result = $this->provider->generateIllustration($context, $chapter, $portraitPaths);

        $update = $this->pdo->prepare(
            'UPDATE chapters
             SET illustration_path = :illustration_path,
                 illustration_status = \'completed\',
                 illustration_error = NULL
             WHERE id = :id'
        );
        $update->execute([
            'illustration_path' => $result['relative_path'],
            'id' => $chapter['id'],
        ]);

        $this->updateProjectChain($projectId, [
            'gemini_image_interaction_id' => $result['image_interaction_id'],
        ]);
    }

    /** @param list<array{name: string, prompt: string, is_adult?: bool}> $characters */
    private function normalizeCharacters(array $characters): array
    {
        $normalized = [];

        foreach ($characters as $character) {
            if (!($character['is_adult'] ?? false)) {
                throw new RuntimeException('Only adult characters are allowed.');
            }

            $normalized[] = [
                'name' => trim((string) $character['name']),
                'prompt' => trim((string) $character['prompt']),
            ];

            if (count($normalized) >= 2) {
                break;
            }
        }

        if ($normalized === []) {
            throw new RuntimeException('At least one adult character is required.');
        }

        return $normalized;
    }

    /** @param array{name?: string, prompt?: string} $result */
    private function normalizeChapter(array $result): array
    {
        return [
            'name' => trim((string) ($result['name'] ?? 'Chapter 1')),
            'prompt' => trim((string) ($result['prompt'] ?? 'Illustrate a key story moment.')),
        ];
    }

    private function markStepCompleted(int $projectId, string $step): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE project_steps
             SET state = \'completed\',
                 completed_at = NOW(),
                 error_message = NULL
             WHERE project_id = :project_id AND step = :step'
        );
        $stmt->execute([
            'project_id' => $projectId,
            'step' => $step,
        ]);
    }

    private function markStepFailed(int $projectId, string $step, string $message): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE project_steps
             SET state = \'failed\',
                 error_message = :error_message
             WHERE project_id = :project_id AND step = :step'
        );
        $stmt->execute([
            'project_id' => $projectId,
            'step' => $step,
            'error_message' => $message,
        ]);
    }

    private function refreshProjectStatus(int $projectId): void
    {
        $steps = $this->fetchStepsIndexed($projectId);
        $allCompleted = true;

        foreach (self::STEPS as $stepName) {
            if (($steps[$stepName]['state'] ?? '') !== 'completed') {
                $allCompleted = false;
                break;
            }
        }

        $status = $allCompleted ? 'done' : 'in_progress';
        $stmt = $this->pdo->prepare('UPDATE projects SET status = :status WHERE id = :id');
        $stmt->execute([
            'status' => $status,
            'id' => $projectId,
        ]);
    }

    private function buildContext(int $projectId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM projects WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $projectId]);
        $project = $stmt->fetch();

        if (!$project) {
            throw new RuntimeException('Project not found.');
        }

        $style = $this->projectService->getDetail($projectId, (int) $project['user_id']);
        $styleText = $style['style']['style_text'] ?? null;

        return [
            'project_id' => $projectId,
            'book_text' => $project['book_text'],
            'book_file_uri' => $project['gemini_book_file_uri'],
            'text_interaction_id' => $project['gemini_text_interaction_id'],
            'image_interaction_id' => $project['gemini_image_interaction_id'],
            'style_text' => $styleText,
            'characters' => $this->fetchCharacters($projectId),
            'chapters' => $this->fetchChapters($projectId),
        ];
    }

    private function updateProjectChain(int $projectId, array $fields): void
    {
        $allowed = [
            'gemini_book_file_uri',
            'gemini_text_interaction_id',
            'gemini_image_interaction_id',
            'status',
        ];

        $sets = [];
        $params = ['id' => $projectId];

        foreach ($fields as $key => $value) {
            if (!in_array($key, $allowed, true) || $value === null) {
                continue;
            }
            $sets[] = "{$key} = :{$key}";
            $params[$key] = $value;
        }

        if ($sets === []) {
            return;
        }

        $sql = 'UPDATE projects SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    private function fetchStepsIndexed(int $projectId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT step, state, step_order FROM project_steps WHERE project_id = :project_id'
        );
        $stmt->execute(['project_id' => $projectId]);

        $indexed = [];
        foreach ($stmt->fetchAll() as $row) {
            $indexed[$row['step']] = $row;
        }

        return $indexed;
    }

    private function fetchCharacters(int $projectId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM characters WHERE project_id = :project_id ORDER BY order_index ASC'
        );
        $stmt->execute(['project_id' => $projectId]);

        return $stmt->fetchAll();
    }

    private function fetchChapters(int $projectId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM chapters WHERE project_id = :project_id ORDER BY order_index ASC'
        );
        $stmt->execute(['project_id' => $projectId]);

        return $stmt->fetchAll();
    }

    private function setCharacterPortraitStatus(int $characterId, string $status, ?string $error): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE characters
             SET portrait_status = :status, portrait_error = :error
             WHERE id = :id'
        );
        $stmt->execute([
            'status' => $status,
            'error' => $error,
            'id' => $characterId,
        ]);
    }

    private function setChapterIllustrationStatus(int $chapterId, string $status, ?string $error): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE chapters
             SET illustration_status = :status, illustration_error = :error
             WHERE id = :id'
        );
        $stmt->execute([
            'status' => $status,
            'error' => $error,
            'id' => $chapterId,
        ]);
    }
}
