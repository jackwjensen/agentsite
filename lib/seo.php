<?php
declare(strict_types=1);

/**
 * SEO core — URL helpers, language alternates, and render_seo_head(), which
 * emits the whole <head> SEO block (title, description, canonical, hreflang,
 * Open Graph, Twitter Card, JSON-LD). Called once from templates/layout.php
 * with the single $seo array the template prepared.
 *
 * This file is the shared base of the seo-*.php set:
 *
 *   lib/seo-jsonld.php   schema.org node builders (Organization/Person, …)
 *   lib/seo-sitemap.php  /sitemap.xml, /sitemap.xsl, /robots.txt
 *   lib/seo-agents.php   /llms.txt and the Markdown representations for agents
 *
 * All identity facts come from config/site.php — nothing brand-shaped lives
 * in these files. Everything is computed at request time from $_SERVER +
 * config + frontmatter, so switching domains needs no code changes.
 */

/** Absolute site origin (scheme + host, no trailing slash). */
function site_url(): string
{
    $proto = request_is_https() ? 'https' : 'http';
    $host  = $_SERVER['HTTP_HOST'] ?? '';
    if (!is_string($host) || $host === '') {
        // No Host header (CLI) → fall back to the configured canonical URL.
        return rtrim((string) cfg('site.url'), '/');
    }
    return $proto . '://' . $host;
}

/** Turn a path (or already-absolute URL) into a full URL. */
function abs_url(string $path): string
{
    if ($path === '')                       return site_url() . '/';
    if (preg_match('#^https?://#i', $path)) return $path;
    return site_url() . '/' . ltrim($path, '/');
}

/** Current request path, normalised with leading + trailing slash. */
function current_path(): string
{
    $p = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $p = '/' . trim($p, '/');
    if ($p !== '/') $p .= '/';
    return $p;
}

/**
 * Language alternates for a route match: lang => site-relative path, for
 * every configured language where the page actually exists. For content
 * templates (page/post) "exists" is a filesystem question — a missing or
 * not-yet-published translation is never advertised in hreflang or the
 * sitemap. Single source of truth shared by render_seo_head(), the sitemap
 * and the header language switcher.
 */
function route_alternates(array $match): array
{
    $route    = (string) ($match['route'] ?? '');
    $template = (string) ($match['template'] ?? '');
    $params   = (array)  ($match['params'] ?? []);
    if ($route === '') {
        return [];
    }

    $routes = routes_all();
    $entry  = $routes[$route] ?? null;
    if ($entry === null) {
        return [];
    }

    $alternates = [];
    foreach (site_langs() as $lang) {
        $defined = isset($entry['paths'])
            ? isset($entry['paths'][$lang])
            : isset($entry['patterns'][$lang]);
        if (!$defined) {
            continue;
        }
        $slug = (string) ($params['slug'] ?? '');
        if ($slug !== '' && in_array($template, ['page', 'post'], true)
            && !translation_exists($template, $slug, $lang)) {
            continue;
        }
        $alternates[$lang] = lang_path($lang, $route, $params);
    }
    return $alternates;
}

/**
 * Derive a clean description from a content array. Prefers explicit
 * `description:` frontmatter, then `excerpt:`, then the first paragraph of
 * rendered HTML. Collapses whitespace and hard-caps at $max chars.
 */
