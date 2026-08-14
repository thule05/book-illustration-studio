<?php

declare(strict_types=1);

/**
 * Simple backend smoke tests. Run: php tests/backend/smoke.php
 */

$root = dirname(__DIR__, 2);
$baseUrl = getenv('SMOKE_BASE_URL') ?: 'http://127.0.0.1:8765';
$cookieFile = sys_get_temp_dir() . '/bis-smoke-cookie.txt';

require_once $root . '/backend/bootstrap.php';

$results = [];

function pass(string $name): void
{
    global $results;
    $results[] = ['name' => $name, 'ok' => true];
    echo "PASS  {$name}\n";
}

function fail(string $name, string $reason): void
{
    global $results;
    $results[] = ['name' => $name, 'ok' => false, 'reason' => $reason];
    echo "FAIL  {$name}: {$reason}\n";
}

function httpRequest(string $method, string $url, ?array $json = null, string $cookieFile = ''): array
{
    $headers = ['Accept: application/json'];
    $body = null;

    if ($json !== null) {
        $headers[] = 'Content-Type: application/json';
        $body = json_encode($json, JSON_UNESCAPED_UNICODE);
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADER => true,
            CURLOPT_COOKIEJAR => $cookieFile,
            CURLOPT_COOKIEFILE => $cookieFile,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new RuntimeException('curl error: ' . curl_error($ch));
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $responseBody = substr($raw, $headerSize);
    } else {
        $opts = [
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'ignore_errors' => true,
            ],
        ];
        if ($body !== null) {
            $opts['http']['content'] = $body;
        }
        $context = stream_context_create($opts);
        $responseBody = file_get_contents($url, false, $context);
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }
    }

    return [
        'status' => $status,
        'json' => json_decode((string) $responseBody, true),
        'raw' => (string) $responseBody,
    ];
}

