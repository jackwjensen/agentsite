<?php
declare(strict_types=1);

/**
 * Route a normalised URI (leading + trailing slash) to a template.
 *
 * The dispatch table is built from config: every route × every configured
 * language produces one exact path or one pattern (see lang_path() for how
 * prefix and path combine). The router therefore also decides the request's
 * LANGUAGE — it is whichever language's path matched, so no caller ever
 * string-matches a URL prefix.
 *
 * Returns ['template', 'params', 'lang', 'route'] or null for a 404.
 * Exact paths are matched before patterns, and the 'page' catch-all pattern
 * is always tried last (routes_all() enforces the ordering).
 */
function route(string $uri): ?array
{
    [$exact, $patterns] = route_tables();

    if (isset($exact[$uri])) {
        return $exact[$uri];
    }

    foreach ($patterns as [$regex, $template, $route_name, $lang, $param_names]) {
        if (preg_match($regex, $uri, $m)) {
            $params = [];
            foreach ($param_names as $i => $name) {
                $params[$name] = $m[$i + 1];
            }
            return ['template' => $template, 'params' => $params, 'lang' => $lang, 'route' => $route_name];
        }
    }

    return null;
}

/**
 * Build (once per request) the exact-match map and the ordered pattern list
 * from the merged route table.
 */
function route_tables(): array
{
    static $tables = null;
    if ($tables !== null) {
        return $tables;
    }

    $exact    = [];
    $patterns = [];

    foreach (routes_all() as $route_name => $entry) {
        $template  = (string) $entry['template'];
        $per_lang  = $entry['paths'] ?? $entry['patterns'] ?? [];
        // A language-neutral route registers once, in the default language,
        // with no prefix.
        $langs     = isset($per_lang[ROUTE_ANY_LANG]) ? [default_lang()] : site_langs();

        foreach ($langs as $lang) {
            if (isset($entry['paths'])) {
                if (!isset($entry['paths'][$lang]) && !isset($entry['paths'][ROUTE_ANY_LANG])) {
                    continue;
                }
                $path = lang_path($lang, $route_name);
                // First definition wins: if two routes claim one path, the
                // earlier route keeps it (e.g. every language maps '/' at most
                // once — the default language bare, the others prefixed).
                if (!isset($exact[$path])) {
                    $exact[$path] = ['template' => $template, 'params' => [], 'lang' => $lang, 'route' => $route_name];
                }
                continue;
            }

            $neutral_pattern = $entry['patterns'][ROUTE_ANY_LANG] ?? null;
            if ($neutral_pattern === null && !isset($entry['patterns'][$lang])) {
                continue;
            }
            $full = $neutral_pattern !== null
                ? (string) $neutral_pattern
                : (string) cfg('languages')[$lang]['prefix'] . (string) $entry['patterns'][$lang];

            // Swap each {placeholder} for an alphanumeric marker (preg_quote
            // leaves alphanumerics untouched; route paths are lowercase, so
            // the uppercase marker cannot occur naturally), regex-escape the
            // rest, then turn the markers into capture groups. The capture
            // class matches valid_slug(), so the router never captures
            // anything the content layer would reject.
            $marker      = 'AGENTSITE0CAPTURE0MARKER';
            $param_names = [];
            $marked = (string) preg_replace_callback(
                '/\{([a-z_]+)\}/',
                function (array $m) use (&$param_names, $marker): string {
                    $param_names[] = $m[1];
                    return $marker;
                },
                $full
            );
            $regex = str_replace($marker, '([a-z0-9-]+)', preg_quote($marked, '#'));

            $patterns[] = ['#^' . $regex . '$#', $template, $route_name, $lang, $param_names];
        }
    }

    return $tables = [$exact, $patterns];
}
