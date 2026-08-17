<?php
declare(strict_types=1);

// Installed from modules/newsletter (v1). To update: re-run the installer.
//
// A subscriber list in a file. No database, no third-party account, no
// tracking pixels — the same shape as the rest of the framework.
//
// **Double opt-in, and not as a formality.** A single-opt-in list is how you
// end up mailing an address somebody else typed in — which under GDPR is
// processing without consent, and in practice is how a sending domain gets
// its reputation burned. An address is only confirmed when its owner clicks
// a link containing an HMAC token that only this site could produce.
//
// The store is a JSON-lines file: one record per line, appended. That makes
// concurrent writes safe with a single append, and the whole list greppable
// without any tooling.

require_once ROOT . '/lib/request.php';
require_once ROOT . '/lib/ratelimit.php';

const NEWSLETTER_DIR        = ROOT . '/_logs/newsletter';
const NEWSLETTER_FILE       = NEWSLETTER_DIR . '/subscribers.jsonl';
const NEWSLETTER_SECRET_FILE = NEWSLETTER_DIR . '/secret.key';
const NEWSLETTER_TOKEN_TTL  = 604800;   // a confirmation link is valid 7 days
const NEWSLETTER_RATE_SCOPE = 'newsletter-signup';
const NEWSLETTER_RATE_MAX   = 5;
const NEWSLETTER_RATE_WINDOW = 3600;

function newsletter_ensure_dir(): void
{
    if (!is_dir(NEWSLETTER_DIR) && !mkdir(NEWSLETTER_DIR, 0775, true) && !is_dir(NEWSLETTER_DIR)) {
        error_log('newsletter: could not create ' . NEWSLETTER_DIR);
    }
}

/** HMAC secret for confirmation links; generated and persisted on first use. */
function newsletter_secret(): string
{
    newsletter_ensure_dir();
    if (!is_file(NEWSLETTER_SECRET_FILE)) {
        file_put_contents(NEWSLETTER_SECRET_FILE, bin2hex(random_bytes(32)), LOCK_EX);
        chmod(NEWSLETTER_SECRET_FILE, 0640);
    }
    return trim((string) file_get_contents(NEWSLETTER_SECRET_FILE));
}

/**
 * A confirmation (or unsubscribe) token for an address. Contains the
 * timestamp so a link can expire, and is signed so it cannot be forged or
 * altered to confirm a different address.
 */
function newsletter_token(string $email, string $purpose): string
{
    $ts   = time();
    $data = $purpose . '|' . mb_strtolower(trim($email)) . '|' . $ts;
    return $ts . '.' . hash_hmac('sha256', $data, newsletter_secret());
}

function newsletter_token_valid(string $email, string $purpose, string $token): bool
{
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) return false;
    [$ts, $sig] = $parts;
    if (!ctype_digit($ts)) return false;
    $age = time() - (int) $ts;
    if ($age < 0 || $age > NEWSLETTER_TOKEN_TTL) return false;
    $data = $purpose . '|' . mb_strtolower(trim($email)) . '|' . $ts;
    return hash_equals(hash_hmac('sha256', $data, newsletter_secret()), $sig);
}

/** Every record in the store, newest state per address. */
function newsletter_subscribers(): array
{
    if (!is_file(NEWSLETTER_FILE)) {
        return [];
    }
    $subscribers = [];
    foreach (file(NEWSLETTER_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $record = json_decode($line, true);
        if (!is_array($record) || !isset($record['email'])) {
            continue;
        }
        // Later lines supersede earlier ones for the same address, so the
        // append-only file behaves like a log with a derived current state.
        $subscribers[mb_strtolower((string) $record['email'])] = $record;
    }
    return $subscribers;
}

/** Confirmed addresses only — what you would actually send to. */
function newsletter_confirmed(): array
{
    return array_filter(
        newsletter_subscribers(),
        static fn(array $record) => ($record['status'] ?? '') === 'confirmed'
    );
}

/** Append a state record for an address. */
function newsletter_write(string $email, string $status, string $lang): void
{
    newsletter_ensure_dir();
    $record = [
        'email'  => mb_strtolower(trim($email)),
        'status' => $status,
        'lang'   => $lang,
        'time'   => date('c'),
        'ip'     => client_ip(),
    ];
    $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($line === false) {
        error_log('newsletter: could not encode record');
        return;
    }
    file_put_contents(NEWSLETTER_FILE, $line . "\n", FILE_APPEND | LOCK_EX);
}

/**
 * Handle a signup. Returns 'pending' (confirmation link should be sent),
 * 'already' (address is already confirmed), 'invalid' or 'throttled'.
 *
 * Note it returns 'pending' for a brand-new address AND says nothing
 * different for one that is already pending — an attacker must not be able
 * to use this form to discover whether an address is on the list.
 */
function newsletter_signup(string $email, string $lang): string
{
    $email = trim($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 200) {
        return 'invalid';
    }
    $limit = ratelimit_check(NEWSLETTER_RATE_SCOPE, client_ip(), NEWSLETTER_RATE_MAX, NEWSLETTER_RATE_WINDOW);
    if (!$limit['allowed']) {
        return 'throttled';
    }

    $existing = newsletter_subscribers()[mb_strtolower($email)] ?? null;
    if ($existing !== null && ($existing['status'] ?? '') === 'confirmed') {
        return 'already';
    }

    newsletter_write($email, 'pending', $lang);
    return 'pending';
}

/** Confirm a pending address. */
function newsletter_confirm(string $email, string $token, string $lang): bool
{
    if (!newsletter_token_valid($email, 'confirm', $token)) {
        return false;
    }
    newsletter_write($email, 'confirmed', $lang);
    return true;
}

/** Unsubscribe an address. */
function newsletter_unsubscribe(string $email, string $token, string $lang): bool
{
    if (!newsletter_token_valid($email, 'unsubscribe', $token)) {
        return false;
    }
    newsletter_write($email, 'unsubscribed', $lang);
    return true;
}

/** The absolute confirmation URL to put in the email you send. */
function newsletter_confirm_url(string $email, string $lang): string
{
    return abs_url(lang_path($lang, 'newsletter_confirm'))
         . '?email=' . rawurlencode($email)
         . '&token=' . rawurlencode(newsletter_token($email, 'confirm'));
}

/** The absolute unsubscribe URL — put this in every mailing you send. */
function newsletter_unsubscribe_url(string $email, string $lang): string
{
    return abs_url(lang_path($lang, 'newsletter_confirm'))
         . '?action=unsubscribe&email=' . rawurlencode($email)
         . '&token=' . rawurlencode(newsletter_token($email, 'unsubscribe'));
}
