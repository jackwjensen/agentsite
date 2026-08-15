<?php
declare(strict_types=1);

/**
 * Generic file-based rate limiting, keyed by an arbitrary string (usually a
 * client IP). One implementation shared by every consumer — a form module's
 * submission limit and an admin module's login limit must not drift apart.
 *
 * State lives under _logs/ratelimit/<scope>/ as one small JSON file per key:
 *   { "count": <attempts in window>, "window_start": <unix ts> }
 *
 * No database, no cron: an expired window resets lazily on the next check,
 * and the files are tiny. _logs/ is bind-mounted, gitignored, and owned by
 * the PHP user (docker/entrypoint.sh).
 */

/**
 * Register an attempt for $key in $scope and report whether it is allowed.
 * A fixed window: at most $max_attempts per $window_seconds.
 *
 * @return array{allowed:bool,remaining:int,retry_after:int}
 */
function ratelimit_check(string $scope, string $key, int $max_attempts, int $window_seconds): array
{
    $now  = time();
    $file = ratelimit_file($scope, $key);

    $state = ['count' => 0, 'window_start' => $now];
    if (is_file($file)) {
        $raw     = file_get_contents($file);
        $decoded = $raw !== false ? json_decode($raw, true) : null;
        if (is_array($decoded) && isset($decoded['count'], $decoded['window_start'])) {
            $state = ['count' => (int) $decoded['count'], 'window_start' => (int) $decoded['window_start']];
        }
    }

    // Window expired → start fresh.
    if ($now - $state['window_start'] >= $window_seconds) {
        $state = ['count' => 0, 'window_start' => $now];
    }

    $state['count']++;
    ratelimit_save($file, $state);

    $allowed = $state['count'] <= $max_attempts;
    return [
        'allowed'     => $allowed,
        'remaining'   => max(0, $max_attempts - $state['count']),
        'retry_after' => $allowed ? 0 : max(0, $state['window_start'] + $window_seconds - $now),
    ];
}

/** Clear the counter for a key (e.g. after a successful login). */
function ratelimit_reset(string $scope, string $key): void
{
    $file = ratelimit_file($scope, $key);
    if (is_file($file)) {
        unlink($file);
    }
}

/** State file path for a scope + key. The key is hashed — never a filename. */
function ratelimit_file(string $scope, string $key): string
{
    // Scope is caller-supplied code, not user input, but sanitise anyway.
    $scope = preg_replace('/[^a-z0-9_-]/', '', strtolower($scope)) ?: 'default';
    $dir   = ROOT . "/_logs/ratelimit/$scope";
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    return $dir . '/' . md5($key) . '.json';
}

function ratelimit_save(string $file, array $state): void
{
    $json = json_encode($state);
    if ($json !== false) {
        file_put_contents($file, $json, LOCK_EX);
    }
}
