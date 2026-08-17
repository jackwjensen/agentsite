<?php
declare(strict_types=1);

/**
 * Content + config validator — the framework's "does this site hold together?"
 * gate. Runs identically locally (`php scripts/validate-content.php`) and in
 * CI (.github/workflows/check.yml). Exit 0 = clean, 1 = problems (printed one
 * per line, file-first, same style as check-image-budget.php).
 *
 * Checks, in escalating order:
 *   1. config/site.php sanity (required keys, languages, routes, nav)
 *   2. frontmatter: parses; required fields; date format + plausible range
 *   3. slugs: filenames produce valid, routable slugs
 *   4. categories: every post category exists in config
 *   5. translation completeness (when >1 language): same-filename
 *      counterparts, `untranslated: true` opt-out, orphan detection
 *   6. i18n parity: every t('key') used in templates exists in every
 *      language file; keys present in one language but missing in another
 *   7. images: frontmatter + body image references resolve to real files
 *      (byte budgets stay in check-image-budget.php — one gate per concern)
 */

define('ROOT', dirname(__DIR__));

require ROOT . '/lib/config.php';
require ROOT . '/lib/i18n.php';
require ROOT . '/lib/images.php';
require ROOT . '/lib/content.php';

$problems = [];
$fail = function (string $where, string $what) use (&$problems): void {
    $problems[] = "$where: $what";
};

// ── 1. Config sanity ─────────────────────────────────────────────────────
$config = null;
try {
    $config = config();
} catch (Throwable $e) {
    fwrite(STDERR, "config/site.php does not load: {$e->getMessage()}\n");
    exit(1);
}

foreach (['site.name', 'site.url', 'site.profile', 'site.default_lang', 'site.timezone',
          'identity.default_og_image', 'languages', 'routes', 'nav', 'categories',
          'seo.content_signal', 'seo.robots_disallow', 'gone', 'redirects', 'modules'] as $required_key) {
    try {
        cfg($required_key);
    } catch (RuntimeException $e) {
        $fail('config/site.php', "missing required key '$required_key'");
    }
}
if ($problems) {
    // Everything below reads these keys; report and stop rather than cascade.
    foreach ($problems as $p) fwrite(STDERR, "  - $p\n");
    exit(1);
}

$languages = cfg('languages');
$default   = default_lang();

if (!in_array(cfg('site.profile'), ['org', 'person'], true)) {
    $fail('config/site.php', "site.profile must be 'org' or 'person'");
}
if (!isset($languages[$default])) {
    $fail('config/site.php', "default_lang '$default' is not in languages");
}
foreach ($languages as $code => $meta) {
    if (!is_array($meta) || !array_key_exists('prefix', $meta) || !isset($meta['label'], $meta['locale'])) {
        $fail('config/site.php', "language '$code' needs 'label', 'prefix' and 'locale'");
    }
    if ((string) $code !== $default && (string) ($meta['prefix'] ?? '') === '') {
        $fail('config/site.php', "non-default language '$code' must have a non-empty prefix");
    }
}

foreach (routes_all() as $route_name => $entry) {
    if (!isset($entry['template'])) {
        $fail("route '$route_name'", "has no 'template'");
        continue;
    }
    if (!isset($entry['paths']) && !isset($entry['patterns'])) {
        $fail("route '$route_name'", "needs 'paths' or 'patterns'");
        continue;
    }
    $per_lang = $entry['paths'] ?? $entry['patterns'];
    // A language-neutral route (ROUTE_ANY_LANG) deliberately has one URL and
    // no per-language entries.
    if (!isset($per_lang[ROUTE_ANY_LANG]) && !isset($per_lang[$default])) {
        $fail("route '$route_name'", "has no path/pattern for the default language '$default'");
    }
    if (!is_file(ROOT . '/templates/' . $entry['template'] . '.php')) {
        $fail("route '$route_name'", "template '{$entry['template']}' has no templates/{$entry['template']}.php");
    }
}

