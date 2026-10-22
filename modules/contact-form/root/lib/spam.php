<?php
declare(strict_types=1);

// Installed from modules/contact-form (v2). To update: uninstall, then install.
//
// Four stacked layers of spam defence for the contact form. Each rejects the
// submission on the spot and quarantines it; NONE blocklists an IP on a
// single hit.
//
//   1. Honeypot — a hidden field a bot fills and a human does not.
//   2. HMAC time token — rejects submissions faster than human typing, and
//      forged or stale ones.
//   3. Behavioural signal — JS sets a marker on the first real input event.
//   4. Per-IP escalating limit — 5/hour, then 5/day, then 5/week, then blocked.
//
// **Nothing here is a first-strike ban, and that is the most important
// property in the file.** Layers 1–3 are strong signals, not certainties: a
// browser's autofill can fill a hidden field, an extension can break a
// script. A detector that can be wrong must not have an irreversible first
// strike — for most sites a single lost enquiry costs more than every piece
// of spam the blocklist has ever stopped. So layers 1–3 escalate to a
// permanent block only after SPAM_BOT_STRIKES_BEFORE_BLOCK strikes inside
// SPAM_BOT_STRIKE_WINDOW_SEC, and every rejection is written to quarantine
// so a false positive is recoverable rather than silently lost.

require_once ROOT . '/lib/request.php';
require_once ROOT . '/lib/ratelimit.php';

const SPAM_DIR            = ROOT . '/_logs/spam';
const SPAM_BLOCKLIST_FILE = SPAM_DIR . '/blocklist.txt';
const SPAM_QUARANTINE_DIR = SPAM_DIR . '/quarantine';
const SPAM_STATE_DIR      = SPAM_DIR . '/state';
const SPAM_SECRET_FILE    = SPAM_DIR . '/secret.key';

const SPAM_FORM_MIN_AGE_SEC = 3;     // Submitting faster than this = bot.
const SPAM_FORM_MAX_AGE_SEC = 7200;  // Older than 2h = stale tab, not a bot.

// Longest field value kept in a quarantine record. Matches the form's own
// maxlength, so a real submission survives intact while a bot posting
// megabytes cannot fill the disk.
const SPAM_QUARANTINE_MAX_FIELD = 5000;

// How many bot signals an IP may trip before the block becomes permanent,
// and the window they must fall inside. Three inside a week separates the
// two populations cleanly: a person trips a layer once, by accident, and
// usually deterministically (autofill, an extension) — so their retry fails
// the same way, which is what the 404's contact hint exists for. A bot trips
// it repeatedly within minutes. Strikes outside the window are forgotten, so
// an accident in March cannot combine with one in November to ban somebody.
const SPAM_BOT_STRIKES_BEFORE_BLOCK = 3;
const SPAM_BOT_STRIKE_WINDOW_SEC    = 604800;  // 7 days

// Escalation ladder for layer 4. Same allowance each tier, exponentially
// longer window — one lever, so the steps compose cleanly.
const SPAM_RATE_ALLOWANCE = 5;
const SPAM_RATE_TIERS     = [3600, 86400, 604800];  // hour, day, week
const SPAM_RATE_SCOPE     = 'contact-form';

function spam_ensure_dirs(): void
{
    foreach ([SPAM_DIR, SPAM_QUARANTINE_DIR, SPAM_STATE_DIR] as $dir) {
        // The trailing is_dir() absorbs the concurrent-request race that an
        // @-suppression would otherwise hide.
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            error_log('spam: could not create directory ' . $dir);
        }
    }
}

/**
 * The HMAC secret for form tokens. Prefers FORM_TOKEN_SECRET from the
 * environment; otherwise generates one and persists it, so a site with no
 * configuration still gets unforgeable tokens.
 */
