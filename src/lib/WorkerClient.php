<?php

declare(strict_types=1);

function worker_request_url(string $baseUrl, string $path): string
{
    return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
}

function worker_safe_target(string $url): string
{
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return '[invalid-worker-url]';
    }

    $path = (string) ($parts['path'] ?? '/');
    $path = preg_replace('#/(?:[^/]*(?:token|secret|password|key)[^/]*)#i', '/[redacted]', $path) ?? '/[redacted]';
    return strtolower((string) $parts['scheme']) . '://' . $parts['host']
        . (isset($parts['port']) ? ':' . (int) $parts['port'] : '') . $path;
}

function worker_safe_response_excerpt(string $body): string
{
    $body = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $body = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $body) ?? '';
    $body = preg_replace('/\s+/u', ' ', trim($body)) ?? '';
    foreach ([
        app_config('security.worker_token'),
        app_config('db.password'),
        app_config('system_api.secret'),
        app_config('smtp.pass'),
    ] as $secret) {
        if (is_string($secret) && $secret !== '') {
            $body = str_replace($secret, '[redacted]', $body);
        }
    }
    $body = preg_replace('/(authorization|token|password|passwd|secret)(["\']?\s*[:=]\s*["\']?)[^\s,"\']+/i', '$1$2[redacted]', $body) ?? '';
    return substr($body, 0, 240);
}

function worker_request(string $method, string $path, array $payload = [], int $timeout = 20): array
{
    $url = worker_request_url((string) app_config('worker.url'), $path);
    $isHealthProbe = trim($path, '/') === 'health';
    $body = $payload ? json_encode($payload, JSON_UNESCAPED_SLASHES) : '';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => min(3, $timeout),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Worker-Token: ' . app_config('security.worker_token'),
        ],
    ]);

    if ($body !== '') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $raw = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($raw === false) {
        if ($isHealthProbe) {
            error_log('[worker-health] target=' . worker_safe_target($url) . ' status=' . $status . ' response=[no response] transport_error');
        }
        return ['ok' => false, 'status' => 0, 'error' => $error ?: 'Worker is not reachable.'];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        if ($isHealthProbe) {
            error_log('[worker-health] target=' . worker_safe_target($url) . ' status=' . $status . ' response=' . worker_safe_response_excerpt($raw) . ' invalid_json');
        }
        return ['ok' => false, 'status' => $status, 'error' => 'Worker returned an invalid response.'];
    }

    $data['status_code'] = $status;
    if ($isHealthProbe && !worker_health_ready($data)) {
        error_log('[worker-health] target=' . worker_safe_target($url) . ' status=' . $status . ' response=' . worker_safe_response_excerpt($raw) . ' readiness_rejected');
    }
    return $data;
}

function worker_health_ready(array $response): bool
{
    $database = $response['database'] ?? null;

    return (int) ($response['status_code'] ?? 0) === 200
        && ($response['ok'] ?? false) === true
        && ($response['service'] ?? null) === 'whatsapp-worker'
        && is_array($database)
        && ($database['connected'] ?? false) === true
        && ($database['schema_ready'] ?? false) === true;
}

function sync_session_status(int $userId, array $workerStatus): void
{
    $state = $workerStatus['state'] ?? 'idle';
    $allowed = ['idle', 'connecting', 'qr', 'connected', 'disconnected', 'error'];
    if (!in_array($state, $allowed, true)) {
        $state = 'error';
    }

    $sleeping = !empty($workerStatus['sleeping']) ? 1 : 0;

    try {
        $stmt = Database::pdo()->prepare(
            'UPDATE whatsapp_sessions
             SET status = ?, sleeping = ?, phone = ?, push_name = ?, qr_updated_at = ?, connected_at = ?, disconnected_at = ?, last_error = ?
             WHERE user_id = ?'
        );
        $stmt->execute([
            $state,
            $sleeping,
            $workerStatus['phone'] ?? null,
            $workerStatus['pushName'] ?? null,
            !empty($workerStatus['hasQr']) ? date('Y-m-d H:i:s') : null,
            $state === 'connected' ? date('Y-m-d H:i:s') : null,
            in_array($state, ['disconnected', 'error'], true) ? date('Y-m-d H:i:s') : null,
            $workerStatus['error'] ?? null,
            $userId,
        ]);
    } catch (PDOException $exception) {
        if ((string) $exception->getCode() !== '42S22') {
            throw $exception;
        }

        $stmt = Database::pdo()->prepare(
            'UPDATE whatsapp_sessions
             SET status = ?, phone = ?, push_name = ?, qr_updated_at = ?, connected_at = ?, disconnected_at = ?, last_error = ?
             WHERE user_id = ?'
        );
        $stmt->execute([
            $state,
            $workerStatus['phone'] ?? null,
            $workerStatus['pushName'] ?? null,
            !empty($workerStatus['hasQr']) ? date('Y-m-d H:i:s') : null,
            $state === 'connected' ? date('Y-m-d H:i:s') : null,
            in_array($state, ['disconnected', 'error'], true) ? date('Y-m-d H:i:s') : null,
            $workerStatus['error'] ?? null,
            $userId,
        ]);
    }
}
