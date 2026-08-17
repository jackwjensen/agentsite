<?php
declare(strict_types=1);

/**
 * Installed from modules/admin (v1).
 *
 *   php scripts/admin-password.php
 *
 * Sets the admin password by writing its bcrypt hash to
 * config/admin-password.hash (gitignored). The plaintext password is never
 * written anywhere — not to that file, not to .env, not to a log, not to
 * this repo.
 *
 * **The hash goes in a file rather than .env because a bcrypt hash contains
 * `$`,** and Docker Compose interpolates `$` sequences in .env — a hash
 * pasted there arrives with a chunk silently eaten, and the only symptom is
 * that the right password stops working.
 *
 * Changing or resetting the password means running this again. That is the
 * whole password-management story, deliberately: a single-owner admin area
 * does not need a reset flow, and a reset flow is an attack surface.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

echo "Admin password setup\n";
echo "--------------------\n";

// Turn off terminal echo where the platform allows it, so the password does
// not stay visible on screen or in a screenshot.
$hide = stripos(PHP_OS_FAMILY, 'Windows') === false && is_readable('/dev/tty');
if ($hide) {
    shell_exec('stty -echo < /dev/tty 2>/dev/null');
}

echo 'New password: ';
$password = trim((string) fgets(STDIN));
if ($hide) {
    echo "\n";
    echo 'Repeat:       ';
}
$repeat = trim((string) fgets(STDIN));

if ($hide) {
    shell_exec('stty echo < /dev/tty 2>/dev/null');
    echo "\n";
}

if ($password === '') {
    fwrite(STDERR, "Aborted: empty password.\n");
    exit(1);
}
if ($password !== $repeat) {
    fwrite(STDERR, "Aborted: the two entries did not match.\n");
    exit(1);
}
if (mb_strlen($password) < 12) {
    fwrite(STDERR, "Aborted: use at least 12 characters. This is the only credential the admin area has.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_BCRYPT);
if (!is_string($hash)) {
    fwrite(STDERR, "Could not hash the password.\n");
    exit(1);
}

$target = dirname(__DIR__) . '/config/admin-password.hash';
if (file_put_contents($target, $hash . "\n", LOCK_EX) === false) {
    fwrite(STDERR, "Could not write $target\n");
    fwrite(STDERR, "Write this hash there by hand instead:\n$hash\n");
    exit(1);
}
chmod($target, 0640);

echo "\nPassword set — hash written to config/admin-password.hash\n";
echo "(gitignored, so it never reaches the repository).\n\n";
echo "Restart to pick it up:  docker compose up -d\n";
