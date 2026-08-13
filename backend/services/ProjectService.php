<?php

declare(strict_types=1);

final class ProjectService
{
    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function findOwnedProject(int $projectId, int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM projects WHERE id = :id AND user_id = :user_id LIMIT 1'
        );
        $stmt->execute([
            'id' => $projectId,
            'user_id' => $userId,
        ]);

        $project = $stmt->fetch();
        return $project ?: null;
    }

    public function listForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, title, status, created_at, updated_at
             FROM projects
             WHERE user_id = :user_id
             ORDER BY created_at DESC'
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    public function getDetail(int $projectId, int $userId): ?array
    {
        $project = $this->findOwnedProject($projectId, $userId);
        if ($project === null) {
            return null;
        }

        $steps = $this->fetchSteps($projectId);
        $style = $this->fetchLatestStyle($projectId);
        $characters = $this->fetchCharacters($projectId);
        $chapters = $this->fetchChapters($projectId);

        return [
            'project' => $this->formatProject($project),
            'steps' => $steps,
            'style' => $style,
            'characters' => $characters,
            'chapters' => $chapters,
        ];
    }

    private function fetchSteps(int $projectId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT step, step_order, state, attempt_count, started_at, completed_at, error_message
             FROM project_steps
             WHERE project_id = :project_id
             ORDER BY step_order ASC'
        );
        $stmt->execute(['project_id' => $projectId]);

        return $stmt->fetchAll();
    }

    private function fetchLatestStyle(int $projectId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT style_text, source, created_at
             FROM project_styles
             WHERE project_id = :project_id
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute(['project_id' => $projectId]);

        $style = $stmt->fetch();
        return $style ?: null;
    }

    private function fetchCharacters(int $projectId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, order_index, name, prompt, portrait_path, portrait_status, portrait_error
             FROM characters
             WHERE project_id = :project_id
             ORDER BY order_index ASC'
        );
        $stmt->execute(['project_id' => $projectId]);

        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            if (!empty($row['portrait_path'])) {
                $row['portrait_url'] = $this->mediaUrl($row['portrait_path']);
            }
        }

        return $rows;
    }

    private function fetchChapters(int $projectId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, order_index, name, prompt, illustration_path, illustration_status, illustration_error
             FROM chapters
             WHERE project_id = :project_id
             ORDER BY order_index ASC'
        );
        $stmt->execute(['project_id' => $projectId]);

        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            if (!empty($row['illustration_path'])) {
                $row['illustration_url'] = $this->mediaUrl($row['illustration_path']);
            }
        }

        return $rows;
    }

    private function formatProject(array $project): array
    {
        return [
            'id' => (int) $project['id'],
            'title' => $project['title'],
            'status' => $project['status'],
            'book_file_path' => $project['book_file_path'],
            'created_at' => $project['created_at'],
            'updated_at' => $project['updated_at'],
        ];
    }

    private function mediaUrl(string $relativePath): string
    {
        return '/backend/api/media.php?path=' . rawurlencode($relativePath);
    }
}
