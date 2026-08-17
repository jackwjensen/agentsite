<?php
declare(strict_types=1);

/**
 * Configuration access. config/site.php is the single identity file; this is
 * the only code that reads it. Everything else asks cfg().
 *
 * Modules never edit config/site.php — they drop route files into
 * config/routes.d/ which routes_all() merges, so installing or removing a
 * module cannot conflict with the site owner's own configuration.
 */

/** The full configuration array, loaded once per request. */
function config(): array
{
    static $config = null;
    if ($config === null) {
        $config = require ROOT . '/config/site.php';
    }
    return $config;
}

/**
 * A configuration value by dotted key, e.g. cfg('site.name').
 * Throws when the key does not exist — a missing config key is a bug in the
 * config or the caller, and a silent default would hide it.
 */
function cfg(string $dotted_key)
{
    $value = config();
    foreach (explode('.', $dotted_key) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            throw new RuntimeException("config key not found: $dotted_key");
        }
        $value = $value[$segment];
    }
    return $value;
}

/** Language codes configured for this site, default language first. */
function site_langs(): array
{
    $langs   = array_keys(cfg('languages'));
    $default = default_lang();
    usort($langs, fn(string $a, string $b) => ($a === $default ? -1 : ($b === $default ? 1 : 0)));
    return $langs;
}

function default_lang(): string
{
    return (string) cfg('site.default_lang');
}

/**
 * The complete route table: config/site.php routes plus every module route
 * file in config/routes.d/*.php (each returns an array in the same shape).
 * The 'page' catch-all is forced last so a module route can never be
 * shadowed by it.
 */
function routes_all(): array
{
    static $routes = null;
    if ($routes !== null) {
        return $routes;
    }
    $routes = cfg('routes');
    foreach (glob(ROOT . '/config/routes.d/*.php') as $file) {
        $module_routes = require $file;
        if (is_array($module_routes)) {
            $routes = array_merge($routes, $module_routes);
        }
    }
    // Keep the page catch-all last regardless of merge order.
    if (isset($routes['page'])) {
        $page = $routes['page'];
        unset($routes['page']);
        $routes['page'] = $page;
    }
    return $routes;
}

/**
 * Build the URL path for a route in a language: language prefix + the
 * route's path or pattern, with {placeholders} substituted from $params.
 *
 * This replaces every hard-coded URL in templates — the one place that
 * knows how a route and a language combine into a path.
 */
function lang_path(string $lang, string $route, array $params = []): string
{
    $routes = routes_all();
    if (!isset($routes[$route])) {
        throw new RuntimeException("unknown route: $route");
    }
    $languages = cfg('languages');
    if (!isset($languages[$lang])) {
        throw new RuntimeException("unknown language: $lang");
    }

    $entry     = $routes[$route];
    $per_lang  = $entry['paths'] ?? $entry['patterns'];
    $is_neutral = isset($per_lang[ROUTE_ANY_LANG]);

    if (isset($entry['paths'])) {
        $path = (string) ($per_lang[ROUTE_ANY_LANG] ?? $per_lang[$lang] ?? $per_lang[default_lang()]);
    } else {
        $path = (string) ($per_lang[ROUTE_ANY_LANG] ?? $per_lang[$lang] ?? $per_lang[default_lang()]);
        foreach ($params as $name => $value) {
            $path = str_replace('{' . $name . '}', (string) $value, $path);
        }
    }

    // A language-neutral route has exactly one URL, unprefixed — see the
    // ROUTE_ANY_LANG note in lib/router.php.
    $prefix = $is_neutral ? '' : (string) $languages[$lang]['prefix'];
    $full   = $prefix . $path;
    return $full === '' ? '/' : $full;
}

/**
 * Per-language localized value from a ['en' => …, 'da' => …] config map,
 * falling back to the default language's value.
 */
function cfg_lang(string $dotted_key, string $lang): string
{
    $map = cfg($dotted_key);
    if (!is_array($map)) {
        return (string) $map;
    }
    return (string) ($map[$lang] ?? $map[default_lang()] ?? '');
}
