<?php

declare(strict_types=1);

function health_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

putenv('APP_ENV=local');
putenv('WORKER_URL=');
putenv('WORKER_PORT=3210');
$config = require dirname(__DIR__) . '/config/app.php';

function app_config(?string $key = null): mixed
{
    global $config;
    return $key === null ? $config : ($config[$key] ?? null);
}

require_once dirname(__DIR__) . '/src/lib/WorkerClient.php';

health_assert(
    $config['worker']['url'] === 'http://127.0.0.1:3210',
    'missing WORKER_URL uses the loopback URL derived from WORKER_PORT'
);

$ready = [
    'ok' => true,
    'status_code' => 200,
    'service' => 'whatsapp-worker',
    'database' => ['connected' => true, 'schema_ready' => true],
];
health_assert(worker_health_ready($ready), 'healthy worker and ready database pass');

$databaseNotReady = $ready;
$databaseNotReady['status_code'] = 503;
$databaseNotReady['ok'] = false;
$databaseNotReady['database']['schema_ready'] = false;
health_assert(!worker_health_ready($databaseNotReady), 'database-not-ready response fails readiness');

$invalidToken = $ready;
$invalidToken['status_code'] = 401;
$invalidToken['ok'] = false;
health_assert(!worker_health_ready($invalidToken), 'unauthorized worker response fails readiness');

$badRequest = $ready;
$badRequest['status_code'] = 400;
health_assert(!worker_health_ready($badRequest), 'unexpected HTTP 400 response fails readiness');

$unreachable = ['ok' => false, 'status_code' => 0];
health_assert(!worker_health_ready($unreachable), 'unreachable worker fails readiness');

fwrite(STDOUT, "Worker health contract tests passed.\n");
