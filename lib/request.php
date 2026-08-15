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
 */

// Longest header value any caller keeps. Every real user agent and referer
// fits well inside this; a crafted one should not be able to bloat a log.
const REQUEST_HEADER_MAX_LENGTH = 500;

/**
 * The client's IP address, walking the proxy chain: CDN header first
 * (Cloudflare's CF-Connecting-IP), then X-Forwarded-For (original client is
 * the first entry), then REMOTE_ADDR (only correct with nothing in front).
 *
 * Returns '0.0.0.0' when no header holds a valid address — that value means
 * "unknown", not an address; never rate-limit or block it.
 */
function client_ip(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $header) {
        $value = $_SERVER[$header] ?? '';
        if (!is_string($value) || $value === '') continue;
        $first = trim(explode(',', $value)[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) return $first;
    }
    return '0.0.0.0';
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