function resetSmokeData(PDO $pdo): void
{
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (['characters', 'chapters', 'project_styles', 'project_steps', 'projects', 'users'] as $table) {
        $pdo->exec("TRUNCATE TABLE {$table}");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

@unlink($cookieFile);

try {
    $pdo->query('SELECT 1');
    pass('database connection');
} catch (Throwable $e) {
    fail('database connection', $e->getMessage());
    exit(1);
}

resetSmokeData($pdo);

// Identity
$identity = httpRequest('POST', "{$baseUrl}/backend/api/identity.php", [
    'name' => 'Smoke Tester',
    'email' => 'smoke@example.com',
], $cookieFile);

if ($identity['status'] === 200 && isset($identity['json']['user']['id'])) {
    pass('identity POST');
    $userId = (int) $identity['json']['user']['id'];
} else {
    fail('identity POST', 'status=' . $identity['status'] . ' body=' . $identity['raw']);
    exit(1);
}

$currentIdentity = httpRequest('GET', "{$baseUrl}/backend/api/identity.php", null, $cookieFile);
if ($currentIdentity['status'] === 200 && isset($currentIdentity['json']['user']['id'])) {
    pass('identity session restore');
} else {
    fail('identity session restore', 'expected current user, got ' . $currentIdentity['status']);
}

// Create project
$created = httpRequest('POST', "{$baseUrl}/backend/api/projects.php", [
    'title' => 'Smoke Book',
    'book_text' => 'Once upon a time by the riverbank.',
], $cookieFile);

if ($created['status'] === 201 && isset($created['json']['project']['id'])) {
    pass('create project');
    $projectId = (int) $created['json']['project']['id'];
} else {
    fail('create project', 'status=' . $created['status'] . ' body=' . $created['raw']);
    exit(1);
}

$steps = $created['json']['steps'] ?? [];
if (count($steps) === 5) {
    pass('create project inserts 5 steps');
} else {
    fail('create project inserts 5 steps', 'count=' . count($steps));
}

$storageRoot = getenv('STORAGE_ROOT') ?: $root . '/backend/storage';
$bookPath = rtrim($storageRoot, '/\\') . '/books/' . $projectId . '.txt';
if (is_file($bookPath)) {
    pass('book text saved to storage');
} else {
    fail('book text saved to storage', 'missing file');
}

// Project detail
$detail = httpRequest('GET', "{$baseUrl}/backend/api/project.php?id={$projectId}", null, $cookieFile);
if ($detail['status'] === 200 && ($detail['json']['project']['id'] ?? null) === $projectId) {
    pass('project detail');
} else {
    fail('project detail', 'status=' . $detail['status']);
}

// Full mock pipeline
$stepNames = ['style', 'characters', 'portraits', 'chapters', 'illustrations'];
foreach ($stepNames as $stepName) {
    $run = httpRequest('POST', "{$baseUrl}/backend/api/run-step.php", [
        'project_id' => $projectId,
        'step' => $stepName,
    ], $cookieFile);

    if ($run['status'] !== 200) {
        fail("pipeline step {$stepName}", 'status=' . $run['status'] . ' body=' . $run['raw']);
        break;
    }

    $completedStep = $run['json']['step'] ?? null;
    $stepState = null;
    foreach ($run['json']['detail']['steps'] ?? [] as $row) {
        if ($row['step'] === $completedStep) {
            $stepState = $row['state'];
        }
    }

    if ($completedStep === $stepName && $stepState === 'completed') {
        pass("pipeline step {$stepName}");
    } else {
        fail("pipeline step {$stepName}", "completed={$completedStep} state={$stepState}");
        break;
    }

    if ($stepName === 'style') {
        $delayedDuplicate = httpRequest('POST', "{$baseUrl}/backend/api/run-step.php", [
            'project_id' => $projectId,
            'step' => 'style',
        ], $cookieFile);

        $characterState = null;
        foreach ($delayedDuplicate['json']['detail']['steps'] ?? [] as $row) {
            if ($row['step'] === 'characters') {
                $characterState = $row['state'];
            }
        }

        if ($delayedDuplicate['status'] === 200 && $characterState === 'pending') {
            pass('delayed duplicate cannot advance next step');
        } else {
            fail('delayed duplicate cannot advance next step', $delayedDuplicate['raw']);
            break;
        }
    }
}

$final = httpRequest('GET', "{$baseUrl}/backend/api/project.php?id={$projectId}", null, $cookieFile);
$finalProject = $final['json'] ?? [];

if (($finalProject['project']['status'] ?? '') === 'done') {
    pass('pipeline completes project');
} else {
    fail('pipeline completes project', 'status=' . ($finalProject['project']['status'] ?? 'unknown'));
}

if (count($finalProject['characters'] ?? []) <= 2) {
    pass('max 2 characters');
} else {
    fail('max 2 characters', 'count=' . count($finalProject['characters']));
}

if (count($finalProject['chapters'] ?? []) <= 1) {
    pass('max 1 chapter');
} else {
    fail('max 1 chapter', 'count=' . count($finalProject['chapters']));
}

// Persisted list state across sign-out/sign-in, including independent projects.
$draftCreated = httpRequest('POST', "{$baseUrl}/backend/api/projects.php", [
    'title' => 'Draft Book',
    'book_text' => 'This project remains a draft.',
], $cookieFile);
$draftProjectId = (int) ($draftCreated['json']['project']['id'] ?? 0);

$partialCreated = httpRequest('POST', "{$baseUrl}/backend/api/projects.php", [
    'title' => 'Partial Book',
    'book_text' => 'This project stops after characters.',
], $cookieFile);
$partialProjectId = (int) ($partialCreated['json']['project']['id'] ?? 0);

foreach (['style', 'characters'] as $partialStep) {
    $partialRun = httpRequest('POST', "{$baseUrl}/backend/api/run-step.php", [
        'project_id' => $partialProjectId,
        'step' => $partialStep,
    ], $cookieFile);

    if ($partialRun['status'] !== 200) {
        fail('prepare partial project', 'step=' . $partialStep . ' body=' . $partialRun['raw']);
        break;
    }

    if ($stepName === 'style') {
        $delayedDuplicate = httpRequest('POST', "{$baseUrl}/backend/api/run-step.php", [
            'project_id' => $projectId,
            'step' => 'style',
        ], $cookieFile);

        $characterState = null;
        foreach ($delayedDuplicate['json']['detail']['steps'] ?? [] as $row) {
            if ($row['step'] === 'characters') {
                $characterState = $row['state'];
            }
        }

        if ($delayedDuplicate['status'] === 200 && $characterState === 'pending') {
            pass('delayed duplicate cannot advance next step');
        } else {
            fail('delayed duplicate cannot advance next step', $delayedDuplicate['raw']);
            break;
        }
    }
}

$signedOut = httpRequest('DELETE', "{$baseUrl}/backend/api/identity.php", null, $cookieFile);
$afterSignOut = httpRequest('GET', "{$baseUrl}/backend/api/projects.php", null, $cookieFile);
if ($signedOut['status'] === 200 && $afterSignOut['status'] === 401) {
    pass('sign out clears backend session');
} else {
    fail('sign out clears backend session', 'logout=' . $signedOut['status'] . ' projects=' . $afterSignOut['status']);
}

$resumedIdentity = httpRequest('POST', "{$baseUrl}/backend/api/identity.php", [
    'name' => 'Smoke Tester',
    'email' => 'smoke@example.com',
], $cookieFile);
$resumedList = httpRequest('GET', "{$baseUrl}/backend/api/projects.php", null, $cookieFile);
$projectsById = [];
foreach ($resumedList['json']['projects'] ?? [] as $listedProject) {
    $projectsById[(int) $listedProject['id']] = $listedProject;
}

$donePersisted = ($projectsById[$projectId]['status'] ?? '') === 'done'
    && (int) ($projectsById[$projectId]['completed_steps'] ?? -1) === 5;
$draftPersisted = ($projectsById[$draftProjectId]['status'] ?? '') === 'draft'
    && (int) ($projectsById[$draftProjectId]['completed_steps'] ?? -1) === 0;
$partialPersisted = ($projectsById[$partialProjectId]['status'] ?? '') === 'in_progress'
    && (int) ($projectsById[$partialProjectId]['completed_steps'] ?? -1) === 2;

if ($resumedIdentity['status'] === 200 && $donePersisted && $draftPersisted && $partialPersisted) {
    pass('project status and progress persist after login');
} else {
    fail('project status and progress persist after login', $resumedList['raw']);
}

// Invalid step order (corrupt DB)
resetSmokeData($pdo);
httpRequest('POST', "{$baseUrl}/backend/api/identity.php", [
    'name' => 'Order Tester',
    'email' => 'order@example.com',
], $cookieFile);
$created2 = httpRequest('POST', "{$baseUrl}/backend/api/projects.php", [
    'title' => 'Order Test',
    'book_text' => 'Order test book.',
], $cookieFile);
$projectId2 = (int) ($created2['json']['project']['id'] ?? 0);

$pdo->prepare("UPDATE project_steps SET state = 'pending' WHERE project_id = ? AND step = 'style'")
    ->execute([$projectId2]);
$pdo->prepare("UPDATE project_steps SET state = 'completed', completed_at = NOW() WHERE project_id = ? AND step = 'characters'")
    ->execute([$projectId2]);

$invalid = httpRequest('POST', "{$baseUrl}/backend/api/run-step.php", [
    'project_id' => $projectId2,
], $cookieFile);

if ($invalid['status'] === 409) {
    pass('invalid step order blocked');
} else {
    fail('invalid step order blocked', 'status=' . $invalid['status'] . ' body=' . $invalid['raw']);
}

// Duplicate running step
resetSmokeData($pdo);
httpRequest('POST', "{$baseUrl}/backend/api/identity.php", [
    'name' => 'Dup Tester',
    'email' => 'dup@example.com',
], $cookieFile);
$created3 = httpRequest('POST', "{$baseUrl}/backend/api/projects.php", [
    'title' => 'Dup Test',
    'book_text' => 'Dup test book.',
], $cookieFile);
$projectId3 = (int) ($created3['json']['project']['id'] ?? 0);

$pdo->prepare(
    "UPDATE project_steps SET state = 'running', started_at = NOW() WHERE project_id = ? AND step = 'style'"
)->execute([$projectId3]);

$dup = httpRequest('POST', "{$baseUrl}/backend/api/run-step.php", [
    'project_id' => $projectId3,
], $cookieFile);

if ($dup['status'] === 409) {
    pass('duplicate running step blocked');
} else {
    fail('duplicate running step blocked', 'status=' . $dup['status'] . ' body=' . $dup['raw']);
}

// Retry after failure
resetSmokeData($pdo);
httpRequest('POST', "{$baseUrl}/backend/api/identity.php", [
    'name' => 'Retry Tester',
    'email' => 'retry@example.com',
], $cookieFile);
$created4 = httpRequest('POST', "{$baseUrl}/backend/api/projects.php", [
    'title' => 'Retry Test',
    'book_text' => 'Retry test book.',
], $cookieFile);
$projectId4 = (int) ($created4['json']['project']['id'] ?? 0);

$pdo->prepare(
    "UPDATE project_steps SET state = 'failed', error_message = 'mock failure' WHERE project_id = ? AND step = 'style'"
)->execute([$projectId4]);

$retry = httpRequest('POST', "{$baseUrl}/backend/api/run-step.php", [
    'project_id' => $projectId4,
    'user_style' => 'Cottage-core watercolor',
], $cookieFile);

if ($retry['status'] === 200 && ($retry['json']['step'] ?? '') === 'style') {
    pass('retry failed step');
} else {
    fail('retry failed step', 'status=' . $retry['status'] . ' body=' . $retry['raw']);
}

// Stale recovery
resetSmokeData($pdo);
httpRequest('POST', "{$baseUrl}/backend/api/identity.php", [
    'name' => 'Stale Tester',
    'email' => 'stale@example.com',
], $cookieFile);
$created5 = httpRequest('POST', "{$baseUrl}/backend/api/projects.php", [
    'title' => 'Stale Test',
    'book_text' => 'Stale test book.',
], $cookieFile);
$projectId5 = (int) ($created5['json']['project']['id'] ?? 0);

$pdo->prepare(
    "UPDATE project_steps
     SET state = 'running', started_at = (NOW() - INTERVAL 600 SECOND)
     WHERE project_id = ? AND step = 'style'"
)->execute([$projectId5]);

putenv('STEP_STALE_SECONDS=300');
$_ENV['STEP_STALE_SECONDS'] = '300';

$stale = httpRequest('POST', "{$baseUrl}/backend/api/run-step.php", [
    'project_id' => $projectId5,
], $cookieFile);

if ($stale['status'] === 200 && ($stale['json']['step'] ?? '') === 'style') {
    pass('stale running step recovered and retried');
} else {
    fail('stale running step recovered and retried', 'status=' . $stale['status'] . ' body=' . $stale['raw']);
}

// Adult-only enforcement via direct pipeline subprocess
resetSmokeData($pdo);
$insertUser = $pdo->prepare('INSERT INTO users (name, email) VALUES (?, ?)');
$insertUser->execute(['Adult Test', 'adult@example.com']);
$testUserId = (int) $pdo->lastInsertId();

$insertProject = $pdo->prepare(
    'INSERT INTO projects (user_id, title, book_text, book_file_path, status)
     VALUES (?, ?, ?, ?, \'in_progress\')'
);
$insertProject->execute([$testUserId, 'Adult', 'text', 'books/adult.txt']);
$adultProjectId = (int) $pdo->lastInsertId();

foreach ([1 => 'style', 2 => 'characters', 3 => 'portraits', 4 => 'chapters', 5 => 'illustrations'] as $order => $step) {
    $state = $step === 'style' ? 'completed' : 'pending';
    $pdo->prepare(
        'INSERT INTO project_steps (project_id, step, step_order, state, completed_at)
         VALUES (?, ?, ?, ?, ' . ($state === 'completed' ? 'NOW()' : 'NULL') . ')'
    )->execute([$adultProjectId, $step, $order, $state]);
}

$pdo->prepare('INSERT INTO project_styles (project_id, style_text, source) VALUES (?, ?, ?)')
    ->execute([$adultProjectId, 'test style', 'generated']);

$tmpScript = sys_get_temp_dir() . '/bis-adult-test.php';
file_put_contents($tmpScript, <<<'PHP'
<?php
$root = getenv('SMOKE_ROOT');
require $root . '/backend/bootstrap.php';

class NonAdultMockProvider extends MockGeminiProvider
{
    public function generateCharacters(array $context): array
    {
        return [
            'characters' => [
                ['name' => 'Child', 'prompt' => 'Not allowed', 'is_adult' => false],
            ],
            'text_interaction_id' => 'mock-non-adult',
        ];
    }
}

$projectService = new ProjectService($pdo);
$pipeline = new PipelineService(
    $pdo,
    new NonAdultMockProvider(getenv('STORAGE_ROOT') ?: $root . '/backend/storage'),
    $projectService
);
$pipeline->runStep((int) getenv('SMOKE_PROJECT'), (int) getenv('SMOKE_USER'));
PHP);

putenv('SMOKE_ROOT=' . $root);
putenv('SMOKE_PROJECT=' . $adultProjectId);
putenv('SMOKE_USER=' . $testUserId);

exec('"' . (getenv('SMOKE_PHP') ?: PHP_BINARY) . '" ' . escapeshellarg($tmpScript) . ' 2>&1', $adultOut, $adultCode);

$stepRow = $pdo->prepare('SELECT state, error_message FROM project_steps WHERE project_id = ? AND step = \'characters\'');
$stepRow->execute([$adultProjectId]);
$charStep = $stepRow->fetch();
$adultOutput = implode("\n", $adultOut);

if (($charStep['state'] ?? '') === 'failed' && stripos((string) ($charStep['error_message'] ?? ''), 'adult') !== false) {
    pass('adult characters only');
} elseif (stripos($adultOutput, 'adult') !== false) {
    pass('adult characters only');
} else {
    fail('adult characters only', 'state=' . ($charStep['state'] ?? 'none') . ' output=' . $adultOutput);
}

@unlink($tmpScript);

$failed = array_values(array_filter($results, static fn ($r) => !$r['ok']));
echo "\nSummary: " . (count($results) - count($failed)) . ' passed, ' . count($failed) . " failed\n";

exit($failed === [] ? 0 : 1);