// Load every language's i18n strings once, for nav + parity checks.
$strings = [];
foreach (array_keys($languages) as $code) {
    $file = ROOT . "/i18n/$code.php";
    if (!is_file($file)) {
        $fail("i18n/$code.php", 'missing — every configured language needs a strings file');
        $strings[$code] = [];
        continue;
    }
    $loaded = require $file;
    $strings[$code] = is_array($loaded) ? $loaded : [];
    if (!is_array($loaded)) {
        $fail("i18n/$code.php", 'must return an array');
    }
}

foreach ((array) cfg('nav') as $i => $item) {
    $key = (string) ($item['key'] ?? '');
    foreach ($strings as $code => $lang_strings) {
        if ($key !== '' && !array_key_exists($key, $lang_strings)) {
            $fail('config/site.php', "nav[$i] key '$key' missing from i18n/$code.php");
        }
    }
    $route = (string) ($item['route'] ?? '');
    if ($route === '' || !isset(routes_all()[$route])) {
        $fail('config/site.php', "nav[$i] references unknown route '$route'");
    }
}

foreach ((array) cfg('categories') as $code => $labels) {
    foreach (array_keys($languages) as $lang_code) {
        if (!is_array($labels) || !isset($labels[$lang_code])) {
            $fail('config/site.php', "category '$code' has no label for language '$lang_code'");
        }
    }
}

// ── 2–4. Content files ───────────────────────────────────────────────────
$today   = site_today();
$max_iso = (new DateTimeImmutable($today))->modify('+2 years')->format('Y-m-d');

$check_date = function (string $where, string $field, string $value) use ($fail, $max_iso): void {
    $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    $bad    = $parsed === false
        || (is_array($errors) && (($errors['warning_count'] ?? 0) + ($errors['error_count'] ?? 0)) > 0)
        || $parsed->format('Y-m-d') !== $value;
    if ($bad) {
        $fail($where, "$field '$value' is not a valid YYYY-MM-DD date");
        return;
    }
    if ($value < '2000-01-01' || $value > $max_iso) {
        $fail($where, "$field '$value' is outside the plausible range (2000-01-01 … $max_iso)");
    }
};

$image_exists = function (string $src): bool {
    return $src !== '' && img_fs_path($src) !== null;
};

$check_content_file = function (string $file, bool $is_post, string $lang)
    use ($fail, $check_date, $image_exists): void {

    $rel    = str_replace('\\', '/', substr($file, strlen(ROOT) + 1));
    $parsed = load_content($file);
    if ($parsed === null) {
        $fail($rel, 'could not be read');
        return;
    }
    $meta = $parsed['meta'];

    foreach (['title', 'description'] as $required_field) {
        if (trim((string) ($meta[$required_field] ?? '')) === '') {
            $fail($rel, "missing required frontmatter field '$required_field'");
        }
    }

    $basename = basename($file, '.md');
    if ($is_post) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}-(.+)$/', $basename, $m)) {
            $fail($rel, 'post filename must be YYYY-MM-DD-<slug>.md');
        } elseif (!valid_slug($m[1])) {
            $fail($rel, "slug '{$m[1]}' is not routable (lowercase a-z, 0-9 and hyphens only)");
        }
        $date = (string) ($meta['date'] ?? '');
        if ($date === '') {
            $fail($rel, "missing required frontmatter field 'date'");
        } else {
            $check_date($rel, 'date', $date);
        }
        $updated = (string) ($meta['updated'] ?? '');
        if ($updated !== '') {
            $check_date($rel, 'updated', $updated);
            if ($date !== '' && $updated < $date) {
                $fail($rel, "updated '$updated' is before date '$date'");
            }
        }
        $category = (string) ($meta['category'] ?? '');
        if ($category !== '' && !in_array($category, all_categories(), true)) {
            $fail($rel, "category '$category' is not in config/site.php categories");
        }
    } elseif (!valid_slug($basename)) {
        $fail($rel, "slug '$basename' is not routable (lowercase a-z, 0-9 and hyphens only)");
    }

    foreach (['featured_image', 'og_image', 'thumb_image'] as $img_field) {
        $src = (string) ($meta[$img_field] ?? '');
        if ($src !== '' && !$image_exists($src)) {
            $fail($rel, "$img_field '$src' does not exist under public/");
        }
    }
    if (preg_match_all('/!\[[^\]]*\]\(([^)\s]+)\)/', $parsed['body'], $m)) {
        foreach ($m[1] as $src) {
            if (str_starts_with($src, '/') && !$image_exists($src)) {
                $fail($rel, "body image '$src' does not exist under public/");
            }
        }
    }
};

