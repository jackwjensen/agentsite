<?php
declare(strict_types=1);

require_once ROOT . '/lib/Parsedown.php';

/**
 * Content layer: markdown files with frontmatter, per-language directories.
 *
 *   content/<lang>/pages/<slug>.md
 *   content/<lang>/posts/YYYY-MM-DD-<slug>.md
 *
 * A page or post and its translations share ONE slug — the same filename in
 * every language directory. That single decision is what makes n languages
 * tractable: translation pairing is a filesystem fact (does the counterpart
 * file exist?), never a hand-maintained map, and every URL differs between
 * languages only by the language prefix and the route's per-language path.
 */

/**
 * Parse a markdown file with YAML-ish frontmatter.
 * Returns ['meta' => [...], 'body' => '...', 'html' => '...'] or null.
 *
 * The frontmatter dialect is intentionally a narrow subset of YAML — just
 * the shapes the framework uses: top-level scalars, bare-scalar lists and
 * one level of list-of-dicts. No multi-line strings, no nesting, no anchors.
 */
function load_content(string $path): ?array
{
    if (!file_exists($path)) {
        return null;
    }

    $raw  = file_get_contents($path);
    $meta = [];
    $body = $raw;

    if (str_starts_with($raw, '---')) {
        $end = strpos($raw, "\n---", 3);
        if ($end !== false) {
            $front = substr($raw, 4, $end - 4); // skip opening "---\n"
            $body  = ltrim(substr($raw, $end + 4));
            $meta  = parse_frontmatter($front);
        }
    }

    return [
        'meta' => $meta,
        'body' => $body,
        'html' => render_markdown($body),
    ];
}

/** Minimal YAML-ish frontmatter parser — see load_content() for the dialect. */
function parse_frontmatter(string $front): array
{
    $meta        = [];
    $currentKey  = null;   // top-level key whose list we're building
    $list        = [];     // accumulated list items for $currentKey
    $currentItem = null;   // dict item currently being populated

    $flush = function () use (&$meta, &$currentKey, &$list, &$currentItem) {
        if ($currentKey !== null) {
            if ($currentItem !== null) {
                $list[] = $currentItem;
                $currentItem = null;
            }
            $meta[$currentKey] = $list;
            $list        = [];
            $currentKey  = null;
        }
    };

    foreach (explode("\n", $front) as $rawLine) {
        $line = rtrim($rawLine, "\r");
        if (trim($line) === '') {
            continue;
        }

        // Top-level key at column 0
        if (preg_match('/^([\w-]+):\s*(.*)$/', $line, $m)) {
            $flush();
            if ($m[2] === '') {
                $currentKey = $m[1];   // bare key → list block follows
            } else {
                $meta[$m[1]] = trim($m[2], '"\'');
            }
            continue;
        }

        if ($currentKey === null) {
            continue;
        }

        // Indented bare-scalar list item: "  - value". Skipped when the value
        // contains a colon so "  - key: value" (dict item) still wins.
        if (preg_match('/^\s+-\s+(.+)$/', $line, $m) && strpos($m[1], ':') === false) {
            if ($currentItem !== null) {
                $list[] = $currentItem;
                $currentItem = null;
            }
            $list[] = trim($m[1], "\"' ");
            continue;
        }

        // Indented list-item start: "  - key: value"
        if (preg_match('/^\s+-\s+([\w-]+):\s*(.*)$/', $line, $m)) {
            if ($currentItem !== null) {
                $list[] = $currentItem;
            }
            $currentItem = [$m[1] => trim($m[2], '"\'')];
            continue;
        }

        // Indented continuation key of the current list item: "    key: value"
        if (preg_match('/^\s+([\w-]+):\s*(.*)$/', $line, $m) && $currentItem !== null) {
            $currentItem[$m[1]] = trim($m[2], '"\'');
            continue;
        }
    }

    $flush();

    return $meta;
}

/**
 * Slug-shape gate. Slugs reach us from the URL via router captures and are
 * then concatenated into filesystem paths — a strict whitelist closes the
 * path-traversal door entirely: anything containing `..`, `/`, `\`,
 * percent-encodings or uppercase is rejected before touching the filesystem.
 */
function valid_slug(string $slug): bool
{
    return (bool) preg_match('/^[a-z0-9-]+$/', $slug);
}