function auto_description(?array $content, int $max = 160): string
{
    if (!$content) return '';
    $desc = (string) ($content['meta']['description'] ?? '');
    if ($desc === '') $desc = (string) ($content['meta']['excerpt'] ?? '');
    if ($desc === '' && !empty($content['html'])) {
        if (preg_match('#<p[^>]*>(.*?)</p>#is', $content['html'], $m)) {
            $desc = html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }
    $desc = trim((string) preg_replace('/\s+/', ' ', $desc));
    if (mb_strlen($desc) > $max) {
        $desc = rtrim(mb_substr($desc, 0, $max - 1)) . '…';
    }
    return $desc;
}

/**
 * Render the full <head> SEO block. Templates prepare ONE $seo array; the
 * layout passes it here. Recognised keys:
 *
 *   lang         current language code (required)
 *   title        page title (default: site name)
 *   description  meta description
 *   og_image     site-relative or absolute image URL
 *   og_image_alt alt text for the OG image (default: the title)
 *   og_type      'website' (default) or 'article'
 *   noindex      bool — emit noindex,follow
 *   alternates   lang => path map from route_alternates() (required for hreflang)
 *   article      ['title','date','updated','description','category','tags'] for posts
 *   breadcrumbs  [['name','url'], …]
 */
function render_seo_head(array $seo): void
{
    $lang         = (string) ($seo['lang']         ?? default_lang());
    $site_name    = (string) cfg('site.name');
    $title        = (string) ($seo['title']        ?? $site_name);
    $desc         = (string) ($seo['description']  ?? '');
    $default_og   = (string) cfg('identity.default_og_image');
    $og_image     = (string) ($seo['og_image']     ?? $default_og);
    $og_image_alt = (string) ($seo['og_image_alt'] ?? '');
    $og_type      = (string) ($seo['og_type']      ?? 'website');
    $noindex      = (bool)   ($seo['noindex']      ?? false);
    $alternates   = (array)  ($seo['alternates']   ?? []);
    $article      =           $seo['article']      ?? null;
    $breadcrumbs  =           $seo['breadcrumbs']  ?? null;

    $languages    = cfg('languages');
    $canonical    = abs_url(current_path());
    $og_image_abs = abs_url($og_image !== '' ? $og_image : $default_og);
    $og_image_dim = img_dimensions($og_image !== '' ? $og_image : $default_og);
    if ($og_image_alt === '') $og_image_alt = $title !== '' ? $title : $site_name;
    $og_locale    = (string) ($languages[$lang]['locale'] ?? 'en_US');
    $owner_name   = (string) (cfg('identity.owner_name') ?? '');

    $h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

    echo "  <title>" . $h($title) . "</title>\n";
    echo '  <meta name="description" content="' . $h($desc) . "\">\n";
    if ($owner_name !== '') {
        echo '  <meta name="author" content="' . $h($owner_name) . "\">\n";
    }

    if ($noindex) {
        echo "  <meta name=\"robots\" content=\"noindex,follow\">\n";
    } else {
        echo "  <meta name=\"robots\" content=\"index,follow,max-image-preview:large,max-snippet:-1\">\n";
    }

    echo '  <link rel="canonical" href="' . $h($canonical) . "\">\n";
    // hreflang for every language the page exists in; x-default points at
    // the default language's version. Only emitted when the page exists in
    // more than one language — a single-language page needs no alternates.
    if (count($alternates) > 1) {
        foreach ($alternates as $alt_lang => $alt_path) {
            echo '  <link rel="alternate" hreflang="' . $h((string) $alt_lang) . '" href="' . $h(abs_url($alt_path)) . "\">\n";
        }
        $x_default = $alternates[default_lang()] ?? reset($alternates);
        echo '  <link rel="alternate" hreflang="x-default" href="' . $h(abs_url((string) $x_default)) . "\">\n";
    }

    // Open Graph
    echo '  <meta property="og:type" content="'        . $h($og_type)      . "\">\n";
    echo '  <meta property="og:site_name" content="'   . $h($site_name)    . "\">\n";
    echo '  <meta property="og:title" content="'       . $h($title)        . "\">\n";
    echo '  <meta property="og:description" content="' . $h($desc)         . "\">\n";
    echo '  <meta property="og:url" content="'         . $h($canonical)    . "\">\n";
    echo '  <meta property="og:image" content="'       . $h($og_image_abs) . "\">\n";
    if ($og_image_dim) {
        echo '  <meta property="og:image:width" content="'  . $og_image_dim['width']  . "\">\n";
        echo '  <meta property="og:image:height" content="' . $og_image_dim['height'] . "\">\n";
    }
    echo '  <meta property="og:image:alt" content="' . $h($og_image_alt) . "\">\n";
    echo '  <meta property="og:locale" content="'    . $h($og_locale)    . "\">\n";
    foreach ($alternates as $alt_lang => $alt_path) {
        if ($alt_lang === $lang) continue;
        $alt_locale = (string) ($languages[$alt_lang]['locale'] ?? '');
        if ($alt_locale !== '') {
            echo '  <meta property="og:locale:alternate" content="' . $h($alt_locale) . "\">\n";
        }
    }

    if ($og_type === 'article' && is_array($article)) {
        if (!empty($article['date'])) {
            $modified = (string) (($article['updated'] ?? '') !== '' ? $article['updated'] : $article['date']);
            echo '  <meta property="article:published_time" content="' . $h((string) $article['date']) . "\">\n";
            echo '  <meta property="article:modified_time" content="'  . $h($modified) . "\">\n";
        }
        if ($owner_name !== '') {
            echo '  <meta property="article:author" content="' . $h($owner_name) . "\">\n";
        }
        if (!empty($article['category'])) {
            echo '  <meta property="article:section" content="' . $h(category_label((string) $article['category'], $lang)) . "\">\n";
        }
        if (!empty($article['tags']) && is_array($article['tags'])) {
            foreach ($article['tags'] as $tag) {
                if (!is_string($tag) || $tag === '') continue;
                echo '  <meta property="article:tag" content="' . $h($tag) . "\">\n";
            }
        }
    }

    // Twitter Card
    echo "  <meta name=\"twitter:card\" content=\"summary_large_image\">\n";
    echo '  <meta name="twitter:title" content="'       . $h($title)        . "\">\n";
    echo '  <meta name="twitter:description" content="' . $h($desc)         . "\">\n";
    echo '  <meta name="twitter:image" content="'       . $h($og_image_abs) . "\">\n";
    echo '  <meta name="twitter:image:alt" content="'   . $h($og_image_alt) . "\">\n";

    // JSON-LD graph — identity node(s) + website on every page, plus the
    // page-specific nodes.
    $jsonld = identity_jsonld_nodes($lang);
    $jsonld[] = website_jsonld($lang);
    if (is_array($article)) {
        $jsonld[] = article_jsonld($article, $canonical, $og_image_abs, $lang);
    }
    if (is_array($breadcrumbs) && $breadcrumbs) {
        $jsonld[] = breadcrumb_jsonld($breadcrumbs);
    }

    foreach ($jsonld as $node) {
        $json = json_encode($node, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
        if ($json !== false) {
            echo "  <script type=\"application/ld+json\">" . $json . "</script>\n";
        }
    }
}