$content_index = []; // [type][slug][lang] => meta
foreach (array_keys($languages) as $lang_code) {
    foreach (glob(ROOT . "/content/$lang_code/pages/*.md") as $file) {
        $check_content_file($file, false, $lang_code);
        $slug = basename($file, '.md');
        $parsed = load_content($file);
        $content_index['page'][$slug][$lang_code] = $parsed['meta'] ?? [];
    }
    foreach (glob(ROOT . "/content/$lang_code/posts/*.md") as $file) {
        $check_content_file($file, true, $lang_code);
        $slug = preg_replace('/^\d{4}-\d{2}-\d{2}-/', '', basename($file, '.md'));
        $parsed = load_content($file);
        $content_index['post'][$slug][$lang_code] = $parsed['meta'] ?? [];
    }
}

// ── 5. Translation completeness ──────────────────────────────────────────
if (count($languages) > 1) {
    foreach ($content_index as $type => $by_slug) {
        foreach ($by_slug as $slug => $by_lang) {
            $opted_out = false;
            foreach ($by_lang as $meta) {
                if (($meta['untranslated'] ?? '') === 'true') {
                    $opted_out = true;
                }
            }
            if ($opted_out) {
                continue;
            }
            $missing = array_diff(array_keys($languages), array_keys($by_lang));
            if ($missing !== []) {
                $have = implode(', ', array_keys($by_lang));
                $need = implode(', ', $missing);
                $fail("$type '$slug'", "exists in [$have] but not in [$need] — add the translation, or set 'untranslated: true' in its frontmatter");
            }
        }
    }
}

// ── 6. i18n key parity ───────────────────────────────────────────────────
//
// Two separate questions, deliberately not conflated:
//
//   a) Does every key the SITE's templates use exist in every language the
//      site is configured for? The site's own files plus any installed
//      module's files answer this.
//   b) Does every key a MODULE's templates use exist in that module's own
//      language files? A module carries its strings with it, so it is
//      checked against itself — otherwise every uninstalled module would
//      report its whole vocabulary as missing.

/** Extract t('key') and t_opt('key') literals from a set of template files. */
$collect_keys = static function (array $template_files): array {
    $keys = [];
    foreach ($template_files as $template) {
        $source = file_get_contents($template);
        if ($source === false) continue;
        if (preg_match_all("/\\bt(?:_opt)?\\(\\s*'([^']+)'\\s*\\)/", $source, $m)) {
            foreach ($m[1] as $key) {
                $keys[$key][] = str_replace('\\', '/', substr($template, strlen(ROOT) + 1));
            }
        }
    }
    return $keys;
};

// (a) The site's own templates, against site + installed-module strings.
$site_strings = $strings;
foreach (array_keys($languages) as $code) {
    foreach (glob(ROOT . "/i18n/modules/*.$code.php") ?: [] as $module_file) {
        $module_strings = require $module_file;
        if (is_array($module_strings)) {
            $site_strings[$code] = $module_strings + $site_strings[$code];
        }
    }
}
$site_keys = $collect_keys(array_merge(
    glob(ROOT . '/templates/*.php') ?: [],
    glob(ROOT . '/templates/*/*.php') ?: [],
    glob(ROOT . '/templates/*/*/*.php') ?: []
));
foreach ($site_keys as $key => $files) {
    foreach ($site_strings as $code => $lang_strings) {
        if (!array_key_exists($key, $lang_strings)) {
            // t_opt() keys are optional by design — a core template offering
            // a slot an installed module may fill.
            $optional = str_contains((string) file_get_contents(ROOT . '/' . $files[0]), "t_opt('$key')");
            if (!$optional) {
                $fail($files[0], "t('$key') has no entry in i18n for language '$code'");
            }
        }
    }
}