function spam_secret(): string
{
    $env = getenv('FORM_TOKEN_SECRET');
    if (is_string($env) && $env !== '') {
        return $env;
    }
    spam_ensure_dirs();
    if (!is_file(SPAM_SECRET_FILE)) {
        file_put_contents(SPAM_SECRET_FILE, bin2hex(random_bytes(32)), LOCK_EX);
        chmod(SPAM_SECRET_FILE, 0640);
    }
    return trim((string) file_get_contents(SPAM_SECRET_FILE));
}

function spam_make_token(): string
{
    $ts = time();
    return $ts . '.' . hash_hmac('sha256', (string) $ts, spam_secret());
}

/**
 * Verify a form token:
 *   'ok'       valid, age within bounds
 *   'too_fast' valid signature but submitted within SPAM_FORM_MIN_AGE_SEC
 *   'invalid'  missing, malformed, forged, or stale
 */
function spam_verify_token(string $token): string
{
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) return 'invalid';
    [$ts, $sig] = $parts;
    if (!ctype_digit($ts)) return 'invalid';
    if (!hash_equals(hash_hmac('sha256', $ts, spam_secret()), $sig)) return 'invalid';
    $age = time() - (int) $ts;
    if ($age < 0 || $age > SPAM_FORM_MAX_AGE_SEC) return 'invalid';
    if ($age < SPAM_FORM_MIN_AGE_SEC)             return 'too_fast';
    return 'ok';
}

function spam_is_blocked(string $ip): bool
{
    if (!is_file(SPAM_BLOCKLIST_FILE)) return false;
    $handle = fopen(SPAM_BLOCKLIST_FILE, 'r');
    if ($handle === false) return false;
    $found = false;
    while (($line = fgets($handle)) !== false) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strtok($line, "\t ") === $ip) {
            $found = true;
            break;
        }
    }
    fclose($handle);
    return $found;
}

/** Append an IP to the blocklist. Idempotent; the file is hand-editable. */
function spam_block_ip(string $ip, string $reason): void
{
    // Never block "unknown": it is every visitor client_ip() could not
    // identify, not one address. Named explicitly because filter_var()
    // accepts '0.0.0.0' as a valid IP, so validating alone never caught it.
    if ($ip === REQUEST_UNKNOWN_IP || !filter_var($ip, FILTER_VALIDATE_IP)) return;
    if (spam_is_blocked($ip)) return;
    spam_ensure_dirs();
    $line = $ip . "\t" . date('c') . "\t" . preg_replace('/\s+/', ' ', $reason) . "\n";
    file_put_contents(SPAM_BLOCKLIST_FILE, $line, FILE_APPEND | LOCK_EX);
}

/**
 * Write a blocked submission to quarantine so a FALSE POSITIVE stays
 * recoverable.
 *
 * The blocklist records only ip/time/reason, which cannot answer the question
 * that matters when the filter is wrong — "was that a human?" — so a genuine
 * enquiry caught by a layer would simply be lost, with the sender told
 * nothing but a 404.
 *
 * The honeypot value is the most diagnostic field in the record: an
 * autofilled address or company name means a browser filled it (human); a URL
 * or nonsense means a bot did.
 *
 * Quarantine holds the same personal data as a delivered submission, so it
 * carries the same retention — scripts/prune-mail-logs.php prunes this
 * directory too, and your privacy policy must declare it.
 */
function spam_quarantine(string $ip, string $reason, array $submitted): void
{
    spam_ensure_dirs();

    $field = static function (string $key) use ($submitted): string {
        $value = $submitted[$key] ?? '';
        if (!is_string($value)) {
            return '(non-string value)';
        }
        return mb_substr($value, 0, SPAM_QUARANTINE_MAX_FIELD);
    };

    $record = 'Time:      ' . date('c') . "\n"
            . 'IP:        ' . $ip . "\n"
            . 'Blocked:   ' . preg_replace('/\s+/', ' ', $reason) . "\n"
            // A browser-autofilled value here means the sender was a person.
            . 'Honeypot:  ' . $field('order_ref') . "\n"
            . 'UserAgent: ' . request_header('HTTP_USER_AGENT') . "\n"
            . 'Referer:   ' . request_header('HTTP_REFERER') . "\n"
            . str_repeat('-', 60) . "\n"
            . 'Name:      ' . $field('name') . "\n"
            . 'Email:     ' . $field('email') . "\n"
            . 'Subject:   ' . $field('subject') . "\n"
            . "Message:\n" . $field('message') . "\n";

    $path = SPAM_QUARANTINE_DIR . '/' . date('Y-m-d_His') . '_' . bin2hex(random_bytes(2)) . '.txt';

    // Deliberately not suppressed: a quarantine write that fails silently
    // defeats the entire point of the file.
    if (file_put_contents($path, $record, LOCK_EX) === false) {
        error_log('spam: could not write quarantine record to ' . $path);
    }
}

