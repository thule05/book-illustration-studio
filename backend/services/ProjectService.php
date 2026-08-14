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
            'SELECT p.*,
                    (
                        SELECT COUNT(*)
                        FROM project_steps ps
                        WHERE ps.project_id = p.id
                          AND ps.state = \'completed\'
                    ) AS completed_steps
             FROM projects p
             WHERE p.user_id = :user_id
             ORDER BY p.created_at DESC'
        );
        $stmt->execute(['user_id' => $userId]);

        $projects = [];
        foreach ($stmt->fetchAll() as $row) {
            $completedSteps = (int) $row['completed_steps'];
            $derivedStatus = self::statusFromCompletedSteps($completedSteps);

            // Repair stale cached statuses left by interrupted or older code.
            if ($row['status'] !== $derivedStatus) {
                $this->updateStatus((int) $row['id'], $derivedStatus);
                $row['status'] = $derivedStatus;
            }

            $projects[] = $this->formatProject($row, $completedSteps);
        }

        return $projects;
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
        $completedSteps = $this->countCompletedSteps($steps);
        $derivedStatus = self::statusFromCompletedSteps($completedSteps);

        if ($project['status'] !== $derivedStatus) {
            $this->updateStatus($projectId, $derivedStatus);
            $project['status'] = $derivedStatus;
        }

        return [
            'project' => $this->formatProject(
                $project,
                $completedSteps
            ),
            'steps' => $steps,
            'style' => $style,
            'characters' => $characters,
            'chapters' => $chapters,
        ];
    }

    private function fetchSteps(int $projectId): array
    {
        $staleSeconds = max(1, (int) env('STEP_STALE_SECONDS', '420'));
        $stmt = $this->pdo->prepare(
            "SELECT step, step_order, state, attempt_count, started_at, completed_at,
                    error_message, updated_at,
                    CASE
                        WHEN state = 'running'
                         AND updated_at < DATE_SUB(NOW(), INTERVAL {$staleSeconds} SECOND)
                        THEN 1 ELSE 0
                    END AS is_stale
             FROM project_steps
             WHERE project_id = :project_id
             ORDER BY step_order ASC"
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

    public function refreshStatus(int $projectId): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM project_steps
             WHERE project_id = :project_id AND state = \'completed\''
        );
        $stmt->execute(['project_id' => $projectId]);

        $status = self::statusFromCompletedSteps((int) $stmt->fetchColumn());
        $this->updateStatus($projectId, $status);

        return $status;
    }

    private function countCompletedSteps(array $steps): int
    {
        return count(array_filter(
            $steps,
            static fn (array $step): bool => $step['state'] === 'completed'
        ));
    }

    private static function statusFromCompletedSteps(int $completedSteps): string
    {
        if ($completedSteps >= 5) {
            return 'done';
        }

        return $completedSteps === 0 ? 'draft' : 'in_progress';
    }

    private function updateStatus(int $projectId, string $status): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE projects SET status = :status WHERE id = :id'
        );
        $stmt->execute([
            'status' => $status,
            'id' => $projectId,
        ]);
    }

    private function formatProject(array $project, int $completedSteps): array
    {
        return [
            'id' => (int) $project['id'],
            'title' => $project['title'],
            'status' => $project['status'],
            'book_text' => $project['book_text'],
            'book_file_path' => $project['book_file_path'],
            'created_at' => $project['created_at'],
            'updated_at' => $project['updated_at'],
            'completed_steps' => max(0, min(5, $completedSteps)),
        ];
    }

    private function mediaUrl(string $relativePath): string
    {
        return '../backend/api/media.php?path=' . rawurlencode($relativePath);
    }
}
