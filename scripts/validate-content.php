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
    if (!isset($per_lang[$default])) {
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
$used_keys = [];
$template_files = array_merge(
    glob(ROOT . '/templates/*.php') ?: [],
    glob(ROOT . '/templates/*/*.php') ?: [],
    glob(ROOT . '/modules/*/root/templates/*.php') ?: [],
    glob(ROOT . '/modules/*/root/templates/*/*.php') ?: []
);
foreach ($template_files as $tpl) {
    $src = file_get_contents($tpl);
    if ($src === false) continue;
    if (preg_match_all("/\\bt\\(\\s*'([^']+)'\\s*\\)/", $src, $m)) {
        foreach ($m[1] as $key) {
            $used_keys[$key][] = str_replace('\\', '/', substr($tpl, strlen(ROOT) + 1));
        }
    }
}
// Module templates look their keys up in i18n/modules/<module>.<lang>.php —
// include those files in the per-language key sets.
$full_strings = $strings;
foreach (array_keys($languages) as $code) {
    foreach (glob(ROOT . "/i18n/modules/*.$code.php") as $module_file) {
        $module_strings = require $module_file;
        if (is_array($module_strings)) {
            $full_strings[$code] = $module_strings + $full_strings[$code];
        }
    }
}
foreach ($used_keys as $key => $files) {
    foreach ($full_strings as $code => $lang_strings) {
        if (!array_key_exists($key, $lang_strings)) {
            // Only report against the site's own templates for module keys the
            // module hasn't installed — an uninstalled module's templates are
            // not globbed above, so anything here is genuinely reachable.
            $fail($files[0], "t('$key') has no entry in i18n for language '$code'");
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
echo "Content validation OK — $pages page(s), $posts post(s), " . count($languages) . " language(s), " . count($used_keys) . " i18n keys checked.\n";
exit(0);
