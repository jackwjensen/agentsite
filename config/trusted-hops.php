<?php
declare(strict_types=1);

/**
 * The proxies in front of this site — the one thing client_ip() in
 * lib/request.php needs to know about your network.
 *
 * Every entry here is a hop whose word is believed: anything inside these
 * ranges can name the visitor's address, and rate limits and logs will use
 * it. Adding a range is a security decision, so it is a file you change in
 * a reviewable diff rather than an environment variable a typo can empty.
 *
 * The defaults work, unchanged, for every setup docs/DEPLOY.md describes:
 *
 *   reverse proxy on the same host   visitor → Caddy/nginx/Traefik → this container
 *   Cloudflare in front of that      visitor → Cloudflare → proxy → this container
 *   development                      browser → Docker's published port → this container
 *
 * Change it only if a proxy of yours has a PUBLIC address (a managed load
 * balancer, a proxy on another server): add that address to
 * `own_infrastructure`, or every visitor will look like that proxy.
 */
return [
    // Loopback and private address space: the container's own nginx, a
    // proxy on the same host, and Docker's bridge network. In development
    // every hop is in here, so visitors resolve to "unknown" and share one
    // rate-limit bucket — correct, and harmless locally.
    'own_infrastructure' => [
        '127.0.0.0/8',
        '::1/128',
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        'fc00::/7',
    ],

    // Cloudflare is declared separately because its CF-Connecting-IP header
    // is only believed when one of these addresses really is in the chain.
    // Listing them costs nothing on a site that does not use Cloudflare: a
    // range that never appears in a request is never consulted.
    //
    // Taken 2026-08-31 from <https://www.cloudflare.com/ips/>. Cloudflare
    // changes these occasionally. A stale list fails safe: an unrecognised
    // edge counts as a visitor, so people behind it share a bucket and are
    // throttled sooner — never let through.
    'cdn' => [
        'header' => 'HTTP_CF_CONNECTING_IP',
        'ranges' => [
            '173.245.48.0/20',
            '103.21.244.0/22',
            '103.22.200.0/22',
            '103.31.4.0/22',
            '141.101.64.0/18',
            '108.162.192.0/18',
            '190.93.240.0/20',
            '188.114.96.0/20',
            '197.234.240.0/22',
            '198.41.128.0/17',
            '162.158.0.0/15',
            '104.16.0.0/13',
            '104.24.0.0/14',
            '172.64.0.0/13',
            '131.0.72.0/22',
            '2400:cb00::/32',
            '2606:4700::/32',
            '2803:f800::/32',
            '2405:b500::/32',
            '2405:8100::/32',
            '2a06:98c0::/29',
            '2c0f:f248::/32',
        ],
    ],
];