/** Per-IP escalation state (offense tier + bot strikes). */
function spam_state_path(string $ip): string
{
    spam_ensure_dirs();
    return SPAM_STATE_DIR . '/' . md5($ip) . '.json';
}

function spam_state_load(string $ip): array
{
    $empty = ['offenses' => 0, 'bot_strikes' => []];
    $file  = spam_state_path($ip);
    if (!is_file($file)) return $empty;
    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data)) return $empty;
    return [
        'offenses'    => (int) ($data['offenses'] ?? 0),
        'bot_strikes' => is_array($data['bot_strikes'] ?? null) ? $data['bot_strikes'] : [],
    ];
}

function spam_state_save(string $ip, array $state): void
{
    file_put_contents(spam_state_path($ip), json_encode($state), LOCK_EX);
}

/**
 * Record one bot signal against an IP; report whether it has now earned a
 * permanent block. Strikes are stored as a list of timestamps rather than a
 * counter, so an old accident ages out on its own instead of waiting for
 * someone to clear it by hand.
 */
function spam_record_bot_strike(string $ip): bool
{
    $state  = spam_state_load($ip);
    $now    = time();
    $cutoff = $now - SPAM_BOT_STRIKE_WINDOW_SEC;

    $strikes = array_values(array_filter(
        $state['bot_strikes'],
        static fn($when) => is_int($when) && $when >= $cutoff
    ));
    $strikes[] = $now;

    $state['bot_strikes'] = $strikes;
    spam_state_save($ip, $state);

    return count($strikes) >= SPAM_BOT_STRIKES_BEFORE_BLOCK;
}

/**
 * Layer 4: record a submission attempt and decide whether to allow it.
 * Returns ['allowed' => bool, 'block' => bool].
 *
 * The hit counting itself is the core rate-limit primitive
 * (lib/ratelimit.php) — the same one the admin module uses for login, so
 * there is one implementation of "N attempts per window" in the codebase.
 * What lives here is the *escalation*, which is a different concept: each
 * time an IP exhausts its allowance the window it must wait out grows.
 *
 *   0 offenses → 5 / hour   (a normal user, or a refresh-happy one)
 *   1 offense  → 5 / day
 *   2 offenses → 5 / week
 *   3+         → permanently blocked
 */
function spam_ratelimit_check(string $ip): array
{
    $state    = spam_state_load($ip);
    $offenses = (int) $state['offenses'];
    if ($offenses >= count(SPAM_RATE_TIERS)) {
        return ['allowed' => false, 'block' => true];
    }

    $window = SPAM_RATE_TIERS[min($offenses, count(SPAM_RATE_TIERS) - 1)];
    $result = ratelimit_check(SPAM_RATE_SCOPE, $ip, SPAM_RATE_ALLOWANCE, $window);

    if ($result['allowed']) {
        return ['allowed' => true, 'block' => false];
    }

    $state['offenses'] = $offenses + 1;
    spam_state_save($ip, $state);
    // A fresh window for the new tier — the sanction is the longer wait, not
    // a permanently exhausted counter.
    ratelimit_reset(SPAM_RATE_SCOPE, $ip);

    return [
        'allowed' => false,
        'block'   => $state['offenses'] >= count(SPAM_RATE_TIERS),
    ];
}
