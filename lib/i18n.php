<?php
declare(strict_types=1);

$_i18n = [];

/**
 * Load UI strings for a language: i18n/<lang>.php plus every installed
 * module's i18n/modules/<module>.<lang>.php (merged on top, so a module can
 * add keys but the site's own file wins on collision — collisions are a
 * naming mistake the validator reports).
 */
function load_i18n(string $lang): void
{
    global $_i18n;
    $file  = ROOT . "/i18n/$lang.php";
    $_i18n = file_exists($file) ? require $file : [];

    foreach (glob(ROOT . "/i18n/modules/*.$lang.php") as $module_file) {
        $module_strings = require $module_file;
        if (is_array($module_strings)) {
            $_i18n = $module_strings + $_i18n;
        }
    }
}

/**
 * A translated string by key. Falls back to the key itself so a missing
 * translation is visible on the page rather than silently blank.
 */
function t(string $key): string
{
    global $_i18n;
    return (string) ($_i18n[$key] ?? $key);
}

/**
 * A translated string that may legitimately not exist, returning '' when it
 * does not. This is how a core template offers an optional slot a module can
 * fill: the core 404 page renders an extra hint only when some installed
 * module has defined it. No hooks, no registry — the string's presence IS
 * the signal.
 */
function t_opt(string $key): string
{
    global $_i18n;
    return isset($_i18n[$key]) ? (string) $_i18n[$key] : '';
}
