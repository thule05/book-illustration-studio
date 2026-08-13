<?php

declare(strict_types=1);

require_once __DIR__ . '/utils/env.php';
require_once __DIR__ . '/utils/response.php';
require_once __DIR__ . '/utils/auth.php';

load_env(dirname(__DIR__) . '/.env');

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/services/providers/GeminiProvider.php';
require_once __DIR__ . '/services/providers/MockGeminiProvider.php';
require_once __DIR__ . '/services/providers/RealGeminiProvider.php';
require_once __DIR__ . '/services/providers/ProviderFactory.php';
require_once __DIR__ . '/services/PipelineService.php';
require_once __DIR__ . '/services/ProjectService.php';

start_session();