/** Load a page: content/<lang>/pages/<slug>.md */
function load_page(string $slug, string $lang): ?array
{
    if (!valid_slug($slug)) return null;
    $parsed = load_content(ROOT . "/content/$lang/pages/$slug.md");
    if ($parsed !== null) {
        $parsed['meta']['slug'] = $slug;
    }
    return $parsed;
}

/**
 * Load a single published post by slug: content/<lang>/posts/*-<slug>.md.
 * Future-dated posts return null — same rule in dev and prod, no override.
 */
function load_post(string $slug, string $lang): ?array
{
    if (!valid_slug($slug)) return null;
    $matches = glob(ROOT . "/content/$lang/posts/*-$slug.md");
    if (empty($matches)) {
        return null;
    }
    $parsed = load_content($matches[0]);
    if (!$parsed || !post_is_published($parsed['meta'])) {
        return null;
    }
    $parsed['meta']['slug'] = $slug;
    return $parsed;
}

/**
 * All published posts for a language, newest first by effective date (an
 * `updated:` override wins over `date:`, so a freshly-revised older post
 * resurfaces at the top). Optionally filtered by category code.
 */
function load_posts(string $lang, ?string $category = null): array
{
    $posts = [];

    foreach (glob(ROOT . "/content/$lang/posts/*.md") as $file) {
        $parsed = load_content($file);
        if (!$parsed) {
            continue;
        }
        if ($category !== null && ($parsed['meta']['category'] ?? '') !== $category) {
            continue;
        }
        // Slug derives from the filename: YYYY-MM-DD-<slug>.md
        $basename = basename($file, '.md');
        $parsed['meta']['slug'] = preg_replace('/^\d{4}-\d{2}-\d{2}-/', '', $basename);
        $posts[] = $parsed;
    }

    $posts = array_values(array_filter($posts, fn(array $p) => post_is_published($p['meta'])));

    usort($posts, function (array $a, array $b): int {
        $byDate = strcmp(effective_post_date($b['meta']), effective_post_date($a['meta']));
        if ($byDate !== 0) {
            return $byDate;
        }
        return strcmp((string) ($a['meta']['slug'] ?? ''), (string) ($b['meta']['slug'] ?? ''));
    });

    return $posts;
}

/**
 * A post's effective date for ordering and freshness: the `updated:`
 * frontmatter override when present, otherwise the publish `date:`.
 * Single source of truth shared by load_posts() ordering, the sitemap
 * <lastmod>, JSON-LD dateModified and og:article:modified_time — so an
 * edit updates all of them or none.
 */
function effective_post_date(array $meta): string
{
    $updated = trim((string) ($meta['updated'] ?? ''));
    return $updated !== '' ? $updated : (string) ($meta['date'] ?? '');
}

/**
 * Whether a post carries a meaningful `updated:` override — revised since
 * publication. Lets templates show a "Published … / Updated …" pair only
 * when it adds information.
 */
function post_is_updated(array $meta): bool
{
    $updated = (string) ($meta['updated'] ?? '');
    return $updated !== '' && $updated !== (string) ($meta['date'] ?? '');
}

/**
 * Today's date (Y-m-d) in the site's configured timezone. Static per request
 * so every publish/new decision within one render agrees.
 */
function site_today(): string
{
    static $today = null;
    if ($today === null) {
        $today = (new DateTimeImmutable('now', new DateTimeZone((string) cfg('site.timezone'))))
            ->format('Y-m-d');
    }
    return $today;
}

/**
 * A post is live once its frontmatter date has arrived. Future-dated posts
 * stay hidden everywhere — listings, single view, sitemap, feeds, llms.txt.
 */
function post_is_published(array $meta): bool
{
    $date = (string) ($meta['date'] ?? '');
    return $date !== '' && $date <= site_today();
}

/** Published within the rolling last 7 days (today-6 .. today). */
function post_is_new(array $meta): bool
{
    if (!post_is_published($meta)) {
        return false;
    }
    $weekAgo = (new DateTimeImmutable(site_today()))->modify('-6 days')->format('Y-m-d');
    return (string) ($meta['date'] ?? '') >= $weekAgo;
}

/** Public URL path for a post slug in a language. */
function post_url(string $slug, string $lang): string
{
    return lang_path($lang, 'post', ['slug' => $slug]);
}

/** Grid/card image: explicit thumb, else the hero. */
function post_thumb(array $meta): string
{
    return (string) ($meta['thumb_image'] ?? $meta['featured_image'] ?? '');
}

