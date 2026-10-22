<?php
declare(strict_types=1);

/**
 * Facts about the incoming HTTP request, in one place.
 *
 * "Who sent this?" must have exactly one answer. Behind a reverse proxy (or
 * a CDN in front of one), REMOTE_ADDR is the proxy, not the visitor — any
 * code reading it directly reports the wrong address in production while
 * looking correct in development, where nothing sits in front of PHP.
 * Every consumer (rate limiting, form handling, logging) uses these helpers.
 *
 * EVERY HEADER IS ATTACKER-CONTROLLED unless a hop we trust wrote it. Until
 * 2026-10-22 client_ip() took the LEFTMOST X-Forwarded-For entry and believed
 * CF-Connecting-IP from anyone. Each proxy APPENDS the peer it saw, so the
 * left end of X-Forwarded-For is whatever the caller invented — and a caller
 * reaching the site without Cloudflare can send CF-Connecting-IP too. Either
 * way one forged header per request was a fresh rate-limit bucket: unlimited
 * admin login attempts, contact-form and newsletter submissions.
 *
 * The algorithm below is the one allegroit-dk and jacks_corner use (itself
 * transcribed from get_client_ip in allegro-it-services). Keep them in step.
 */

// Longest header value any caller keeps. Every real user agent and referer
// fits well inside this; a crafted one should not be able to bloat a log.
const REQUEST_HEADER_MAX_LENGTH = 500;

// Returned when nothing in the request names a visitor. It means "unknown",
// not an address. Consumers rate-limit it like any other key, which groups
// every unidentifiable caller into one bucket — the safe direction: they
// throttle each other rather than passing unmetered.
const REQUEST_UNKNOWN_IP = '0.0.0.0';

/**
 * The declared trusted hops, from config/trusted-hops.php. A missing or
 * malformed file is an empty set rather than a fatal: the walk then returns
 * the connection peer, which is right with nothing in front and wrong behind
 * a proxy — so it is logged, because nothing else would show it.
 *
 * @return array{own: list<string>, cdn: list<string>, header: string}
 */
function request_trusted_hops(): array
{
    static $config = null;
    if ($config !== null) return $config;

    $path     = ROOT . '/config/trusted-hops.php';
    $declared = is_file($path) ? require $path : null;
    if (!is_array($declared)) {
        error_log("client_ip: no trusted-hop config at $path — every address will resolve to the connection peer");
        $declared = [];
    }

    $config = [
        'own'    => is_array($declared['own_infrastructure'] ?? null) ? $declared['own_infrastructure'] : [],
        'cdn'    => is_array($declared['cdn']['ranges'] ?? null) ? $declared['cdn']['ranges'] : [],
        'header' => is_string($declared['cdn']['header'] ?? null) ? $declared['cdn']['header'] : '',
    ];
    return $config;
}

/**
 * The client's IP address, resolved from the hops config/trusted-hops.php
 * declares:
 *
 *   1. Build the chain: the X-Forwarded-For entries, then the connection's
 *      peer address appended last, so the chain ends in a fact nobody can
 *      forge.
 *   2. Walk it right to left, stepping over declared hops.
 *   3. The first entry that is not one of them is the visitor.
 *   4. Crossing a Cloudflare address earns CF-Connecting-IP, which is then
 *      checked against the walk.
 *
 * Returns REQUEST_UNKNOWN_IP when every hop was ours (development, a health
 * check) or the evidence disagrees. Never returns ''.
 */
function client_ip(): string
{
    $trusted = request_trusted_hops();

    $chain   = request_forwarded_hops();
    $chain[] = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));

    $cdn_crossed = false;
    $caller      = null;

    foreach (array_reverse($chain) as $hop) {
        if (request_matches_any_range($hop, $trusted['own'])) continue;
        if (request_matches_any_range($hop, $trusted['cdn'])) {
            $cdn_crossed = true;
            continue;
        }
        // Not ours and not the CDN: this is the caller — even when it does
        // not parse. Stepping over garbage would walk left into entries the
        // caller wrote, so padding the header would steer the answer.
        $caller = request_packed_address($hop) !== null ? $hop : null;
        break;
    }

    if ($cdn_crossed) {
        $stated = trim((string) ($_SERVER[$trusted['header']] ?? ''));
        if (request_packed_address($stated) !== null) {
            // The chain ran out past the edge (a hop that overwrites
            // X-Forwarded-For instead of appending): the header is the only
            // record of the visitor.
            if ($caller === null) return request_canonical_address($stated);

            // The CDN named the visitor twice — in the chain and in its own
            // header. Compared parsed, so two spellings of one IPv6 agree.
            if (request_same_address($caller, $stated)) return request_canonical_address($stated);

            // They disagree, which means one of our hops is misconfigured.
            // Picking either would give a wrong answer a confident value.
            error_log(sprintf(
                'client_ip: chain says %s but %s says %s — check config/trusted-hops.php',
                $caller,
                $trusted['header'],
                $stated
            ));
            return REQUEST_UNKNOWN_IP;
        }
    }

    return $caller === null ? REQUEST_UNKNOWN_IP : request_canonical_address($caller);
}