// (b) Each module's templates, against that module's own language files.
foreach (glob(ROOT . '/modules/*', GLOB_ONLYDIR) ?: [] as $module_dir) {
    $module_name = basename($module_dir);
    $module_keys = $collect_keys(array_merge(
        glob($module_dir . '/root/templates/*.php') ?: [],
        glob($module_dir . '/root/templates/*/*.php') ?: [],
        glob($module_dir . '/root/templates/*/*/*.php') ?: []
    ));
    if ($module_keys === []) {
        continue;
    }

    $module_langs = [];
    foreach (glob($module_dir . "/root/i18n/modules/$module_name.*.php") ?: [] as $lang_file) {
        if (preg_match('/\.([a-z]{2}(?:-[A-Za-z]+)?)\.php$/', $lang_file, $m)) {
            $loaded = require $lang_file;
            $module_langs[$m[1]] = is_array($loaded) ? $loaded : [];
        }
    }
    if ($module_langs === []) {
        $fail("modules/$module_name", 'uses translated strings but ships no i18n/modules/ files');
        continue;
    }

    // A module may use strings from a module it declares a dependency on —
    // the newsletter's admin page reuses the admin module's error strings,
    // which is correct precisely BECAUSE it requires admin. Undeclared
    // borrowing still fails, which is the point.
    $manifest_file = $module_dir . '/module.json';
    $manifest      = is_file($manifest_file) ? json_decode((string) file_get_contents($manifest_file), true) : null;
    foreach ((array) (is_array($manifest) ? ($manifest['requires'] ?? []) : []) as $dependency) {
        foreach (array_keys($module_langs) as $code) {
            $dep_file = ROOT . "/modules/$dependency/root/i18n/modules/$dependency.$code.php";
            if (is_file($dep_file)) {
                $dep_strings = require $dep_file;
                if (is_array($dep_strings)) {
                    $module_langs[$code] += $dep_strings;
                }
            }
        }
    }

    foreach ($module_keys as $key => $files) {
        foreach ($module_langs as $code => $lang_strings) {
            if (array_key_exists($key, $lang_strings)) {
                continue;
            }
            // A module may legitimately use a CORE key (nav_home,
            // notfound_home) that the site already defines.
            if (isset($strings[$code]) && array_key_exists($key, $strings[$code])) {
                continue;
            }
            $fail($files[0], "t('$key') is not defined in modules/$module_name for language '$code'");
        }
    }
}
// Keys present in one core language file but not another (drift, both ways).
$all_keys = [];
foreach ($strings as $code => $lang_strings) {
    foreach (array_keys($lang_strings) as $key) {
        $all_keys[$key][] = $code;
    }
}
foreach ($all_keys as $key => $present_in) {
    $missing = array_diff(array_keys($languages), $present_in);
    if ($missing !== []) {
        $fail('i18n', "key '$key' exists in [" . implode(', ', $present_in) . "] but not in [" . implode(', ', $missing) . ']');
    }
}

// ── Report ───────────────────────────────────────────────────────────────
if ($problems) {
    fwrite(STDERR, 'Content validation FAILED (' . count($problems) . " problem(s)):\n");
    foreach ($problems as $p) {
        fwrite(STDERR, "  - $p\n");
    }
    exit(1);
}

$pages = count($content_index['page'] ?? []);
$posts = count($content_index['post'] ?? []);
echo "Content validation OK — $pages page(s), $posts post(s), " . count($languages)
   . ' language(s), ' . count($site_keys) . " i18n keys checked.\n";
exit(0);