/** Social/OG image: explicit og_image, else the hero. */
function post_og_image(array $meta): string
{
    return (string) ($meta['og_image'] ?? $meta['featured_image'] ?? '');
}

/**
 * Does a translation of this content exist (and, for posts, is it published)?
 * Because translations share a filename, this is a filesystem question. The
 * hreflang alternates and the sitemap must not advertise a language whose
 * file is missing — or, worse, a scheduled sibling that is not yet live.
 */
function translation_exists(string $template, string $slug, string $lang): bool
{
    if (!valid_slug($slug)) return false;
    if ($template === 'post') {
        return load_post($slug, $lang) !== null;
    }
    return is_file(ROOT . "/content/$lang/pages/$slug.md");
}

/**
 * Map a route match to the underlying .md file path without rendering it.
 * Returns null for templates with no markdown backing (home, archive, 404) —
 * and for unpublished posts, so the .md URL and content negotiation obey the
 * same publish gate as the HTML.
 */
function resolve_content_path(string $template, array $params, string $lang): ?string
{
    $slug = (string) ($params['slug'] ?? '');
    if ($slug === '' || !valid_slug($slug)) return null;

    if ($template === 'page') {
        $path = ROOT . "/content/$lang/pages/$slug.md";
        return file_exists($path) ? $path : null;
    }
    if ($template === 'post') {
        $matches = glob(ROOT . "/content/$lang/posts/*-$slug.md");
        if (empty($matches)) {
            return null;
        }
        $parsed = load_content($matches[0]);
        if (!$parsed || !post_is_published($parsed['meta'])) {
            return null;
        }
        return $matches[0];
    }
    return null;
}

/** Human-readable category label for a code, in a language, from config. */
function category_label(string $code, string $lang): string
{
    $categories = cfg('categories');
    if (isset($categories[$code]) && is_array($categories[$code])) {
        return (string) ($categories[$code][$lang] ?? $categories[$code][default_lang()] ?? $code);
    }
    return $code;
}

/** All configured category codes. */
function all_categories(): array
{
    return array_keys(cfg('categories'));
}

function render_markdown(string $md): string
{
    static $parsedown = null;
    if ($parsedown === null) {
        $parsedown = new Parsedown();
        $parsedown->setSafeMode(false); // content is trusted (it comes from git)
    }
    $html = $parsedown->text($md);

    // Route every <img> Parsedown produced through render_picture(): body
    // images get the same <picture>/WebP, width/height (CLS fix) and
    // lazy-loading as the rest of the site.
    $html = rewrite_body_images($html);

    // Open external links in a new tab.
    $html = preg_replace(
        '/<a\s+href="(https?:\/\/[^"]+)"/i',
        '<a href="$1" target="_blank"',
        $html
    );

    // Open local downloadable files in a new tab too — otherwise the
    // browser's built-in viewer replaces the page the reader was on.
    $html = preg_replace(
        '/<a\s+href="(\/[^"]+\.(?:pdf|docx?|xlsx?|pptx?|zip))"/i',
        '<a href="$1" target="_blank" rel="noopener"',
        $html
    );

    return $html;
}

/**
 * Replace every <img> Parsedown emitted with render_picture() output
 * (preserving src + alt). Runs inside render_markdown() — the single
 * choke-point all bodies pass through — so no body type is missed.
 * Body images are always lazy (below the fold by nature).
 */
function rewrite_body_images(string $html): string
{
    if (!function_exists('render_picture')) return $html; // lib-load-order safety
    return preg_replace_callback('#<img\b[^>]*>#i', static function (array $m): string {
        $tag = $m[0];
        $src = preg_match('/\bsrc="([^"]*)"/i', $tag, $s) ? $s[1] : '';
        if ($src === '') return $tag;
        $alt = preg_match('/\balt="([^"]*)"/i', $tag, $a) ? $a[1] : '';
        // Parsedown already entity-encoded the alt; decode once so
        // render_picture re-encodes exactly once rather than double-encoding.
        $alt = html_entity_decode($alt, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return render_picture($src, $alt);
    }, $html) ?? $html;
}

/**
 * An explicit hero excerpt from frontmatter, or ''. Deliberately NOT
 * auto-generated from the first paragraph: a truncated first paragraph
 * followed immediately by the full body just repeats itself.
 */
function extract_excerpt(array $content): string
{
    return !empty($content['meta']['excerpt'])
        ? (string) $content['meta']['excerpt']
        : '';
}