/**
 * The X-Forwarded-For entries in header order: oldest claim first, nearest
 * hop last. Read raw rather than through request_header(): clipping it to a
 * length would cut the RIGHT end — the entries our own hops appended — and
 * keep the caller's.
 *
 * @return list<string>
 */
function request_forwarded_hops(): array
{
    $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if (!is_string($forwarded) || $forwarded === '') return [];

    $hops = [];
    foreach (explode(',', $forwarded) as $hop) {
        $hop = trim($hop);
        if ($hop !== '') $hops[] = $hop;
    }
    return $hops;
}

/**
 * One address parsed to its packed bytes, or null if it is not an address.
 * The one place spelling is decided, because every consumer keys on the
 * result and a spelling difference is a separate rate-limit bucket:
 *
 *  - Brackets and ports are stripped: proxies write `[2001:db8::9]:443` and
 *    `198.51.100.9:5678`.
 *  - An IPv4-mapped IPv6 (`::ffff:198.51.100.9`) folds to plain IPv4, or a
 *    caller could pick between two buckets by how they spell their address.
 */
function request_packed_address(string $value): ?string
{
    $value = trim($value);
    if ($value === '') return null;

    if (preg_match('/^\[(.+)\](?::\d+)?$/', $value, $m) === 1) {
        $value = $m[1];
    } elseif (substr_count($value, ':') === 1) {
        // One colon: IPv4 with a port, or broken IPv6. Strip only the former.
        [$head] = explode(':', $value, 2);
        if (filter_var($head, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) $value = $head;
    }

    $packed = @inet_pton($value);
    if ($packed === false) return null;

    // ::ffff:a.b.c.d is 10 zero bytes, two 0xFF bytes, then the IPv4 address.
    // Built with chr() rather than "\xFF" so re-encoding the file cannot
    // silently break the comparison.
    if (strlen($packed) === 16
        && substr($packed, 0, 10) === str_repeat(chr(0), 10)
        && substr($packed, 10, 2) === chr(255) . chr(255)) {
        return substr($packed, 12, 4);
    }
    return $packed;
}

/** The canonical spelling of an address, so one address is one rate-limit key. */
function request_canonical_address(string $value): string
{
    $packed = request_packed_address($value);
    if ($packed === null) return REQUEST_UNKNOWN_IP;
    $canonical = @inet_ntop($packed);
    return is_string($canonical) ? $canonical : REQUEST_UNKNOWN_IP;
}

/** Are these the same address, however each is spelled? */
function request_same_address(string $left, string $right): bool
{
    $a = request_packed_address($left);
    $b = request_packed_address($right);
    return $a !== null && $b !== null && $a === $b;
}

/** Is $value inside any of $ranges? An unparseable value matches nothing. */
function request_matches_any_range(string $value, array $ranges): bool
{
    foreach ($ranges as $range) {
        if (is_string($range) && request_ip_in_cidr($value, $range)) return true;
    }
    return false;
}

/**
 * Is $ip inside $cidr? IPv4 and IPv6 alike, compared on the packed form; an
 * address of one family is never inside a range of the other.
 */
function request_ip_in_cidr(string $ip, string $cidr): bool
{
    if (substr_count($cidr, '/') !== 1) return false;
    [$subnet, $prefix_length] = explode('/', $cidr);

    $address = request_packed_address($ip);
    $network = request_packed_address($subnet);
    if ($address === null || $network === null || strlen($address) !== strlen($network)) {
        return false;
    }

    $bits = (int) $prefix_length;
    if ($bits < 0 || $bits > strlen($network) * 8) return false;

    $whole_bytes = intdiv($bits, 8);
    if ($whole_bytes > 0 && strncmp($address, $network, $whole_bytes) !== 0) return false;

    $leftover_bits = $bits % 8;
    if ($leftover_bits === 0) return true;

    $mask = ~(0xFF >> $leftover_bits) & 0xFF;
    return (ord($address[$whole_bytes]) & $mask) === (ord($network[$whole_bytes]) & $mask);
}

/**
 * A request header's value, clipped to a sane length. Takes the $_SERVER key
 * ('HTTP_USER_AGENT', 'HTTP_REFERER') because that is what callers have.
 * A missing header and an array-valued one both read as ''.
 */
function request_header(string $server_key, int $max_length = REQUEST_HEADER_MAX_LENGTH): string
{
    $value = $_SERVER[$server_key] ?? '';
    return is_string($value) ? mb_substr($value, 0, $max_length) : '';
}

/**
 * Whether the request arrived over HTTPS, seen through a TLS-terminating
 * proxy: the proxy's X-Forwarded-Proto wins; a bare container connection
 * falls back to the request's own scheme.
 */
function request_is_https(): bool
{
    $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null;
    if (is_string($proto) && $proto !== '') {
        return strtolower(trim(explode(',', $proto)[0])) === 'https';
    }
    return ($_SERVER['HTTPS'] ?? '') === 'on';
}
